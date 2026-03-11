'use strict';

require('dotenv').config({ path: __dirname + '/.env' });

const { ethers } = require('ethers');
const log = require('./logger');
const { loadBotConfig, saveBotStat, closeDb, CONTRACTS } = require('./config');
const { runGridTrading, runArbitrage, dailyPnl, nextDirection, resetDailyPnlIfNeeded } = require('./trader');
const { checkPoolHealth } = require('./monitor');
const { getPoolPrice } = require('./prices');

// ── Startup ──────────────────────────────────────────────

async function main() {
    log.info('========================================');
    log.info('TRDC Trading Bot starting...');
    log.info('========================================');

    // Validate private key
    const privateKey = process.env.BOT_PRIVATE_KEY;
    if (!privateKey || privateKey === '0x_YOUR_PRIVATE_KEY_HERE') {
        log.error('BOT_PRIVATE_KEY not set in .env file. Exiting.');
        process.exit(1);
    }

    // Load config from database
    let cfg = await loadBotConfig();
    log.info('Config loaded from database');

    if (cfg.bot_enabled !== '1') {
        log.warn('Bot is DISABLED in admin panel. Enable it at /admin-cp/trading-bot');
        log.warn('Entering standby mode — will check config every 60s...');
        await standbyLoop();
        return;
    }

    // Connect to BSC
    const rpcUrl = process.env.RPC_URL || cfg.bot_rpc_url || 'https://bsc-dataseed1.binance.org';
    const provider = new ethers.JsonRpcProvider(rpcUrl);
    const wallet = new ethers.Wallet(privateKey, provider);

    log.info(`Wallet: ${wallet.address}`);
    log.info(`RPC: ${rpcUrl}`);
    log.info(`Mode: ${cfg.bot_mode}`);

    // Check chain
    const network = await provider.getNetwork();
    if (network.chainId !== 56n) {
        log.error(`Wrong chain! Expected BSC (56), got ${network.chainId}. Exiting.`);
        process.exit(1);
    }
    log.info('Connected to BSC Mainnet');

    // Check wallet BNB balance for gas
    const bnbBalance = ethers.formatEther(await provider.getBalance(wallet.address));
    log.info(`BNB balance (gas): ${bnbBalance} BNB`);
    if (parseFloat(bnbBalance) < 0.005) {
        log.error('BNB balance too low for gas. Fund the wallet with at least 0.01 BNB. Exiting.');
        process.exit(1);
    }

    // ── Main trading loop ──────────────────────────────
    log.info(`Starting trading loop (cooldown: ${cfg.bot_cooldown_seconds}s)`);

    let cycleCount = 0;
    const CONFIG_RELOAD_CYCLES = 10; // Reload config every N cycles

    while (true) {
        try {
            cycleCount++;
            resetDailyPnlIfNeeded();

            // Reload config periodically
            if (cycleCount % CONFIG_RELOAD_CYCLES === 0) {
                cfg = await loadBotConfig();
                if (cfg.bot_enabled !== '1') {
                    log.warn('Bot was DISABLED via admin panel. Entering standby...');
                    await standbyLoop();
                    cfg = await loadBotConfig();
                    if (cfg.bot_enabled !== '1') continue;
                    log.info('Bot re-enabled! Resuming trading...');
                }
            }

            // Check gas price
            const feeData = await provider.getFeeData();
            const gasGwei = parseFloat(ethers.formatUnits(feeData.gasPrice || 0n, 'gwei'));
            const maxGas = parseFloat(cfg.bot_max_gas_gwei);

            if (gasGwei > maxGas) {
                log.warn(`Gas ${gasGwei.toFixed(1)} gwei > max ${maxGas} gwei. Skipping cycle.`);
                await sleep(parseInt(cfg.bot_cooldown_seconds) * 1000);
                continue;
            }

            // Check daily loss limit
            const lossLimit = parseFloat(cfg.bot_daily_loss_limit);
            if (dailyPnl() < -lossLimit) {
                log.warn(`Daily loss $${Math.abs(dailyPnl()).toFixed(2)} exceeds limit $${lossLimit}. Pausing until tomorrow.`);
                await sleep(300000); // 5 min check
                continue;
            }

            // Get current TRDC price for monitoring
            const trdcPrice = await getPoolPrice(provider, cfg.bot_pool_trdc_usdt, 18, 18);

            // Run pool health check every 5 cycles
            if (cycleCount % 5 === 0) {
                await checkPoolHealth(provider, cfg, trdcPrice);
            }

            // Execute trading strategy
            const mode = cfg.bot_mode;

            if (mode === 'market_making' || mode === 'both') {
                log.info(`── Cycle ${cycleCount}: Grid Trading ──`);
                await runGridTrading(wallet, provider, cfg);
            }

            if (mode === 'arbitrage' || mode === 'both') {
                log.info(`── Cycle ${cycleCount}: Arbitrage ──`);
                await runArbitrage(wallet, provider, cfg);
            }

            // Save stats to database
            const cooldown = parseInt(cfg.bot_cooldown_seconds);
            const nextCycleAt = new Date(Date.now() + cooldown * 1000).toISOString();
            await saveBotStat('bot_daily_pnl', dailyPnl().toFixed(4));
            await saveBotStat('bot_last_cycle', new Date().toISOString());
            await saveBotStat('bot_next_cycle', nextCycleAt);
            await saveBotStat('bot_cycle_count', cycleCount);
            await saveBotStat('bot_next_direction', nextDirection());

            log.info(`Cycle ${cycleCount} done. P&L: $${dailyPnl().toFixed(2)}. Next: ${nextDirection()} at ${nextCycleAt.slice(11,19)} UTC`);

        } catch (e) {
            log.error(`Cycle ${cycleCount} error: ${e.message}`, { stack: e.stack?.split('\n').slice(0, 3) });
        }

        await sleep(parseInt(cfg.bot_cooldown_seconds) * 1000);
    }
}

/**
 * Standby loop: polls config every 60s waiting for bot to be enabled.
 */
async function standbyLoop() {
    while (true) {
        await sleep(60000);
        try {
            const cfg = await loadBotConfig();
            if (cfg.bot_enabled === '1') {
                log.info('Bot enabled! Exiting standby.');
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

// ── Graceful shutdown ──────────────────────────────────

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

// ── Run ──────────────────────────────────────────────

main().catch(e => {
    log.error('Fatal error', { error: e.message, stack: e.stack });
    process.exit(1);
});
