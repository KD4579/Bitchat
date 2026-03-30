'use strict';

const { ethers } = require('ethers');
const log = require('./logger');
const {
    TRDC_CONTRACT, USDT_CONTRACT, TRANSFER_TOPIC,
    ERC20_ABI, getDbPool, getConfig,
} = require('./config');

// Map contract address → token name
const TOKEN_MAP = {
    [TRDC_CONTRACT.toLowerCase()]: 'TRDC',
    [USDT_CONTRACT.toLowerCase()]: 'USDT',
};

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

function isRateLimitError(e) {
    return e.code === 'BAD_DATA' ||
        (e.message && e.message.includes('rate limit')) ||
        (e.message && e.message.includes('-32005'));
}

/**
 * Scan a range of blocks for deposits to any of our monitored addresses.
 * Returns { found: number, rateLimited: boolean }
 */
async function scanBlocks(provider, addressMap, fromBlock, toBlock) {
    const db = getDbPool();
    const now = Math.floor(Date.now() / 1000);
    let depositsFound = 0;
    let rateLimited = false;

    // 1. Scan BEP-20 Transfer events for TRDC and USDT
    const tokenContracts = [TRDC_CONTRACT, USDT_CONTRACT];

    for (let i = 0; i < tokenContracts.length; i++) {
        if (i > 0) await sleep(500); // space out requests to avoid rate limits
        const contractAddr = tokenContracts[i];
        const tokenName = TOKEN_MAP[contractAddr.toLowerCase()];

        try {
            const logs = await provider.getLogs({
                fromBlock,
                toBlock,
                address: contractAddr,
                topics: [TRANSFER_TOPIC, null],
            });

            for (const logEntry of logs) {
                if (!logEntry.topics[2]) continue;
                const toAddr = '0x' + logEntry.topics[2].slice(26).toLowerCase();

                if (!addressMap.has(toAddr)) continue;

                const userId = addressMap.get(toAddr);
                const contract = new ethers.Contract(contractAddr, ERC20_ABI, provider);
                const decimals = await contract.decimals();
                const amount = ethers.formatUnits(BigInt(logEntry.data), decimals);

                const minKey = `deposit_min_${tokenName.toLowerCase()}`;
                const minAmount = parseFloat(await getConfig(minKey, '0'));
                if (parseFloat(amount) < minAmount) {
                    log.info(`Skipping small ${tokenName} deposit: ${amount} < min ${minAmount} (user ${userId})`);
                    continue;
                }

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
            if (isRateLimitError(e)) {
                log.warn(`Rate limited scanning ${tokenName} logs — will rotate RPC`);
                rateLimited = true;
                return { found: depositsFound, rateLimited: true };
            }
            log.error(`Error scanning ${tokenName} logs: ${e.message}`);
        }
    }

    await sleep(500); // gap before BNB block scan

    // 2. Scan native BNB transfers by fetching each block's transactions
    try {
        for (let blockNum = fromBlock; blockNum <= toBlock; blockNum++) {
            // Get block with full transaction objects (works with single JsonRpcProvider)
            const block = await provider.send('eth_getBlockByNumber', [
                '0x' + blockNum.toString(16),
                true, // include full transactions
            ]);
            if (!block || !block.transactions) continue;

            for (const tx of block.transactions) {
                if (!tx.to) continue;
                const toAddr = tx.to.toLowerCase();

                if (!addressMap.has(toAddr)) continue;

                const value = BigInt(tx.value);
                if (value === 0n) continue;

                const userId = addressMap.get(toAddr);
                const amount = ethers.formatEther(value);

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
        if (isRateLimitError(e)) {
            log.warn('Rate limited scanning BNB blocks — will rotate RPC');
            return { found: depositsFound, rateLimited: true };
        }
        log.error(`Error scanning BNB transfers: ${e.message}`);
    }

    return { found: depositsFound, rateLimited: false };
}

/**
 * Update confirmation counts and credit confirmed deposits.
 */
async function processConfirmations(provider) {
    const db = getDbPool();
    const currentBlock = await provider.getBlockNumber();
    const requiredConf = parseInt(await getConfig('deposit_confirmations', '15'));
    const now = Math.floor(Date.now() / 1000);

    const [pending] = await db.execute(
        "SELECT * FROM Wo_Deposits WHERE status IN ('detected', 'confirmed') ORDER BY block_number ASC"
    );

    let credited = 0;

    for (const deposit of pending) {
        const confirmations = currentBlock - Number(deposit.block_number);

        await db.execute(
            'UPDATE Wo_Deposits SET confirmations = ?, updated_at = ? WHERE id = ?',
            [confirmations, now, deposit.id]
        );

        if (confirmations >= requiredConf && deposit.status === 'detected') {
            await db.execute(
                "UPDATE Wo_Deposits SET status = 'confirmed', updated_at = ? WHERE id = ? AND status = 'detected'",
                [now, deposit.id]
            );
            log.info(`Deposit #${deposit.id} confirmed (${confirmations}/${requiredConf} blocks)`);
        }

        if (confirmations >= requiredConf && (deposit.status === 'detected' || deposit.status === 'confirmed')) {
            const success = await creditUser(db, deposit, now);
            if (success) {
                credited++;
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

    await db.execute(
        `UPDATE Wo_Users SET ${balanceColumn} = ${balanceColumn} + ? WHERE user_id = ?`,
        [deposit.amount, deposit.user_id]
    );

    const [result] = await db.execute(
        "UPDATE Wo_Deposits SET status = 'credited', credited_at = ?, updated_at = ? WHERE id = ? AND status IN ('detected', 'confirmed')",
        [now, now, deposit.id]
    );

    if (result.affectedRows > 0) {
        log.info(`Credited ${deposit.amount} ${deposit.token} to user ${deposit.user_id} (deposit #${deposit.id})`);

        const notes = JSON.stringify({ deposit_id: deposit.id, tx_hash: deposit.tx_hash, token: deposit.token });
        await db.execute(
            "INSERT INTO Wo_Payment_Transactions (userid, kind, amount, notes) VALUES (?, 'DEPOSIT_CREDITED', ?, ?)",
            [deposit.user_id, deposit.amount, notes]
        );

        // Notify user — include all NOT NULL columns in Wo_Notifications
        await db.execute(
            `INSERT INTO Wo_Notifications
             (notifier_id, recipient_id, post_id, page_id, group_id, group_chat_id, event_id, thread_id, blog_id, story_id, type, type2, text, url, full_link, seen, sent_push, admin, time)
             VALUES (0, ?, 0, 0, 0, 0, 0, 0, 0, 0, 'deposit', '', ?, '/wallet', '', 0, 0, 0, ?)`,
            [deposit.user_id, `Your ${deposit.token} deposit of ${deposit.amount} has been credited to your account.`, now]
        );

        return true;
    }

    return false;
}

module.exports = { scanBlocks, processConfirmations };
