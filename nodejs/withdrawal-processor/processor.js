'use strict';

const { ethers } = require('ethers');
const log = require('./logger');
const { TRDC_CONTRACT, ERC20_ABI, getDbPool } = require('./config');

const MAX_RETRIES = 3;
const MAX_GAS_GWEI = 15;

/**
 * Process all pending withdrawals (up to 5 per cycle).
 */
async function processWithdrawals(wallet, provider) {
    const db = getDbPool();

    // Only fetch admin-approved withdrawals (not pending — those need admin approval first)
    const [rows] = await db.execute(
        `SELECT * FROM Wo_TRDC_Withdrawals
         WHERE status = 'approved' AND retry_count < ?
         ORDER BY created_at ASC LIMIT 5`,
        [MAX_RETRIES]
    );

    if (rows.length === 0) return 0;

    log.info(`Found ${rows.length} approved withdrawal(s) to process`);

    const trdcContract = new ethers.Contract(TRDC_CONTRACT, ERC20_ABI, wallet);
    let processed = 0;

    for (const withdrawal of rows) {
        try {
            await processSingleWithdrawal(db, wallet, provider, trdcContract, withdrawal);
            processed++;
        } catch (e) {
            log.error(`Withdrawal #${withdrawal.id} error: ${e.message}`);
        }
    }

    return processed;
}

/**
 * Process a single withdrawal request.
 */
async function processSingleWithdrawal(db, wallet, provider, trdcContract, withdrawal) {
    const now = Math.floor(Date.now() / 1000);

    // Mark as processing (prevents double-processing)
    const [updateResult] = await db.execute(
        `UPDATE Wo_TRDC_Withdrawals SET status = 'processing', processed_at = ?
         WHERE id = ? AND status = 'approved'`,
        [now, withdrawal.id]
    );

    if (updateResult.affectedRows === 0) {
        log.warn(`Withdrawal #${withdrawal.id} already picked up by another process`);
        return;
    }

    log.info(`Processing withdrawal #${withdrawal.id}: ${withdrawal.net_amount} TRDC -> ${withdrawal.wallet_address}`);

    // Validate destination address
    if (!ethers.isAddress(withdrawal.wallet_address)) {
        await markFailedAndRefund(db, withdrawal, 'Invalid wallet address format');
        return;
    }

    // Check gas price
    const feeData = await provider.getFeeData();
    const gasGwei = parseFloat(ethers.formatUnits(feeData.gasPrice || 0n, 'gwei'));
    if (gasGwei > MAX_GAS_GWEI) {
        log.warn(`Gas ${gasGwei.toFixed(1)} gwei > max ${MAX_GAS_GWEI}. Deferring withdrawal #${withdrawal.id}`);
        await db.execute(
            `UPDATE Wo_TRDC_Withdrawals SET status = 'approved', processed_at = NULL WHERE id = ?`,
            [withdrawal.id]
        );
        return;
    }

    // Check platform wallet TRDC balance
    const decimals = await trdcContract.decimals();
    const needed = ethers.parseUnits(withdrawal.net_amount.toString(), decimals);
    const balance = await trdcContract.balanceOf(wallet.address);

    if (balance < needed) {
        log.error(`Insufficient platform TRDC balance. Have: ${ethers.formatUnits(balance, decimals)}, Need: ${withdrawal.net_amount}`);
        await markFailed(db, withdrawal, 'Insufficient platform TRDC balance — contact admin');
        return;
    }

    // Check BNB balance for gas
    const bnbBalance = await provider.getBalance(wallet.address);
    if (bnbBalance < ethers.parseEther('0.001')) {
        log.error('BNB balance too low for gas');
        await markFailed(db, withdrawal, 'Insufficient BNB for gas — contact admin');
        return;
    }

    // Execute BEP-20 transfer
    try {
        log.info(`Sending ${withdrawal.net_amount} TRDC to ${withdrawal.wallet_address}...`);
        // Estimate gas before submitting
        const gasEstimate = await trdcContract.transfer.estimateGas(withdrawal.wallet_address, needed);
        // Add 20% buffer
        const gasLimit = gasEstimate * 120n / 100n;
        // Submit with explicit gas limit
        const tx = await trdcContract.transfer(withdrawal.wallet_address, needed, { gasLimit });
        log.info(`TX submitted: ${tx.hash}`);

        const receipt = await tx.wait();
        log.info(`TX confirmed: ${tx.hash} (block ${receipt.blockNumber})`);

        const gasCostBnb = ethers.formatEther(receipt.gasUsed * receipt.gasPrice);
        const completedAt = Math.floor(Date.now() / 1000);

        // Mark completed
        await db.execute(
            `UPDATE Wo_TRDC_Withdrawals
             SET status = 'completed', tx_hash = ?, gas_used = ?, gas_cost_bnb = ?, completed_at = ?
             WHERE id = ?`,
            [tx.hash, receipt.gasUsed.toString(), gasCostBnb, completedAt, withdrawal.id]
        );

        // Log transaction
        const notes = JSON.stringify({ tx_hash: tx.hash, withdrawal_id: withdrawal.id });
        await db.execute(
            `INSERT INTO Wo_Payment_Transactions (userid, kind, amount, notes) VALUES (?, 'WITHDRAWAL_COMPLETED', ?, ?)`,
            [withdrawal.user_id, withdrawal.net_amount, notes]
        );

        log.info(`Withdrawal #${withdrawal.id} completed. TX: ${tx.hash}, Gas: ${gasCostBnb} BNB`);

    } catch (e) {
        const retryCount = withdrawal.retry_count + 1;
        const reason = e.message.substring(0, 500);

        if (retryCount >= MAX_RETRIES) {
            log.error(`Withdrawal #${withdrawal.id} failed after ${MAX_RETRIES} retries. Refunding.`);
            await markFailedAndRefund(db, withdrawal, 'Max retries exceeded: ' + reason);
        } else {
            log.warn(`Withdrawal #${withdrawal.id} attempt ${retryCount} failed: ${reason}. Will retry.`);
            await db.execute(
                `UPDATE Wo_TRDC_Withdrawals
                 SET status = 'approved', retry_count = ?, failure_reason = ?
                 WHERE id = ?`,
                [retryCount, reason, withdrawal.id]
            );
        }
    }
}

/**
 * Mark withdrawal as failed without refund (admin needs to investigate).
 */
async function markFailed(db, withdrawal, reason) {
    log.error(`Withdrawal #${withdrawal.id} FAILED (no auto-refund): ${reason}`);
    await db.execute(
        `UPDATE Wo_TRDC_Withdrawals SET status = 'failed', failure_reason = ? WHERE id = ?`,
        [reason.substring(0, 500), withdrawal.id]
    );
}

/**
 * Mark withdrawal as failed AND refund the full amount to user's wallet.
 */
async function markFailedAndRefund(db, withdrawal, reason) {
    log.error(`Withdrawal #${withdrawal.id} FAILED — refunding ${withdrawal.amount} TRDC to user ${withdrawal.user_id}`);

    await db.execute(
        `UPDATE Wo_Users SET wallet = wallet + ? WHERE user_id = ?`,
        [withdrawal.amount, withdrawal.user_id]
    );

    await db.execute(
        `UPDATE Wo_TRDC_Withdrawals SET status = 'failed', failure_reason = ? WHERE id = ?`,
        [reason.substring(0, 500), withdrawal.id]
    );

    const notes = JSON.stringify({ withdrawal_id: withdrawal.id, reason });
    await db.execute(
        `INSERT INTO Wo_Payment_Transactions (userid, kind, amount, notes) VALUES (?, 'WITHDRAWAL_REFUNDED', ?, ?)`,
        [withdrawal.user_id, withdrawal.amount, notes]
    );
}

module.exports = { processWithdrawals };
