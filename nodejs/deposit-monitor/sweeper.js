'use strict';

const { ethers } = require('ethers');
const log = require('./logger');
const { TRDC_CONTRACT, USDT_CONTRACT, ERC20_ABI, getDbPool, getConfig } = require('./config');
const { getWalletForIndex } = require('./address-manager');

const MAX_SWEEP_RETRIES = 3;

/**
 * Process pending sweep queue — move deposited tokens from user addresses to hot wallet.
 */
async function processSweeps(hotWallet, provider, seedPhrase) {
    const db = getDbPool();
    const now = Math.floor(Date.now() / 1000);

    const hotAddress = await getConfig('deposit_hot_wallet', '');
    if (!hotAddress) {
        log.warn('deposit_hot_wallet not configured — skipping sweeps');
        return 0;
    }

    // Get pending sweeps
    const [pending] = await db.execute(
        `SELECT sq.*, da.derivation_index
         FROM Wo_Sweep_Queue sq
         JOIN Wo_Deposit_Addresses da ON da.address = sq.address
         WHERE sq.status IN ('pending', 'gas_sent') AND sq.retry_count < ?
         ORDER BY sq.created_at ASC LIMIT 5`,
        [MAX_SWEEP_RETRIES]
    );

    if (pending.length === 0) return 0;

    log.info(`Processing ${pending.length} sweep(s)`);
    let swept = 0;

    for (const sweep of pending) {
        try {
            if (sweep.token === 'BNB') {
                await sweepBnb(db, hotWallet, provider, seedPhrase, sweep, hotAddress, now);
            } else {
                await sweepToken(db, hotWallet, provider, seedPhrase, sweep, hotAddress, now);
            }
            swept++;
        } catch (e) {
            const retryCount = sweep.retry_count + 1;
            log.error(`Sweep #${sweep.id} error: ${e.message}`);
            await db.execute(
                `UPDATE Wo_Sweep_Queue SET retry_count = ?, failure_reason = ?, status = ?, updated_at = ? WHERE id = ?`,
                [retryCount, e.message.substring(0, 500), retryCount >= MAX_SWEEP_RETRIES ? 'failed' : 'pending', now, sweep.id]
            );
        }
    }

    return swept;
}

/**
 * Sweep BNB: send from user address to hot wallet, minus gas.
 */
async function sweepBnb(db, hotWallet, provider, seedPhrase, sweep, hotAddress, now) {
    const userWallet = getWalletForIndex(seedPhrase, sweep.derivation_index, provider);
    const balance = await provider.getBalance(userWallet.address);

    if (balance === 0n) {
        log.info(`Sweep #${sweep.id}: BNB balance is 0, marking completed`);
        await db.execute(
            "UPDATE Wo_Sweep_Queue SET status = 'completed', updated_at = ? WHERE id = ?",
            [now, sweep.id]
        );
        await db.execute(
            "UPDATE Wo_Deposits SET status = 'swept', updated_at = ? WHERE id = ?",
            [now, sweep.deposit_id]
        );
        return;
    }

    // Estimate gas cost, send remainder
    const gasPrice = (await provider.getFeeData()).gasPrice;
    const gasLimit = 21000n;
    const gasCost = gasPrice * gasLimit;

    if (balance <= gasCost) {
        log.info(`Sweep #${sweep.id}: BNB balance ${ethers.formatEther(balance)} <= gas cost, skipping`);
        await db.execute(
            "UPDATE Wo_Sweep_Queue SET status = 'completed', updated_at = ? WHERE id = ?",
            [now, sweep.id]
        );
        return;
    }

    const sendAmount = balance - gasCost;
    log.info(`Sweeping ${ethers.formatEther(sendAmount)} BNB from ${userWallet.address} to ${hotAddress}`);

    const tx = await userWallet.sendTransaction({
        to: hotAddress,
        value: sendAmount,
        gasLimit,
        gasPrice,
    });

    const receipt = await tx.wait();
    log.info(`BNB sweep completed: ${tx.hash}`);

    await db.execute(
        "UPDATE Wo_Sweep_Queue SET status = 'completed', sweep_tx_hash = ?, updated_at = ? WHERE id = ?",
        [tx.hash, now, sweep.id]
    );
    await db.execute(
        "UPDATE Wo_Deposits SET status = 'swept', updated_at = ? WHERE id = ?",
        [now, sweep.deposit_id]
    );
}

/**
 * Sweep BEP-20 token: fund gas to user address, then transfer token to hot wallet.
 */
async function sweepToken(db, hotWallet, provider, seedPhrase, sweep, hotAddress, now) {
    const userWallet = getWalletForIndex(seedPhrase, sweep.derivation_index, provider);
    const contractAddr = sweep.token === 'TRDC' ? TRDC_CONTRACT : USDT_CONTRACT;

    const tokenContract = new ethers.Contract(contractAddr, ERC20_ABI, userWallet);
    const decimals = await tokenContract.decimals();
    const balance = await tokenContract.balanceOf(userWallet.address);

    if (balance === 0n) {
        log.info(`Sweep #${sweep.id}: ${sweep.token} balance is 0, marking completed`);
        await db.execute(
            "UPDATE Wo_Sweep_Queue SET status = 'completed', updated_at = ? WHERE id = ?",
            [now, sweep.id]
        );
        await db.execute(
            "UPDATE Wo_Deposits SET status = 'swept', updated_at = ? WHERE id = ?",
            [now, sweep.deposit_id]
        );
        return;
    }

    // Step 1: Check if user address has enough BNB for gas
    const userBnb = await provider.getBalance(userWallet.address);
    const gasPrice = (await provider.getFeeData()).gasPrice;
    const estimatedGas = 60000n; // BEP-20 transfer typically ~50k
    const gasCost = gasPrice * estimatedGas;

    if (userBnb < gasCost) {
        if (sweep.status === 'pending') {
            // Fund gas from hot wallet
            const gasToSend = gasCost * 2n; // 2x for safety margin
            log.info(`Funding ${ethers.formatEther(gasToSend)} BNB gas to ${userWallet.address} for ${sweep.token} sweep`);

            const gasTx = await hotWallet.sendTransaction({
                to: userWallet.address,
                value: gasToSend,
                gasLimit: 21000n,
                gasPrice,
            });

            await gasTx.wait();
            log.info(`Gas funded: ${gasTx.hash}`);

            await db.execute(
                "UPDATE Wo_Sweep_Queue SET status = 'gas_sent', gas_tx_hash = ?, updated_at = ? WHERE id = ?",
                [gasTx.hash, now, sweep.id]
            );

            // Will be picked up in next cycle when gas_sent
            return;
        }
        // status is gas_sent but still no BNB — wait more
        log.warn(`Sweep #${sweep.id}: gas_sent but user still has insufficient BNB. Waiting...`);
        return;
    }

    // Step 2: Transfer tokens from user address to hot wallet
    log.info(`Sweeping ${ethers.formatUnits(balance, decimals)} ${sweep.token} from ${userWallet.address} to ${hotAddress}`);

    await db.execute(
        "UPDATE Wo_Sweep_Queue SET status = 'sweeping', updated_at = ? WHERE id = ?",
        [now, sweep.id]
    );

    const tx = await tokenContract.transfer(hotAddress, balance);
    const receipt = await tx.wait();
    log.info(`${sweep.token} sweep completed: ${tx.hash}`);

    await db.execute(
        "UPDATE Wo_Sweep_Queue SET status = 'completed', sweep_tx_hash = ?, updated_at = ? WHERE id = ?",
        [tx.hash, now, sweep.id]
    );
    await db.execute(
        "UPDATE Wo_Deposits SET status = 'swept', updated_at = ? WHERE id = ?",
        [now, sweep.deposit_id]
    );
}

module.exports = { processSweeps };
