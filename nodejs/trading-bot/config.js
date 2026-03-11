'use strict';

const mysql = require('mysql2/promise');
const path = require('path');
const log = require('./logger');

// Known contract addresses
const CONTRACTS = {
    TRDC:           '0x39006641db2d9c3618523a1778974c0d7e98e39d',
    USDT:           '0x55d398326f99059fF775485246999027B3197955',
    WBNB:           '0xbb4CdB9CBd36B01bD1cBaEBF2De08d9173bc095c',
    PANCAKE_ROUTER: '0x13f4EA83D0bd40E75C8222255bc855a974568Dd4',  // PancakeSwap V3 SwapRouter
    PANCAKE_QUOTER: '0xB048Bbc1Ee6b733FFfCFb9e9CeF7375518e25997',  // PancakeSwap V3 QuoterV2
};

// Default bot config (matches admin panel defaults)
const DEFAULTS = {
    bot_enabled:           '0',
    bot_mode:              'both',
    bot_rpc_url:           'https://bsc-dataseed1.binance.org',
    bot_spread_percent:    '1.5',
    bot_grid_levels:       '8',
    bot_grid_spacing:      '2',
    bot_order_size_trdc:   '5000',
    bot_min_arb_profit:    '0.5',
    bot_arb_max_size:      '10000',
    bot_max_slippage:      '2',
    bot_daily_loss_limit:  '20',
    bot_cooldown_seconds:  '30',
    bot_max_trade_percent: '5',
    bot_min_tvl:           '100',
    bot_max_gas_gwei:      '5',
    bot_lp_exit_alert:     '50',
    bot_tvl_drop_alert:    '20',
    bot_pool_trdc_usdt:    '0x7b57fa13cca5093f5d724823d58503dfd02ff07c',
    bot_pool_trdc_wbnb:    '0x0b5e165fcb524fbb2d313f3d573d79372913788b',
    bot_pool_usdt_fee:     '100',
    bot_pool_wbnb_fee:     '2500',
};

let pool = null;

function getDbPool() {
    if (pool) return pool;

    // Read DB creds from env or fall back to nodejs/config.json
    let dbHost = process.env.DB_HOST;
    let dbUser = process.env.DB_USER;
    let dbPass = process.env.DB_PASS;
    let dbName = process.env.DB_NAME;

    if (!dbHost || !dbUser || !dbName) {
        try {
            const nodejsConfig = require(path.join(__dirname, '..', 'config.json'));
            dbHost = dbHost || nodejsConfig.sql_db_host;
            dbUser = dbUser || nodejsConfig.sql_db_user;
            dbPass = dbPass ?? nodejsConfig.sql_db_pass;
            dbName = dbName || nodejsConfig.sql_db_name;
        } catch (e) {
            log.error('Cannot read nodejs/config.json and DB env vars not set');
            process.exit(1);
        }
    }

    pool = mysql.createPool({
        host: dbHost,
        user: dbUser,
        password: dbPass,
        database: dbName,
        waitForConnections: true,
        connectionLimit: 3,
        queueLimit: 0,
    });

    return pool;
}

/**
 * Load all bot_* config keys from Wo_Config table.
 * Returns merged object with defaults filled in.
 */
async function loadBotConfig() {
    const db = getDbPool();
    const [rows] = await db.execute(
        "SELECT `name`, `value` FROM `Wo_Config` WHERE `name` LIKE 'bot_%'"
    );

    const cfg = { ...DEFAULTS };
    for (const row of rows) {
        cfg[row.name] = row.value;
    }

    return cfg;
}

/**
 * Save a bot stat to the database (for tracking daily P&L, etc.)
 */
async function saveBotStat(key, value) {
    const db = getDbPool();
    await db.execute(
        "INSERT INTO `Wo_Config` (`name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?",
        [key, String(value), String(value)]
    );
}

/**
 * Save a trade record to Wo_Bot_Trades.
 */
async function saveTrade(trade) {
    const db = getDbPool();
    await db.execute(
        `INSERT INTO Wo_Bot_Trades
         (strategy, direction, token_in, token_out, amount_in, amount_out,
          price_usd, trade_value_usd, gas_used, gas_cost_bnb, tx_hash, pnl_usd, daily_pnl_usd, pool_tvl)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
        [
            trade.strategy, trade.direction, trade.tokenIn, trade.tokenOut,
            trade.amountIn, trade.amountOut, trade.priceUsd, trade.tradeValueUsd,
            trade.gasUsed, trade.gasCostBnb, trade.txHash, trade.pnlUsd,
            trade.dailyPnlUsd, trade.poolTvl
        ]
    );
}

async function closeDb() {
    if (pool) {
        await pool.end();
        pool = null;
    }
}

module.exports = { CONTRACTS, DEFAULTS, loadBotConfig, saveBotStat, saveTrade, closeDb, getDbPool };
