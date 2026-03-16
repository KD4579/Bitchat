'use strict';

require('dotenv').config({ path: __dirname + '/.env' });

const { ethers } = require('ethers');
const log = require('./logger');
const { TRDC_CONTRACT, ERC20_ABI, getConfig, closeDb } = require('./config');
const { processWithdrawals } = require('./processor');

const POLL_INTERVAL = 30000; // 30 seconds

async function main() {
    log.info('========================================');
    log.info('TRDC Withdrawal Processor starting...');
    log.info('========================================');

    // Validate private key
    const privateKey = process.env.BOT_PRIVATE_KEY;
    if (!privateKey || privateKey === '0x_YOUR_PRIVATE_KEY_HERE') {
        log.error('BOT_PRIVATE_KEY not set in .env file. Exiting.');
        process.exit(1);
    }

    // Check if withdrawal is enabled
    const enabled = await getConfig('trdc_withdrawal_enabled', '0');
    if (enabled !== '1') {
        log.warn('Withdrawal is DISABLED in admin panel.');
        log.warn('Entering standby mode — will check config every 60s...');
        await standbyLoop();
    }

    // Connect to BSC
    const rpcUrl = process.env.RPC_URL || 'https://bsc-dataseed1.binance.org';
    const provider = new ethers.JsonRpcProvider(rpcUrl);
    const wallet = new ethers.Wallet(privateKey, provider);

    log.info(`Wallet: ${wallet.address}`);
    log.info(`RPC: ${rpcUrl}`);

    // Verify BSC
    const network = await provider.getNetwork();
    if (network.chainId !== 56n) {
        log.error(`Wrong chain! Expected BSC (56), got ${network.chainId}. Exiting.`);
        process.exit(1);
    }
    log.info('Connected to BSC Mainnet');

    // Check balances
    const bnbBalance = ethers.formatEther(await provider.getBalance(wallet.address));
    log.info(`BNB balance (gas): ${bnbBalance} BNB`);

    const trdcContract = new ethers.Contract(TRDC_CONTRACT, ERC20_ABI, provider);
    const decimals = await trdcContract.decimals();
    const trdcBalance = ethers.formatUnits(await trdcContract.balanceOf(wallet.address), decimals);
    log.info(`TRDC balance: ${trdcBalance} TRDC`);

    if (parseFloat(bnbBalance) < 0.005) {
        log.error('BNB balance too low for gas. Fund the wallet with at least 0.01 BNB. Exiting.');
        process.exit(1);
    }

    // Main processing loop
    log.info(`Starting withdrawal processing loop (poll every ${POLL_INTERVAL / 1000}s)`);

    let cycleCount = 0;

    while (true) {
        try {
            cycleCount++;

            // Reload enabled flag every 10 cycles
            if (cycleCount % 10 === 0) {
                const stillEnabled = await getConfig('trdc_withdrawal_enabled', '0');
                if (stillEnabled !== '1') {
                    log.warn('Withdrawal DISABLED via admin panel. Entering standby...');
                    await standbyLoop();
                    log.info('Withdrawal re-enabled! Resuming...');
                }
            }

            const processed = await processWithdrawals(wallet, provider);

            if (processed > 0) {
                log.info(`Cycle ${cycleCount}: processed ${processed} withdrawal(s)`);
            }

        } catch (e) {
            log.error(`Cycle ${cycleCount} error: ${e.message}`, { stack: e.stack?.split('\n').slice(0, 3) });
        }

        await sleep(POLL_INTERVAL);
    }
}

/**
 * Standby loop: polls config every 60s waiting for withdrawal to be enabled.
 */
async function standbyLoop() {
    while (true) {
        await sleep(60000);
        try {
            const enabled = await getConfig('trdc_withdrawal_enabled', '0');
            if (enabled === '1') {
                log.info('Withdrawal enabled! Exiting standby.');
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

// Graceful shutdown
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
