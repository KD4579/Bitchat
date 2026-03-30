'use strict';

require('dotenv').config({ path: __dirname + '/.env' });

const { ethers } = require('ethers');
const log = require('./logger');
const { getConfig, setConfig, closeDb } = require('./config');
const { loadAllAddresses } = require('./address-manager');
const { scanBlocks, processConfirmations } = require('./monitor');
const { processSweeps } = require('./sweeper');

const POLL_INTERVAL = 15000; // 15 seconds
const MAX_BLOCKS_PER_SCAN = 50; // Reduced to avoid rate limits

// Multiple public BSC RPC endpoints for rotation
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

/**
 * Create a JsonRpcProvider with batching disabled.
 * batchMaxCount: 1 sends each request individually — avoids rate-limit errors
 * on batch endpoints.
 */
function makeProvider(urlIndex) {
    const url = RPC_URLS[urlIndex % RPC_URLS.length];
    return new ethers.JsonRpcProvider(url, 56, {
        batchMaxCount: 1,
        staticNetwork: true,
    });
}

/**
 * Rotate to the next RPC provider. Called on rate-limit or network errors.
 */
function nextProvider() {
    rpcIndex = (rpcIndex + 1) % RPC_URLS.length;
    const url = RPC_URLS[rpcIndex];
    log.info(`Rotating RPC → ${url}`);
    return makeProvider(rpcIndex);
}

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

async function main() {
    log.info('========================================');
    log.info('BSC Deposit Monitor starting...');
    log.info('========================================');

    // Validate keys
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

    // Check if deposits are enabled
    const enabled = await getConfig('deposit_enabled', '0');
    if (enabled !== '1') {
        log.warn('Deposits are DISABLED in admin panel.');
        log.warn('Entering standby mode — will check config every 60s...');
        await standbyLoop();
    }

    // Connect to BSC with batching disabled
    let provider = makeProvider(rpcIndex);
    log.info(`RPC: ${RPC_URLS[rpcIndex]} (batching disabled, ${RPC_URLS.length} endpoints available)`);

    // Verify BSC mainnet
    const network = await provider.getNetwork();
    if (network.chainId !== 56n) {
        log.error(`Wrong chain! Expected BSC (56), got ${network.chainId}. Exiting.`);
        process.exit(1);
    }
    log.info('Connected to BSC Mainnet');

    const hotWallet = new ethers.Wallet(hotWalletKey, provider);
    log.info(`Hot Wallet: ${hotWallet.address}`);

    // Check hot wallet BNB balance
    const bnbBalance = ethers.formatEther(await provider.getBalance(hotWallet.address));
    log.info(`Hot wallet BNB balance: ${bnbBalance} BNB`);

    let sweepingEnabled = true;
    if (parseFloat(bnbBalance) < 0.01) {
        log.warn(`Hot wallet BNB too low for sweeping (${bnbBalance} BNB < 0.01). Monitoring and crediting will continue. Fund ${hotWallet.address} with at least 0.05 BNB to enable sweeping.`);
        sweepingEnabled = false;
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
        lastBlock = (await provider.getBlockNumber()) - 10;
        await setConfig('deposit_monitor_last_block', lastBlock.toString());
        log.info(`Initialized last block to ${lastBlock}`);
    }

    log.info(`Starting deposit monitor loop (poll every ${POLL_INTERVAL / 1000}s)`);
    let cycleCount = 0;
    let consecutiveErrors = 0;

    while (true) {
        try {
            cycleCount++;

            // Check enabled every 10 cycles
            if (cycleCount % 10 === 0) {
                const stillEnabled = await getConfig('deposit_enabled', '0');
                if (stillEnabled !== '1') {
                    log.warn('Deposits DISABLED via admin panel. Entering standby...');
                    await standbyLoop();
                    log.info('Deposits re-enabled! Resuming...');
                }
            }

            // Load monitored addresses
            const addressMap = await loadAllAddresses();
            if (addressMap.size === 0) {
                if (cycleCount % 20 === 0) log.info('No deposit addresses to monitor yet');
                await sleep(POLL_INTERVAL);
                continue;
            }

            // Get current block
            const currentBlock = await provider.getBlockNumber();
            const fromBlock = lastBlock + 1;

            if (fromBlock > currentBlock) {
                await sleep(POLL_INTERVAL);
                continue;
            }

            // Cap scan range
            const toBlock = Math.min(fromBlock + MAX_BLOCKS_PER_SCAN - 1, currentBlock);

            if (cycleCount % 20 === 0) {
                log.info(`Scanning blocks ${fromBlock}-${toBlock} (${addressMap.size} addresses monitored, RPC: ${RPC_URLS[rpcIndex]})`);
            }

            // Scan for deposits
            const { found, rateLimited } = await scanBlocks(provider, addressMap, fromBlock, toBlock);
            if (found > 0) {
                log.info(`Found ${found} new deposit(s) in blocks ${fromBlock}-${toBlock}`);
            }

            // Rotate RPC if rate limited
            if (rateLimited) {
                provider = nextProvider();
                consecutiveErrors++;
                if (consecutiveErrors >= RPC_URLS.length) {
                    log.warn('All RPCs rate-limited. Waiting 30s...');
                    await sleep(30000);
                    consecutiveErrors = 0;
                }
                await sleep(POLL_INTERVAL);
                continue;
            }

            consecutiveErrors = 0;

            // Update last scanned block
            lastBlock = toBlock;
            await setConfig('deposit_monitor_last_block', lastBlock.toString());

            // Process confirmations and credit
            const credited = await processConfirmations(provider);
            if (credited > 0) {
                log.info(`Credited ${credited} deposit(s)`);
            }

            // Process sweep queue (only when hot wallet has enough BNB for gas)
            if (sweepingEnabled) {
                const swept = await processSweeps(hotWallet, provider, seedPhrase);
                if (swept > 0) {
                    log.info(`Swept ${swept} deposit(s) to hot wallet`);
                }
            } else if (cycleCount % 20 === 0) {
                const currentBnb = parseFloat(ethers.formatEther(await provider.getBalance(hotWallet.address)));
                if (currentBnb >= 0.01) {
                    log.info(`Hot wallet funded (${currentBnb} BNB). Re-enabling sweeping.`);
                    sweepingEnabled = true;
                    hotWallet.connect(provider);
                } else {
                    log.warn(`Sweeping disabled — hot wallet: ${currentBnb} BNB. Fund ${hotWallet.address} with 0.05+ BNB.`);
                }
            }

        } catch (e) {
            log.error(`Cycle ${cycleCount} error: ${e.message}`);
            // On network errors, rotate provider
            if (e.code === 'BAD_DATA' || e.code === 'NETWORK_ERROR' || e.message.includes('rate limit')) {
                provider = nextProvider();
            }
        }

        await sleep(POLL_INTERVAL);
    }
}

async function standbyLoop() {
    while (true) {
        await sleep(60000);
        try {
            const enabled = await getConfig('deposit_enabled', '0');
            if (enabled === '1') {
                log.info('Deposits enabled! Exiting standby.');
                return;
            }
        } catch (e) {
            log.error('Standby config check failed', { error: e.message });
        }
    }
}

process.on('SIGINT', async () => {
    log.info('Received SIGINT. Shutting down...');
    await closeDb();
    process.exit(0);
});

process.on('SIGTERM', async () => {
    log.info('Received SIGTERM. Shutting down...');
    await closeDb();
    process.exit(0);
});

process.on('unhandledRejection', (reason) => {
    log.error('Unhandled rejection', { error: String(reason) });
});

main().catch(e => {
    log.error('Fatal error', { error: e.message, stack: e.stack });
    process.exit(1);
});
