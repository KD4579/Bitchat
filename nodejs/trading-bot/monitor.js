'use strict';

const log = require('./logger');
const { getPoolLiquidity, estimatePoolTVL } = require('./prices');

// Track previous values for alert comparison
let prevLiquidity = {};
let prevTvl = {};

/**
 * Monitor LP liquidity and TVL for sudden drops.
 * Logs warnings when thresholds are breached.
 */
async function checkPoolHealth(provider, cfg, trdcPriceUsd) {
    const pools = [
        { name: 'TRDC/USDT', address: cfg.bot_pool_trdc_usdt },
        { name: 'TRDC/WBNB', address: cfg.bot_pool_trdc_wbnb },
    ];

    const lpExitThreshold = parseFloat(cfg.bot_lp_exit_alert) / 100;
    const tvlDropThreshold = parseFloat(cfg.bot_tvl_drop_alert) / 100;

    for (const pool of pools) {
        try {
            // Check liquidity
            const liquidity = await getPoolLiquidity(provider, pool.address);
            const liqNum = Number(liquidity);

            if (prevLiquidity[pool.name]) {
                const drop = (prevLiquidity[pool.name] - liqNum) / prevLiquidity[pool.name];
                if (drop >= lpExitThreshold) {
                    log.warn(`LP EXIT ALERT: ${pool.name} liquidity dropped ${(drop * 100).toFixed(1)}%!`, {
                        previous: prevLiquidity[pool.name],
                        current: liqNum,
                    });
                }
            }
            prevLiquidity[pool.name] = liqNum;

            // Check TVL
            const tvl = await estimatePoolTVL(provider, pool.address, trdcPriceUsd);

            if (prevTvl[pool.name]) {
                const drop = (prevTvl[pool.name] - tvl) / prevTvl[pool.name];
                if (drop >= tvlDropThreshold) {
                    log.warn(`TVL DROP ALERT: ${pool.name} TVL dropped ${(drop * 100).toFixed(1)}%!`, {
                        previous: prevTvl[pool.name].toFixed(2),
                        current: tvl.toFixed(2),
                    });
                }
            }
            prevTvl[pool.name] = tvl;

            log.info(`Pool health: ${pool.name}`, {
                liquidity: liqNum.toLocaleString(),
                tvl: `$${tvl.toFixed(2)}`,
            });
        } catch (e) {
            log.error(`Failed to check ${pool.name}`, { error: e.message });
        }
    }
}

module.exports = { checkPoolHealth };
