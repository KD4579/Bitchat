'use strict';

require('dotenv').config({ path: __dirname + '/.env' });

const { ethers } = require('ethers');
const log = require('./logger');
const { getConfig, setConfig, closeDb } = require('./config');
const { loadAllAddresses } = require('./address-manager');
const { scanBlocks, processConfirmations, setRpcUrl, addressesProvenEmpty } = require('./monitor');
const { processSweeps } = require('./sweeper');

const POLL_INTERVAL = 15000; // 15 seconds
const MAX_BLOCKS_PER_SCAN = 50;
const CATCH_UP_THRESHOLD = 500;   // blocks behind before switching to catch-up mode
const CATCH_UP_WINDOW = 1000;     // blocks per eth_getLogs in catch-up (public RPC limit)

// Multiple public BSC RPC endpoints — rotated on rate-limit errors
// publicnode.com and 1rpc.io have no batch limits; binance.org nodes are fallbacks
const RPC_URLS = (process.env.RPC_URL ? [process.env.RPC_URL] : []).concat([
    'https://bsc.publicnode.com',
    'https://bsc-rpc.publicnode.com',
    'https://1rpc.io/bnb',
    'https://bsc-dataseed1.binance.org',
    'https://bsc-dataseed2.binance.org',
    'https://bsc-dataseed3.binance.org',
    'https://bsc-dataseed1.defibit.io',
    'https://bsc-dataseed1.ninicoin.io',
]);

let rpcIndex = 0;

function currentRpcUrl() { return RPC_URLS[rpcIndex % RPC_URLS.length]; }

function rotateRpc() {
    rpcIndex = (rpcIndex + 1) % RPC_URLS.length;
    const url = currentRpcUrl();
    log.info(`Rotating RPC → ${url}`);
    setRpcUrl(url);
    return url;
}

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

async function main() {
    log.info('========================================');
    log.info('BSC Deposit Monitor starting...');
    log.info('========================================');

    const hotWalletKey = process.env.HOT_WALLET_KEY;
    if (!hotWalletKey || hotWalletKey === '0x_YOUR_PRIVATE_KEY_HERE') {
        log.error('HOT_WALLET_KEY not set in .env file. Exiting.');
        process.exit(1);
    }

    const seedPhrase = process.env.HD_SEED_PHRASE;
    if (!seedPhrase || seedPhrase.includes('replace with actual')) {
        log.error('HD_SEED_PHRASE not set in .env file. Exiting.');
        process.exit(1);
    }

    const enabled = await getConfig('deposit_enabled', '0');
    if (enabled !== '1') {
        log.warn('Deposits DISABLED in admin panel. Entering standby...');
        await standbyLoop();
    }

    // Set initial RPC URL for monitor's direct fetch calls
    setRpcUrl(currentRpcUrl());
    log.info(`RPC: ${currentRpcUrl()} (${RPC_URLS.length} endpoints, no batching)`);

    // Use a plain provider only for wallet balance checks and sweeping
    // monitor.js makes ALL blockchain reads via direct fetch (no batching)
    const provider = new ethers.JsonRpcProvider(currentRpcUrl(), 56);
    const hotWallet = new ethers.Wallet(hotWalletKey, provider);

    log.info(`Hot Wallet: ${hotWallet.address}`);

    // Verify BSC mainnet
    const network = await provider.getNetwork();
    if (network.chainId !== 56n) {
        log.error(`Wrong chain! Expected BSC (56), got ${network.chainId}. Exiting.`);
        process.exit(1);
    }
    log.info('Connected to BSC Mainnet');

    // Check hot wallet BNB balance
    const bnbBalance = ethers.formatEther(await provider.getBalance(hotWallet.address));
    log.info(`Hot wallet BNB balance: ${bnbBalance} BNB`);

    let sweepingEnabled = parseFloat(bnbBalance) >= 0.01;
    if (!sweepingEnabled) {
        log.warn(`Sweeping disabled (${bnbBalance} BNB < 0.01). Monitoring and crediting will continue. Fund ${hotWallet.address} with 0.05+ BNB to enable sweeping.`);
    }

    // Validate HD seed
    try {
        const hdNode = ethers.HDNodeWallet.fromPhrase(seedPhrase, '', "m/44'/60'/0'/0");
        log.info(`HD wallet root verified. First address: ${hdNode.deriveChild(0).address}`);
    } catch (e) {
        log.error(`Invalid HD seed phrase: ${e.message}. Exiting.`);
        process.exit(1);
    }

    // Initialize last scanned block. A stored value that is not a positive whole
    // number (e.g. "NaN" saved by an older version) is never replaced silently:
    // jumping to the chain head could skip real deposits.
    let lastBlock;
    for (;;) {
        const raw = String(await getConfig('deposit_monitor_last_block', '0')).trim();
        if (raw === '0' || raw === '') {
            lastBlock = (await getBlockNumber()) - 10;
            await setConfig('deposit_monitor_last_block', String(lastBlock));
            log.info(`Initialized last block to ${lastBlock}`);
            break;
        }
        if (/^\d+$/.test(raw) && Number.isSafeInteger(Number(raw))) {
            lastBlock = Number(raw);
            log.info(`Resuming from block ${lastBlock}`);
            break;
        }
        log.error(`Stored deposit_monitor_last_block "${raw}" is not a valid block number. Set it in Wo_Config to the last block known to be scanned. Re-checking in 60s...`);
        await sleep(60000);
    }

    log.info(`Starting deposit monitor loop (poll every ${POLL_INTERVAL / 1000}s)`);
    let cycleCount = 0;
    let consecutiveRateLimits = 0;
    let lastCatchUpLog = 0;

    while (true) {
        try {
            cycleCount++;

            // Check if still enabled every 10 cycles
            if (cycleCount % 10 === 0) {
                const stillEnabled = await getConfig('deposit_enabled', '0');
                if (stillEnabled !== '1') {
                    log.warn('Deposits DISABLED via admin panel. Entering standby...');
                    await standbyLoop();
                    log.info('Deposits re-enabled! Resuming...');
                }
            }

            const addressMap = await loadAllAddresses();
            if (addressMap.size === 0) {
                if (cycleCount % 20 === 0) log.info('No deposit addresses to monitor yet');
                await sleep(POLL_INTERVAL);
                continue;
            }

            const currentBlock = await getBlockNumber();

            const fromBlock = lastBlock + 1;
            if (fromBlock > currentBlock) {
                await sleep(POLL_INTERVAL);
                continue;
            }

            // Far behind (downtime): bigger windows, filtered logs, and skip the
            // per-block BNB scan when the addresses provably never received anything.
            const behind = currentBlock - lastBlock;
            const catchUp = behind > CATCH_UP_THRESHOLD;
            const windowSize = catchUp ? CATCH_UP_WINDOW : MAX_BLOCKS_PER_SCAN;
            const toBlock = Math.min(fromBlock + windowSize - 1, currentBlock);
            let nativeMode = 'auto';
            if (catchUp && await addressesProvenEmpty(addressMap)) nativeMode = 'skip';

            if (catchUp && Date.now() - lastCatchUpLog > 60000) {
                log.info(`Catch-up: scanning ${fromBlock}-${toBlock}, ${behind} blocks behind (${addressMap.size} addresses, BNB scan: ${nativeMode}, RPC: ${currentRpcUrl()})`);
                lastCatchUpLog = Date.now();
            } else if (!catchUp && cycleCount % 10 === 0) {
                log.info(`Scanning blocks ${fromBlock}-${toBlock} (${addressMap.size} addresses, RPC: ${currentRpcUrl()})`);
            }

            const scan = await scanBlocks(provider, addressMap, fromBlock, toBlock, { nativeMode });

            if (scan.found > 0) log.info(`Found ${scan.found} new deposit(s)`);

            if (scan.rateLimited) {
                consecutiveRateLimits++;
                rotateRpc();
                if (consecutiveRateLimits >= RPC_URLS.length) {
                    log.warn('All RPCs rate-limited. Pausing 120s...');
                    await sleep(120000);
                    consecutiveRateLimits = 0;
                } else {
                    await sleep(10000);
                }
                continue;
            }

            if (scan.historyUnavailable) {
                // The RPC no longer has logs this old. Skipping is only safe when no
                // deposit can have reached any address (never transacted, holds nothing).
                if (await addressesProvenEmpty(addressMap)) {
                    const resumeAt = currentBlock - 10;
                    log.warn(`Blocks ${fromBlock}-${currentBlock} are older than the RPC keeps (${scan.error}). All ${addressMap.size} deposit address(es) have never transacted and hold 0 BNB/TRDC/USDT, so no deposit can have arrived. Resuming from block ${resumeAt}.`);
                    await setConfig('deposit_monitor_gap_skipped', JSON.stringify({ from: fromBlock, to: resumeAt, at: new Date().toISOString(), reason: 'history unavailable; addresses proven empty' }));
                    lastBlock = resumeAt;
                    await setConfig('deposit_monitor_last_block', String(lastBlock));
                } else {
                    reportError(`Blocks from ${fromBlock} are older than the RPC keeps, and some deposit address has received funds. Set RPC_URL to an archive BSC RPC in .env to backfill. Not skipping. (${scan.error})`);
                    await sleep(60000);
                }
                continue;
            }

            if (scan.failed) {
                // Retry the same range on another RPC; never advance past an unscanned range
                rotateRpc();
                await sleep(5000);
                continue;
            }

            consecutiveRateLimits = 0;
            lastBlock = toBlock;
            await setConfig('deposit_monitor_last_block', String(lastBlock));
            if (catchUp && currentBlock - lastBlock <= CATCH_UP_THRESHOLD) {
                log.info(`Catch-up complete at block ${lastBlock}.`);
            }

            const credited = await processConfirmations(provider);
            if (credited > 0) log.info(`Credited ${credited} deposit(s)`);

            if (sweepingEnabled) {
                const swept = await processSweeps(hotWallet, provider, seedPhrase);
                if (swept > 0) log.info(`Swept ${swept} deposit(s) to hot wallet`);
            } else if (cycleCount % 20 === 0) {
                const bnb = parseFloat(ethers.formatEther(await provider.getBalance(hotWallet.address)));
                if (bnb >= 0.01) {
                    log.info(`Hot wallet funded (${bnb} BNB). Sweeping enabled.`);
                    sweepingEnabled = true;
                } else if (cycleCount % 240 === 0) {
                    log.warn(`Sweeping disabled — ${bnb} BNB. Fund ${hotWallet.address} with 0.05+ BNB.`);
                }
            }

            // In catch-up, go straight to the next window
            if (catchUp) continue;

        } catch (e) {
            reportError(`Cycle error: ${e.message}`);
            if (e.badBlockNumber) rotateRpc();
        }

        await sleep(POLL_INTERVAL);
    }
}

/**
 * Current block from the RPC, validated. Throws (instead of returning NaN) when
 * the RPC answers with an error or garbage.
 */
async function getBlockNumber() {
    const res = await fetch(currentRpcUrl(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'eth_blockNumber', params: [] }),
        signal: AbortSignal.timeout(8000),
    });
    const json = await res.json();
    const n = typeof json.result === 'string' ? parseInt(json.result, 16) : NaN;
    if (!Number.isSafeInteger(n) || n <= 0) {
        const err = new Error(`RPC ${currentRpcUrl()} returned no valid block number (${JSON.stringify(json.error || json.result || json).slice(0, 120)})`);
        err.badBlockNumber = true;
        throw err;
    }
    return n;
}

// Log a repeated error once, then at most a summary every 5 minutes
let lastErr = { msg: null, at: 0, repeats: 0 };
function reportError(msg) {
    const now = Date.now();
    if (msg === lastErr.msg && now - lastErr.at < 5 * 60 * 1000) {
        lastErr.repeats++;
        return;
    }
    if (lastErr.repeats > 0) log.error(`(previous error repeated ${lastErr.repeats} more time(s))`);
    log.error(msg);
    lastErr = { msg, at: now, repeats: 0 };
}

async function standbyLoop() {
    while (true) {
        await sleep(60000);
        try {
            const enabled = await getConfig('deposit_enabled', '0');
            if (enabled === '1') { log.info('Deposits enabled! Exiting standby.'); return; }
        } catch (e) {
            log.error('Standby check failed', { error: e.message });
        }
    }
}

process.on('SIGINT', async () => { log.info('Shutting down...'); await closeDb(); process.exit(0); });
process.on('SIGTERM', async () => { log.info('Shutting down...'); await closeDb(); process.exit(0); });
process.on('unhandledRejection', (reason) => { log.error('Unhandled rejection', { error: String(reason) }); });

main().catch(e => { log.error('Fatal error', { error: e.message, stack: e.stack }); process.exit(1); });
