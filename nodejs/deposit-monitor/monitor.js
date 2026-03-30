'use strict';

const { ethers } = require('ethers');
const log = require('./logger');
const {
    TRDC_CONTRACT, USDT_CONTRACT, TRANSFER_TOPIC,
    ERC20_ABI, getDbPool, getConfig, setConfig,
} = require('./config');

// Map contract address → token name
const TOKEN_MAP = {
    [TRDC_CONTRACT.toLowerCase()]: 'TRDC',
    [USDT_CONTRACT.toLowerCase()]: 'USDT',
};

/**
 * Scan a range of blocks for deposits to any of our monitored addresses.
 * Detects both BEP-20 transfers (TRDC, USDT) and native BNB transfers.
 */
async function scanBlocks(provider, addressMap, fromBlock, toBlock) {
    const db = getDbPool();
    const now = Math.floor(Date.now() / 1000);
    let depositsFound = 0;

    // 1. Scan BEP-20 Transfer events for TRDC and USDT
    const tokenContracts = [TRDC_CONTRACT, USDT_CONTRACT];

    for (let i = 0; i < tokenContracts.length; i++) {
        if (i > 0) await sleep(800); // avoid rate limiting on public RPCs
        const contractAddr = tokenContracts[i];
        const tokenName = TOKEN_MAP[contractAddr.toLowerCase()];

        try {
            const logs = await provider.getLogs({
                fromBlock,
                toBlock,
                address: contractAddr,
                topics: [
                    TRANSFER_TOPIC,
                    null, // from (any)
                    // to: we filter manually since we have many addresses
                ],
            });

            for (const logEntry of logs) {
                // Decode the 'to' address from topic[2]
                if (!logEntry.topics[2]) continue;
                const toAddr = '0x' + logEntry.topics[2].slice(26).toLowerCase();

                if (!addressMap.has(toAddr)) continue;

                const userId = addressMap.get(toAddr);
                const contract = new ethers.Contract(contractAddr, ERC20_ABI, provider);
                const decimals = await contract.decimals();
                const amount = ethers.formatUnits(BigInt(logEntry.data), decimals);

                // Check min deposit
                const minKey = `deposit_min_${tokenName.toLowerCase()}`;
                const minAmount = parseFloat(await getConfig(minKey, '0'));
                if (parseFloat(amount) < minAmount) {
                    log.info(`Skipping small ${tokenName} deposit: ${amount} < min ${minAmount} (user ${userId})`);
                    continue;
                }

                // Insert deposit (unique on tx_hash + log_index)
                const [result] = await db.execute(
                    `INSERT IGNORE INTO Wo_Deposits
                     (user_id, address, token, amount, tx_hash, log_index, block_number, confirmations, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'detected', ?, ?)`,
                    [userId, toAddr, tokenName, amount, logEntry.transactionHash, logEntry.index, logEntry.blockNumber, now, now]
                );

                if (result.affectedRows > 0) {
                    log.info(`Detected ${tokenName} deposit: ${amount} to ${toAddr} (user ${userId}) tx:${logEntry.transactionHash}`);
                    depositsFound++;
                }
            }
        } catch (e) {
            log.error(`Error scanning ${tokenName} logs: ${e.message}`);
        }
    }

    // 2. Scan native BNB transfers by checking each block's transactions
    try {
        for (let blockNum = fromBlock; blockNum <= toBlock; blockNum++) {
            const block = await provider.getBlock(blockNum, true);
            if (!block || !block.prefetchedTransactions) continue;

            for (const tx of block.prefetchedTransactions) {
                if (!tx.to) continue; // contract creation
                const toAddr = tx.to.toLowerCase();

                if (!addressMap.has(toAddr)) continue;
                if (tx.value === 0n) continue;

                const userId = addressMap.get(toAddr);
                const amount = ethers.formatEther(tx.value);

                // Check min deposit
                const minBnb = parseFloat(await getConfig('deposit_min_bnb', '0.001'));
                if (parseFloat(amount) < minBnb) continue;

                const [result] = await db.execute(
                    `INSERT IGNORE INTO Wo_Deposits
                     (user_id, address, token, amount, tx_hash, log_index, block_number, confirmations, status, created_at, updated_at)
                     VALUES (?, ?, 'BNB', ?, ?, 0, ?, 0, 'detected', ?, ?)`,
                    [userId, toAddr, amount, tx.hash, blockNum, now, now]
                );

                if (result.affectedRows > 0) {
                    log.info(`Detected BNB deposit: ${amount} BNB to ${toAddr} (user ${userId}) tx:${tx.hash}`);
                    depositsFound++;
                }
            }
        }
    } catch (e) {
        log.error(`Error scanning BNB transfers: ${e.message}`);
    }

    return depositsFound;
}

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

/**
 * Update confirmation counts and credit confirmed deposits.
 */
async function processConfirmations(provider) {
    const db = getDbPool();
    const currentBlock = await provider.getBlockNumber();
    const requiredConf = parseInt(await getConfig('deposit_confirmations', '15'));
    const now = Math.floor(Date.now() / 1000);

    // Get all unconfirmed deposits
    const [pending] = await db.execute(
        "SELECT * FROM Wo_Deposits WHERE status IN ('detected', 'confirmed') ORDER BY block_number ASC"
    );

    let credited = 0;

    for (const deposit of pending) {
        const confirmations = currentBlock - Number(deposit.block_number);

        // Update confirmation count
        await db.execute(
            'UPDATE Wo_Deposits SET confirmations = ?, updated_at = ? WHERE id = ?',
            [confirmations, now, deposit.id]
        );

        if (confirmations >= requiredConf && deposit.status === 'detected') {
            // Mark as confirmed
            await db.execute(
                "UPDATE Wo_Deposits SET status = 'confirmed', updated_at = ? WHERE id = ? AND status = 'detected'",
                [now, deposit.id]
            );
            log.info(`Deposit #${deposit.id} confirmed (${confirmations}/${requiredConf} blocks)`);
        }

        if (confirmations >= requiredConf && (deposit.status === 'detected' || deposit.status === 'confirmed')) {
            // Credit user balance
            const success = await creditUser(db, deposit, now);
            if (success) {
                credited++;

                // Queue for sweeping
                await db.execute(
                    `INSERT INTO Wo_Sweep_Queue (deposit_id, address, token, amount, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, 'pending', ?, ?)`,
                    [deposit.id, deposit.address, deposit.token, deposit.amount, now, now]
                );
            }
        }
    }

    return credited;
}

/**
 * Credit a confirmed deposit to the user's balance.
 */
async function creditUser(db, deposit, now) {
    let balanceColumn;

    switch (deposit.token) {
        case 'TRDC': balanceColumn = 'wallet'; break;
        case 'USDT': balanceColumn = 'balance_usdt'; break;
        case 'BNB':  balanceColumn = 'balance_bnb'; break;
        default:
            log.error(`Unknown token ${deposit.token} for deposit #${deposit.id}`);
            return false;
    }

    // Atomic credit
    await db.execute(
        `UPDATE Wo_Users SET ${balanceColumn} = ${balanceColumn} + ? WHERE user_id = ?`,
        [deposit.amount, deposit.user_id]
    );

    // Mark as credited
    const [result] = await db.execute(
        "UPDATE Wo_Deposits SET status = 'credited', credited_at = ?, updated_at = ? WHERE id = ? AND status IN ('detected', 'confirmed')",
        [now, now, deposit.id]
    );

    if (result.affectedRows > 0) {
        log.info(`Credited ${deposit.amount} ${deposit.token} to user ${deposit.user_id} (deposit #${deposit.id})`);

        // Log transaction
        const notes = JSON.stringify({ deposit_id: deposit.id, tx_hash: deposit.tx_hash, token: deposit.token });
        await db.execute(
            "INSERT INTO Wo_Payment_Transactions (userid, kind, amount, notes) VALUES (?, 'DEPOSIT_CREDITED', ?, ?)",
            [deposit.user_id, deposit.amount, notes]
        );

        // Notify user (include all NOT NULL columns)
        await db.execute(
            "INSERT INTO Wo_Notifications (notifier_id, recipient_id, post_id, page_id, group_id, group_chat_id, event_id, thread_id, blog_id, story_id, type, type2, text, url, full_link, seen, sent_push, admin, time) VALUES (0, ?, 0, 0, 0, 0, 0, 0, 0, 0, 'deposit', '', ?, '/wallet', '', 0, 0, 0, ?)",
            [deposit.user_id, `Your ${deposit.token} deposit of ${deposit.amount} has been credited to your account.`, now]
        );

        return true;
    }

    return false;
}

module.exports = { scanBlocks, processConfirmations };
