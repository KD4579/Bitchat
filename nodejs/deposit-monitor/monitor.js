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
 * Classify an RPC failure:
 *   'rate_limit' — rotate RPC and retry later
 *   'history'    — the node no longer has data that old (pruned / archive-only)
 *   'other'      — anything else; the range must be retried, never skipped
 */
function classifyRpcError(e) {
    if (isRateLimitError(e)) return 'rate_limit';
    const msg = String(e && e.message || '');
    if (/archive|pruned|missing trie node|header not found|history has been pruned/i.test(msg)) return 'history';
    if (/limit exceeded|too many requests|429/i.test(msg)) return 'rate_limit';
    return 'other';
}

function addressTopic(addr) {
    return '0x' + addr.toLowerCase().replace(/^0x/, '').padStart(64, '0');
}

/**
 * True when every deposit address has never sent a transaction and holds no
 * BNB, TRDC or USDT right now. Then no deposit can ever have reached them:
 * a deposit would still be there, because moving funds out needs a transaction
 * signed by the address (nonce > 0). Used to skip slow scans safely.
 */
async function addressesProvenEmpty(addressMap) {
    const balanceOf = (token, addr) => rpc('eth_call', [{
        to: token, data: '0x70a08231' + addressTopic(addr).slice(2),
    }, 'latest']);
    for (const addr of addressMap.keys()) {
        const [nonce, bnb, trdc, usdt] = await Promise.all([
            rpc('eth_getTransactionCount', [addr, 'latest']),
            rpc('eth_getBalance', [addr, 'latest']),
            balanceOf(TRDC_CONTRACT, addr),
            balanceOf(USDT_CONTRACT, addr),
        ]);
        if (BigInt(nonce) !== 0n || BigInt(bnb) !== 0n || BigInt(trdc) !== 0n || BigInt(usdt) !== 0n) {
            return false;
        }
    }
    return true;
}

/**
 * Cheap check for native BNB deposits in a block range: if no address's BNB
 * balance or nonce changed between fromBlock-1 and toBlock, no BNB arrived.
 * Returns 'unchanged', 'changed' or 'unknown' (node lacks that state, or too
 * many addresses for this to be cheaper than reading the blocks).
 */
async function nativeBalancesUnchanged(addressMap, fromBlock, toBlock) {
    if (addressMap.size > 25) return 'unknown';
    const before = '0x' + (fromBlock - 1).toString(16);
    const after = '0x' + toBlock.toString(16);
    try {
        for (const addr of addressMap.keys()) {
            const [b0, b1, n0, n1] = await Promise.all([
                rpc('eth_getBalance', [addr, before]),
                rpc('eth_getBalance', [addr, after]),
                rpc('eth_getTransactionCount', [addr, before]),
                rpc('eth_getTransactionCount', [addr, after]),
            ]);
            if (BigInt(b0) !== BigInt(b1) || BigInt(n0) !== BigInt(n1)) return 'changed';
        }
        return 'unchanged';
    } catch (e) {
        if (classifyRpcError(e) === 'rate_limit') throw e;
        return 'unknown';
    }
}

/**
 * Scan a range of blocks for deposits.
 * opts.nativeMode: 'auto' (default) | 'skip' (caller proved no BNB can have arrived)
 * Returns { found, rateLimited, historyUnavailable, failed }.
 * The caller must only advance past toBlock when all three flags are false.
 */
async function scanBlocks(provider, addressMap, fromBlock, toBlock, opts = {}) {
    const db = getDbPool();
    const now = Math.floor(Date.now() / 1000);
    let depositsFound = 0;
    const fail = (kind, what, e) => {
        if (kind === 'rate_limit') {
            log.warn(`Rate limited scanning ${what} — will rotate RPC`);
            return { found: depositsFound, rateLimited: true, historyUnavailable: false, failed: false };
        }
        if (kind === 'history') {
            return { found: depositsFound, rateLimited: false, historyUnavailable: true, failed: false, error: e.message };
        }
        log.error(`Error scanning ${what} (blocks ${fromBlock}-${toBlock}): ${e.message}`);
        return { found: depositsFound, rateLimited: false, historyUnavailable: false, failed: true, error: e.message };
    };

    const fromHex = '0x' + fromBlock.toString(16);
    const toHex   = '0x' + toBlock.toString(16);
    // Ask the node only for transfers TO our deposit addresses (topic 2), in chunks
    const recipients = [...addressMap.keys()].map(addressTopic);
    const recipientChunks = [];
    for (let i = 0; i < recipients.length; i += 100) recipientChunks.push(recipients.slice(i, i + 100));

    // 1. BEP-20 Transfer events for TRDC and USDT
    for (const [contractAddr, tokenName] of Object.entries(TOKEN_MAP)) {
        for (const chunk of recipientChunks) {
            let logs;
            try {
                logs = await rpc('eth_getLogs', [{
                    fromBlock: fromHex,
                    toBlock:   toHex,
                    address:   contractAddr,
                    topics:    [TRANSFER_TOPIC, null, chunk],
                }]);
            } catch (e) {
                return fail(classifyRpcError(e), `${tokenName} logs`, e);
            }

            for (const logEntry of logs) {
                if (!logEntry.topics[2]) continue;
                const toAddr = '0x' + logEntry.topics[2].slice(26).toLowerCase();
                if (!addressMap.has(toAddr)) continue;

                const userId = addressMap.get(toAddr);
                const amount = ethers.formatUnits(BigInt(logEntry.data), 18); // TRDC and USDT are 18 decimals on BSC

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
            await sleep(300); // gap between requests on same endpoint
        }
    }

    // 2. Native BNB transfers
    if (opts.nativeMode === 'skip') {
        return { found: depositsFound, rateLimited: false, historyUnavailable: false, failed: false };
    }
    try {
        const state = await nativeBalancesUnchanged(addressMap, fromBlock, toBlock);
        if (state === 'unchanged') {
            return { found: depositsFound, rateLimited: false, historyUnavailable: false, failed: false };
        }
    } catch (e) {
        return fail(classifyRpcError(e), 'BNB balances', e);
    }

    // Balances changed (or unknown): read the blocks to find the transactions
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
        return fail(classifyRpcError(e), 'BNB transfers', e);
    }

    return { found: depositsFound, rateLimited: false, historyUnavailable: false, failed: false };
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

module.exports = { scanBlocks, processConfirmations, setRpcUrl, addressesProvenEmpty, classifyRpcError };
