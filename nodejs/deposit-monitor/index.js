'use strict';

require('dotenv').config({ path: __dirname + '/.env' });

const { ethers } = require('ethers');
const log = require('./logger');
const { getConfig, setConfig, closeDb } = require('./config');
const { loadAllAddresses } = require('./address-manager');
const { scanBlocks, processConfirmations } = require('./monitor');
const { processSweeps } = require('./sweeper');

const POLL_INTERVAL = 15000; // 15 seconds
const MAX_BLOCKS_PER_SCAN = 100; // Don't scan more than 100 blocks at once

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

    // Connect to BSC
    const rpcUrl = process.env.RPC_URL || 'https://bsc-dataseed1.binance.org';
    const provider = new ethers.JsonRpcProvider(rpcUrl);
    const hotWallet = new ethers.Wallet(hotWalletKey, provider);

    log.info(`Hot Wallet: ${hotWallet.address}`);
    log.info(`RPC: ${rpcUrl}`);

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

    let sweepingEnabled = true;
    if (parseFloat(bnbBalance) < 0.01) {
        log.warn('Hot wallet BNB too low for sweeping (< 0.01 BNB). Monitoring and crediting deposits will continue, but sweeping to hot wallet is disabled until funded with at least 0.05 BNB.');
        sweepingEnabled = false;
    }

    // Validate HD seed by deriving first address
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
        lastBlock = await provider.getBlockNumber() - 10; // Start from 10 blocks ago
        await setConfig('deposit_monitor_last_block', lastBlock.toString());
        log.info(`Initialized last block to ${lastBlock}`);
    }

    // Main loop
    log.info(`Starting deposit monitor loop (poll every ${POLL_INTERVAL / 1000}s)`);
    let cycleCount = 0;

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
                log.info(`Scanning blocks ${fromBlock}-${toBlock} (${addressMap.size} addresses monitored)`);
            }

            // Scan for deposits
            const found = await scanBlocks(provider, addressMap, fromBlock, toBlock);
            if (found > 0) {
                log.info(`Found ${found} new deposit(s) in blocks ${fromBlock}-${toBlock}`);
            }

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
            } else {
                // Re-check BNB balance every 20 cycles and re-enable sweeping if funded
                if (cycleCount % 20 === 0) {
                    const currentBnb = parseFloat(ethers.formatEther(await provider.getBalance(hotWallet.address)));
                    if (currentBnb >= 0.01) {
                        log.info(`Hot wallet funded (${currentBnb} BNB). Re-enabling sweeping.`);
                        sweepingEnabled = true;
                    } else {
                        log.warn(`Sweeping disabled — hot wallet BNB: ${currentBnb}. Fund ${hotWallet.address} with at least 0.05 BNB.`);
                    }
                }
            }

        } catch (e) {
            log.error(`Cycle ${cycleCount} error: ${e.message}`, { stack: e.stack?.split('\n').slice(0, 3) });
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

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
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
