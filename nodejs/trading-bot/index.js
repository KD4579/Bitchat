'use strict';

require('dotenv').config({ path: __dirname + '/.env' });

const { ethers } = require('ethers');
const log = require('./logger');
const { loadBotConfig, saveBotStat, closeDb, CONTRACTS } = require('./config');
const { runGridTrading, runArbitrage, restoreDailyPnl, dailyPnl, dailyPnlDate, nextDirection, resetDailyPnlIfNeeded } = require('./trader');
const { checkPoolHealth } = require('./monitor');
const { getPoolPrice } = require('./prices');

let heartbeatTimer = null;
let shuttingDown = false;

// ── Startup ──────────────────────────────────────────────

async function main() {
    log.info('========================================');
    log.info('TRDC Trading Bot starting...');
    log.info('========================================');

    await saveBotStat('bot_runtime_heartbeat', '0');

    // Validate private key
    const privateKey = process.env.BOT_PRIVATE_KEY;
    if (!privateKey || privateKey === '0x_YOUR_PRIVATE_KEY_HERE') {
        log.error('BOT_PRIVATE_KEY not set in .env file. Exiting.');
        process.exit(1);
    }

    // Load config from database
    let cfg = await loadBotConfig();
    log.info('Config loaded from database');
    restoreDailyPnl(cfg.bot_daily_pnl, cfg.bot_daily_pnl_date);

    if (cfg.bot_enabled !== '1') {
        log.warn('Bot is DISABLED in admin panel. Enable it at /admin-cp/trading-bot');
        log.warn('Entering standby mode — will check config every 60s...');
        await standbyLoop();
        // Exit cleanly so systemd (Restart=always) starts a fresh, fully initialised run.
        // Returning here used to leave the process alive but idle forever.
        log.info('Restarting to start trading with the current settings...');
        await shutdown('enabled after standby', 0);
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

    const configuredWallet = (cfg.bot_wallet_address || '').trim();
    if (configuredWallet && configuredWallet.toLowerCase() !== wallet.address.toLowerCase()) {
        throw new Error('Configured bot wallet address does not match the wallet derived from BOT_PRIVATE_KEY.');
    }
    if (!configuredWallet) {
        await saveBotStat('bot_wallet_address', wallet.address);
        cfg.bot_wallet_address = wallet.address;
    }
    await startRuntimeHeartbeat(wallet.address);

    // Check wallet BNB balance for gas. When it is too low, stay online (heartbeat
    // keeps running, dashboard shows "waiting for gas") and re-check every 5 minutes
    // instead of exiting and crash-looping under systemd.
    const MIN_GAS_BNB = 0.005;
    const GAS_RECHECK_MS = 5 * 60 * 1000;
    let bnbBalance = ethers.formatEther(await provider.getBalance(wallet.address));
    log.info(`BNB balance (gas): ${bnbBalance} BNB`);
    await saveBotStat('bot_gas_balance', bnbBalance);
    while (parseFloat(bnbBalance) < MIN_GAS_BNB) {
        await saveBotStat('bot_runtime_status', 'waiting_for_gas');
        log.warn(`BNB balance too low for gas (${bnbBalance} < ${MIN_GAS_BNB}). Fund ${wallet.address} with at least 0.01 BNB. Re-checking in 5 minutes...`);
        await sleep(GAS_RECHECK_MS);
        const latestCfg = await loadBotConfig();
        if (latestCfg.bot_enabled !== '1') {
            log.warn('Bot was disabled while waiting for gas.');
            await shutdown('disabled while waiting for gas', 0);
            return;
        }
        try {
            bnbBalance = ethers.formatEther(await provider.getBalance(wallet.address));
            await saveBotStat('bot_gas_balance', bnbBalance);
        } catch (e) {
            log.error('Gas balance re-check failed', { error: e.message });
        }
    }
    await saveBotStat('bot_runtime_status', 'running');
    log.info(`Gas OK (${bnbBalance} BNB). Starting strategies.`);

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
            await persistDailyPnl();
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
// runArbitrage quotes the round trip and trades only when it beats gas by the
// configured minimum. Every outcome is saved to bot_arb_status / bot_arb_detail.

const arbShared = {
    cfg: null,
    running: false,
    paused: false,
    lastArbTime: 0,    // Timestamp of last arb trade
    pollCount: 0,      // Total price checks
    arbCount: 0,       // Successful arb executions
    failCount: 0,      // Arb attempts where a swap failed
};

async function startArbMonitor(wallet, provider, cfg) {
    if (arbShared.running) {
        log.info('Arb monitor already running, skipping duplicate start.');
        return;
    }

    arbShared.cfg = cfg;
    arbShared.running = true;
    arbShared.paused = false;
    arbShared.arbCount = parseInt(cfg.bot_arb_count || 0) || 0;
    arbShared.failCount = parseInt(cfg.bot_arb_fail_count || 0) || 0;

    log.info('========================================');
    log.info(`Arb monitor STARTED — polling every ${parseInt(cfg.bot_arb_poll_seconds || 15)}s`);
    log.info(`Spread trigger ${cfg.bot_min_arb_profit}%, min pool $${cfg.bot_arb_min_pool_usd}, min net profit $${cfg.bot_arb_min_profit_usd}`);
    log.info('========================================');

    let lastStatus = null;

    let lastCfgLoad = Date.now();

    while (arbShared.running) {
        // Pick up admin-panel changes within a minute (the grid loop only reloads every few hours)
        if (Date.now() - lastCfgLoad >= 60000) {
            try {
                arbShared.cfg = await loadBotConfig();
            } catch (e) {
                log.error(`[ArbMon] Config reload failed: ${e.message}`);
            }
            lastCfgLoad = Date.now();
        }
        const c = arbShared.cfg;
        const pollInterval = Math.max(5, parseInt(c.bot_arb_poll_seconds || 15)) * 1000;
        const minCooldown = parseInt(c.bot_arb_cooldown || 60) * 1000;

        try {
            if (arbShared.paused || c.bot_enabled !== '1') {
                await sleep(5000);
                continue;
            }
            if (c.bot_mode !== 'arbitrage' && c.bot_mode !== 'both') {
                log.info('Arb monitor stopping — mode changed to market_making only.');
                await saveArbStatus('stopped', { message: 'Arbitrage is off (mode is market making only).' });
                break;
            }

            arbShared.pollCount++;
            let result;

            if (dailyPnl() < -parseFloat(c.bot_daily_loss_limit)) {
                result = { status: 'loss_limit', detail: { message: `Daily loss $${Math.abs(dailyPnl()).toFixed(2)} is over the $${c.bot_daily_loss_limit} limit; paused until 00:00 UTC.` } };
            } else if (Date.now() - arbShared.lastArbTime < minCooldown) {
                await sleep(pollInterval);
                continue;
            } else {
                const feeData = await provider.getFeeData();
                const gasPrice = feeData.gasPrice || 0n;
                const gasGwei = parseFloat(ethers.formatUnits(gasPrice, 'gwei'));
                const maxGas = parseFloat(c.bot_max_gas_gwei);
                if (gasGwei > maxGas) {
                    result = { status: 'gas_too_high', detail: { message: `Gas ${gasGwei.toFixed(2)} gwei is above the ${maxGas} gwei limit.` } };
                } else {
                    resetDailyPnlIfNeeded();
                    result = await runArbitrage(wallet, provider, c, gasPrice);
                }
            }

            const { status, detail } = result;
            await saveArbStatus(status, detail);

            if (status === 'executed') {
                arbShared.arbCount++;
                arbShared.lastArbTime = Date.now();
                await saveBotStat('bot_last_arb', new Date().toISOString());
                await saveBotStat('bot_arb_count', arbShared.arbCount);
                await persistDailyPnl();
                log.info(`[ArbMon] Arb #${arbShared.arbCount} executed. ${detail.message}`);
            } else if (status === 'leg1_failed' || status === 'leg2_failed') {
                arbShared.failCount++;
                arbShared.lastArbTime = Date.now(); // back off before retrying
                await saveBotStat('bot_arb_fail_count', arbShared.failCount);
                await persistDailyPnl();
                log.error(`[ArbMon] Arb FAILED (${status}): ${detail.message}`);
            } else if (status !== lastStatus || arbShared.pollCount % 40 === 0) {
                // Quiet states: log only when they change, plus a periodic reminder
                log.info(`[ArbMon] ${status}: ${detail.message || ''}`);
            }
            lastStatus = status;

            if (status === 'loss_limit') {
                await sleep(pollInterval * 4);
                continue;
            }
        } catch (e) {
            log.error(`[ArbMon] Monitor error: ${e.message}`);
        }

        await sleep(pollInterval);
    }

    log.info('Arb monitor stopped.');
    arbShared.running = false;
}

async function saveArbStatus(status, detail) {
    try {
        await saveBotStat('bot_arb_status', status);
        await saveBotStat('bot_arb_detail', JSON.stringify({ ...detail, checked_at: new Date().toISOString() }));
    } catch (e) {
        log.error('Could not save arb status', { error: e.message });
    }
}

async function persistDailyPnl() {
    await saveBotStat('bot_daily_pnl', dailyPnl().toFixed(4));
    await saveBotStat('bot_daily_pnl_date', dailyPnlDate());
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

async function startRuntimeHeartbeat(walletAddress) {
    await saveBotStat('bot_runtime_wallet_address', walletAddress);
    await saveBotStat('bot_runtime_heartbeat', Math.floor(Date.now() / 1000));
    heartbeatTimer = setInterval(async () => {
        try {
            await saveBotStat('bot_runtime_heartbeat', Math.floor(Date.now() / 1000));
        } catch (e) {
            log.error('Could not update bot runtime heartbeat', { error: e.message });
        }
    }, 30000);
    heartbeatTimer.unref();
}

async function markRuntimeOffline() {
    if (heartbeatTimer) {
        clearInterval(heartbeatTimer);
        heartbeatTimer = null;
    }
    try {
        await saveBotStat('bot_runtime_heartbeat', '0');
        await saveBotStat('bot_runtime_status', 'offline');
    } catch (e) {
        log.error('Could not clear bot runtime heartbeat', { error: e.message });
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

async function shutdown(signal, exitCode) {
    if (shuttingDown) return;
    shuttingDown = true;
    log.info(`Received ${signal}. Shutting down...`);
    await markRuntimeOffline();
    try {
        await closeDb();
    } catch (e) {
        log.error('Could not close bot database connection', { error: e.message });
        if (exitCode === 0) exitCode = 1;
    }
    process.exit(exitCode);
}

process.on('SIGINT', () => shutdown('SIGINT', 0));
process.on('SIGTERM', () => shutdown('SIGTERM', 0));

process.on('unhandledRejection', async (reason) => {
    log.error('Unhandled rejection', { error: String(reason) });
    await shutdown('unhandled rejection', 1);
});

// ── Run ──────────────────────────────────────────────

main().catch(e => {
    log.error('Fatal error', { error: e.message, stack: e.stack });
    shutdown('fatal error', 1);
});
