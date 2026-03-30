'use strict';

const { ethers } = require('ethers');
const log = require('./logger');
const {
    TRDC_CONTRACT, USDT_CONTRACT, TRANSFER_TOPIC,
    getDbPool, getConfig,
} = require('./config');

// Map contract address → token name
const TOKEN_MAP = {
    [TRDC_CONTRACT.toLowerCase()]: 'TRDC',
    [USDT_CONTRACT.toLowerCase()]: 'USDT',
};

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

let _rpcUrl = null;
let _rpcId = 1;

/** Set the RPC URL used for all direct fetch calls */
function setRpcUrl(url) {
    _rpcUrl = url;
}

/**
 * Make a single, non-batched JSON-RPC call directly via fetch.
 * Bypasses ethers.js batching which triggers rate limits on public nodes.
 */
async function rpc(method, params) {
    const body = JSON.stringify({ jsonrpc: '2.0', id: _rpcId++, method, params });
    const res = await fetch(_rpcUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body,
        signal: AbortSignal.timeout(10000),
    });
    const json = await res.json();
    if (json.error) {
        const err = new Error(json.error.message || 'RPC error');
        err.code = json.error.code;
        err.rpcError = true;
        throw err;
    }
    return json.result;
}

function isRateLimitError(e) {
    return e.rpcError && (e.code === -32005 || (e.message && e.message.includes('rate limit')));
}

/**
 * Scan a range of blocks for deposits.
 * Returns { found: number, rateLimited: boolean }
 */
async function scanBlocks(provider, addressMap, fromBlock, toBlock) {
    const db = getDbPool();
    const now = Math.floor(Date.now() / 1000);
    let depositsFound = 0;

    const fromHex = '0x' + fromBlock.toString(16);
    const toHex   = '0x' + toBlock.toString(16);

    // 1. Scan BEP-20 Transfer events for TRDC and USDT
    for (const [contractAddr, tokenName] of Object.entries(TOKEN_MAP)) {
        try {
            const logs = await rpc('eth_getLogs', [{
                fromBlock: fromHex,
                toBlock:   toHex,
                address:   contractAddr,
                topics:    [TRANSFER_TOPIC, null],
            }]);

            for (const logEntry of logs) {
                if (!logEntry.topics[2]) continue;
                const toAddr = '0x' + logEntry.topics[2].slice(26).toLowerCase();
                if (!addressMap.has(toAddr)) continue;

                const userId = addressMap.get(toAddr);

                // Decode amount using token-specific decimals
                const decimals = tokenName === 'USDT' ? 18 : 18; // both 18 on BSC
                const amount = ethers.formatUnits(BigInt(logEntry.data), decimals);

                const minKey = `deposit_min_${tokenName.toLowerCase()}`;
                const minAmount = parseFloat(await getConfig(minKey, '0'));
                if (parseFloat(amount) < minAmount) continue;

                const logIndex = parseInt(logEntry.logIndex, 16);
                const blockNumber = parseInt(logEntry.blockNumber, 16);

                const [result] = await db.execute(
                    `INSERT IGNORE INTO Wo_Deposits
                     (user_id, address, token, amount, tx_hash, log_index, block_number, confirmations, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'detected', ?, ?)`,
                    [userId, toAddr, tokenName, amount, logEntry.transactionHash, logIndex, blockNumber, now, now]
                );

                if (result.affectedRows > 0) {
                    log.info(`Detected ${tokenName} deposit: ${amount} to ${toAddr} (user ${userId}) tx:${logEntry.transactionHash}`);
                    depositsFound++;
                }
            }
        } catch (e) {
            if (isRateLimitError(e)) {
                log.warn(`Rate limited scanning ${tokenName} logs — will rotate RPC`);
                return { found: depositsFound, rateLimited: true };
            }
            log.error(`Error scanning ${tokenName} logs: ${e.message}`);
        }

        await sleep(600); // gap between requests on same endpoint
    }

    // 2. Scan native BNB transfers block by block
    try {
        for (let blockNum = fromBlock; blockNum <= toBlock; blockNum++) {
            const hexNum = '0x' + blockNum.toString(16);
            const block = await rpc('eth_getBlockByNumber', [hexNum, true]);
            if (!block || !Array.isArray(block.transactions)) continue;

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

            await sleep(200); // avoid flooding with block requests
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
    const currentBlock = parseInt(await rpc('eth_blockNumber', []), 16);
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

        // Notify user — provide all NOT NULL columns
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

module.exports = { scanBlocks, processConfirmations, setRpcUrl };
