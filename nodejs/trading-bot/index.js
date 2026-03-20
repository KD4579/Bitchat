'use strict';

require('dotenv').config({ path: __dirname + '/.env' });

const { ethers } = require('ethers');
const log = require('./logger');
const { loadBotConfig, saveBotStat, closeDb, CONTRACTS } = require('./config');
const { runGridTrading, runArbitrage, dailyPnl, nextDirection, resetDailyPnlIfNeeded } = require('./trader');
const { checkPoolHealth } = require('./monitor');
const { getPoolPrice, getBnbPriceUsd } = require('./prices');

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

    // ── Launch arbitrage price monitor (runs independently) ──
    const mode = cfg.bot_mode;
    if (mode === 'arbitrage' || mode === 'both') {
        startArbMonitor(wallet, provider, cfg);
    }

    // ── Main trading loop (grid trading on slow cooldown) ──
    log.info(`Starting trading loop (cooldown: ${cfg.bot_cooldown_seconds}s)`);

    let cycleCount = 0;
    const CONFIG_RELOAD_CYCLES = 10; // Reload config every N cycles

    while (true) {
        let cooldown = randomizeCooldown(parseInt(cfg.bot_cooldown_seconds));
        try {
            cycleCount++;
            resetDailyPnlIfNeeded();

            // Reload config periodically
            if (cycleCount % CONFIG_RELOAD_CYCLES === 0) {
                cfg = await loadBotConfig();
                // Update arb monitor config reference
                arbShared.cfg = cfg;

                if (cfg.bot_enabled !== '1') {
                    log.warn('Bot was DISABLED via admin panel. Entering standby...');
                    arbShared.paused = true;
                    await standbyLoop();
                    cfg = await loadBotConfig();
                    arbShared.cfg = cfg;
                    if (cfg.bot_enabled !== '1') continue;
                    arbShared.paused = false;
                    log.info('Bot re-enabled! Resuming trading...');
                    // Restart arb monitor if mode includes arbitrage
                    const newMode = cfg.bot_mode;
                    if ((newMode === 'arbitrage' || newMode === 'both') && !arbShared.running) {
                        startArbMonitor(wallet, provider, cfg);
                    }
                }
            }

            // Check gas price
            const feeData = await provider.getFeeData();
            const gasGwei = parseFloat(ethers.formatUnits(feeData.gasPrice || 0n, 'gwei'));
            const maxGas = parseFloat(cfg.bot_max_gas_gwei);

            if (gasGwei > maxGas) {
                const gasCooldown = randomizeCooldown(parseInt(cfg.bot_cooldown_seconds));
                log.warn(`Gas ${gasGwei.toFixed(1)} gwei > max ${maxGas} gwei. Skipping cycle. Retry in ${(gasCooldown/60).toFixed(0)}min.`);
                await sleep(gasCooldown * 1000);
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

            // Execute grid trading on slow cooldown (arbitrage runs independently via monitor)
            const currentMode = cfg.bot_mode;

            if (currentMode === 'market_making' || currentMode === 'both') {
                log.info(`── Cycle ${cycleCount}: Grid Trading ──`);
                await runGridTrading(wallet, provider, cfg);
            }

            // Save stats to database
            cooldown = randomizeCooldown(parseInt(cfg.bot_cooldown_seconds));
            const nextCycleAt = new Date(Date.now() + cooldown * 1000).toISOString();
            await saveBotStat('bot_daily_pnl', dailyPnl().toFixed(4));
            await saveBotStat('bot_last_cycle', new Date().toISOString());
            await saveBotStat('bot_next_cycle', nextCycleAt);
            await saveBotStat('bot_cycle_count', cycleCount);
            await saveBotStat('bot_next_direction', nextDirection());
            await saveBotStat('bot_arb_monitor_status', arbShared.running ? 'active' : 'stopped');

            log.info(`Cycle ${cycleCount} done. P&L: $${dailyPnl().toFixed(2)}. Next grid: ${nextDirection()} in ${(cooldown/60).toFixed(0)}min at ${nextCycleAt.slice(11,19)} UTC`);

        } catch (e) {
            log.error(`Cycle ${cycleCount} error: ${e.message}`, { stack: e.stack?.split('\n').slice(0, 3) });
        }

        await sleep(cooldown * 1000);
    }
}

// ── Arbitrage Price Monitor ─────────────────────────────
// Runs as a separate async loop, polling pool prices every N seconds.
// When a profitable spread is detected, it executes arbitrage immediately.

const arbShared = {
    cfg: null,
    running: false,
    paused: false,
    executing: false,  // Lock to prevent overlapping arb executions
    lastArbTime: 0,    // Timestamp of last arb trade
    pollCount: 0,      // Total price checks
    arbCount: 0,       // Successful arb executions
};

async function startArbMonitor(wallet, provider, cfg) {
    if (arbShared.running) {
        log.info('Arb monitor already running, skipping duplicate start.');
        return;
    }

    arbShared.cfg = cfg;
    arbShared.running = true;
    arbShared.paused = false;

    const DEC = 18;
    const pollInterval = parseInt(cfg.bot_arb_poll_seconds || 15) * 1000; // Default 15s
    const minCooldown = parseInt(cfg.bot_arb_cooldown || 60) * 1000;     // Min 60s between arb trades

    log.info('========================================');
    log.info(`Arb monitor STARTED — polling every ${pollInterval/1000}s`);
    log.info(`Min arb profit: ${cfg.bot_min_arb_profit}%, cooldown between arbs: ${minCooldown/1000}s`);
    log.info('========================================');

    while (arbShared.running) {
        try {
            // Respect pause state (bot disabled)
            if (arbShared.paused) {
                await sleep(5000);
                continue;
            }

            // Use latest config
            const c = arbShared.cfg;
            const currentMode = c.bot_mode;

            // Stop if mode no longer includes arbitrage
            if (currentMode !== 'arbitrage' && currentMode !== 'both') {
                log.info('Arb monitor stopping — mode changed to market_making only.');
                arbShared.running = false;
                break;
            }

            if (c.bot_enabled !== '1') {
                await sleep(5000);
                continue;
            }

            arbShared.pollCount++;

            // Log every poll for first 10, then every 20th
            if (arbShared.pollCount <= 10 || arbShared.pollCount % 20 === 0) {
                log.info(`[ArbMon] Poll #${arbShared.pollCount} — checking prices...`);
            }

            // Check daily loss limit
            const lossLimit = parseFloat(c.bot_daily_loss_limit);
            if (dailyPnl() < -lossLimit) {
                if (arbShared.pollCount % 20 === 0) {
                    log.warn(`[ArbMon] Daily loss limit hit ($${Math.abs(dailyPnl()).toFixed(2)}/$${lossLimit}). Monitoring paused.`);
                }
                await sleep(pollInterval * 4); // Slow down when loss limit hit
                continue;
            }

            // Fetch prices from both pools
            const usdtPool = c.bot_pool_trdc_usdt;
            const wbnbPool = c.bot_pool_trdc_wbnb;

            const [priceUsdt, priceWbnb] = await Promise.all([
                getPoolPrice(provider, usdtPool, DEC, DEC),
                getPoolPrice(provider, wbnbPool, DEC, DEC),
            ]);

            if (priceUsdt <= 0 || priceWbnb <= 0) {
                await sleep(pollInterval);
                continue;
            }

            // Get independent BNB/USD price from WBNB/USDT pool
            const bnbPriceUsd = await getBnbPriceUsd(provider);
            if (!isFinite(bnbPriceUsd) || bnbPriceUsd <= 0) {
                await sleep(pollInterval);
                continue;
            }

            // Log BNB price for first 5 polls to verify correctness
            if (arbShared.pollCount <= 5) {
                log.info(`[ArbMon] BNB/USD: $${bnbPriceUsd.toFixed(2)}, TRDC/WBNB raw: ${priceWbnb.toFixed(14)}`);
            }

            // Convert WBNB-pool TRDC price to USD using independent BNB price
            const trdcPriceViaWbnb = priceWbnb * bnbPriceUsd;
            const priceDiff = Math.abs(priceUsdt - trdcPriceViaWbnb);
            const minPrice = Math.min(priceUsdt, trdcPriceViaWbnb);
            const priceDiffPct = minPrice > 0 ? priceDiff / minPrice : 0;
            const minProfit = parseFloat(c.bot_min_arb_profit) / 100;

            // Log price check: every poll for first 10, then every 20th
            if (arbShared.pollCount <= 10 || arbShared.pollCount % 20 === 0) {
                log.info(`[ArbMon] Poll #${arbShared.pollCount}: USDT=$${priceUsdt.toFixed(10)}, viaWBNB=$${trdcPriceViaWbnb.toFixed(10)}, spread=${(priceDiffPct*100).toFixed(3)}% (threshold: ${(minProfit*100).toFixed(1)}%)`);
            }

            // Check if spread exceeds threshold
            if (priceDiffPct >= minProfit) {
                // Check cooldown between arb trades
                const now = Date.now();
                const timeSinceLastArb = now - arbShared.lastArbTime;
                if (timeSinceLastArb < minCooldown) {
                    log.info(`[ArbMon] Spread ${(priceDiffPct*100).toFixed(3)}% detected but cooling down (${((minCooldown - timeSinceLastArb)/1000).toFixed(0)}s left).`);
                    await sleep(pollInterval);
                    continue;
                }

                // Prevent overlapping executions
                if (arbShared.executing) {
                    log.info(`[ArbMon] Spread detected but arb already executing. Waiting...`);
                    await sleep(pollInterval);
                    continue;
                }

                // Check gas before executing
                try {
                    const feeData = await provider.getFeeData();
                    const gasGwei = parseFloat(ethers.formatUnits(feeData.gasPrice || 0n, 'gwei'));
                    const maxGas = parseFloat(c.bot_max_gas_gwei);
                    if (gasGwei > maxGas) {
                        log.warn(`[ArbMon] Spread ${(priceDiffPct*100).toFixed(3)}% but gas too high (${gasGwei.toFixed(1)} > ${maxGas} gwei). Skipping.`);
                        await sleep(pollInterval);
                        continue;
                    }
                } catch (e) {
                    log.warn(`[ArbMon] Gas check failed: ${e.message}`);
                    await sleep(pollInterval);
                    continue;
                }

                // EXECUTE ARBITRAGE
                log.info(`[ArbMon] ⚡ SPREAD DETECTED: ${(priceDiffPct*100).toFixed(3)}% >= ${(minProfit*100).toFixed(1)}% — executing arb NOW`);
                arbShared.executing = true;

                try {
                    resetDailyPnlIfNeeded();
                    await runArbitrage(wallet, provider, c);
                    arbShared.arbCount++;
                    arbShared.lastArbTime = Date.now();

                    // Save stats after arb
                    await saveBotStat('bot_daily_pnl', dailyPnl().toFixed(4));
                    await saveBotStat('bot_last_arb', new Date().toISOString());
                    await saveBotStat('bot_arb_count', arbShared.arbCount);

                    log.info(`[ArbMon] Arb #${arbShared.arbCount} complete. Daily P&L: $${dailyPnl().toFixed(4)}`);
                } catch (e) {
                    log.error(`[ArbMon] Arb execution failed: ${e.message}`);
                } finally {
                    arbShared.executing = false;
                }
            }

        } catch (e) {
            log.error(`[ArbMon] Monitor error: ${e.message}`);
        }

        await sleep(pollInterval);
    }

    log.info('Arb monitor stopped.');
    arbShared.running = false;
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

/**
 * Randomize cooldown between 1x and 2x the configured value.
 * E.g. 3600s config → random delay between 3600s (60min) and 7200s (120min).
 */
function randomizeCooldown(baseSec) {
    const min = baseSec;
    const max = baseSec * 2;
    return Math.floor(min + Math.random() * (max - min));
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
