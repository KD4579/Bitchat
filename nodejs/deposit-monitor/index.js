'use strict';

require('dotenv').config({ path: __dirname + '/.env' });

const { ethers } = require('ethers');
const log = require('./logger');
const { getConfig, setConfig, closeDb } = require('./config');
const { loadAllAddresses } = require('./address-manager');
const { scanBlocks, processConfirmations, setRpcUrl } = require('./monitor');
const { processSweeps } = require('./sweeper');

const POLL_INTERVAL = 15000; // 15 seconds
const MAX_BLOCKS_PER_SCAN = 50;

// Multiple public BSC RPC endpoints — rotated on rate-limit errors
const RPC_URLS = (process.env.RPC_URL ? [process.env.RPC_URL] : []).concat([
    'https://bsc-dataseed1.binance.org',
    'https://bsc-dataseed2.binance.org',
    'https://bsc-dataseed3.binance.org',
    'https://bsc-dataseed4.binance.org',
    'https://bsc-dataseed1.defibit.io',
    'https://bsc-dataseed2.defibit.io',
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

    // Initialize last scanned block
    let lastBlock = parseInt(await getConfig('deposit_monitor_last_block', '0'));
    if (lastBlock === 0) {
        const res = await fetch(currentRpcUrl(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'eth_blockNumber', params: [] }),
        });
        const json = await res.json();
        lastBlock = parseInt(json.result, 16) - 10;
        await setConfig('deposit_monitor_last_block', lastBlock.toString());
        log.info(`Initialized last block to ${lastBlock}`);
    }

    log.info(`Starting deposit monitor loop (poll every ${POLL_INTERVAL / 1000}s)`);
    let cycleCount = 0;
    let consecutiveRateLimits = 0;

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

            // Get current block via direct fetch
            const blkRes = await fetch(currentRpcUrl(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'eth_blockNumber', params: [] }),
                signal: AbortSignal.timeout(8000),
            });
            const blkJson = await blkRes.json();
            const currentBlock = parseInt(blkJson.result, 16);

            const fromBlock = lastBlock + 1;
            if (fromBlock > currentBlock) {
                await sleep(POLL_INTERVAL);
                continue;
            }

            const toBlock = Math.min(fromBlock + MAX_BLOCKS_PER_SCAN - 1, currentBlock);

            if (cycleCount % 10 === 0) {
                log.info(`Scanning blocks ${fromBlock}-${toBlock} (${addressMap.size} addresses, RPC: ${currentRpcUrl()})`);
            }

            const { found, rateLimited } = await scanBlocks(provider, addressMap, fromBlock, toBlock);

            if (found > 0) log.info(`Found ${found} new deposit(s)`);

            if (rateLimited) {
                consecutiveRateLimits++;
                rotateRpc();
                if (consecutiveRateLimits >= RPC_URLS.length) {
                    log.warn('All RPCs rate-limited. Pausing 60s...');
                    await sleep(60000);
                    consecutiveRateLimits = 0;
                } else {
                    await sleep(5000);
                }
                continue;
            }

            consecutiveRateLimits = 0;
            lastBlock = toBlock;
            await setConfig('deposit_monitor_last_block', lastBlock.toString());

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
                } else {
                    log.warn(`Sweeping disabled — ${bnb} BNB. Fund ${hotWallet.address} with 0.05+ BNB.`);
                }
            }

        } catch (e) {
            log.error(`Cycle ${cycleCount} error: ${e.message}`);
        }

        await sleep(POLL_INTERVAL);
    }
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
