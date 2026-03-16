'use strict';

const mysql = require('mysql2/promise');
const path = require('path');
const log = require('./logger');

// Token contracts on BSC
const TRDC_CONTRACT = '0x39006641db2d9c3618523a1778974c0d7e98e39d';
const USDT_CONTRACT = '0x55d398326f99059fF775485246999027B3197955';
const WBNB_CONTRACT = '0xbb4CdB9CBd36B01bD1cBaEBF2De08d9173bc095c';

// ERC-20 ABI for deposit detection and sweeping
const ERC20_ABI = [
    'function transfer(address to, uint256 amount) returns (bool)',
    'function balanceOf(address owner) view returns (uint256)',
    'function decimals() view returns (uint8)',
    'event Transfer(address indexed from, address indexed to, uint256 value)',
];

// Transfer event topic (keccak256 of Transfer(address,address,uint256))
const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

let pool = null;

function getDbPool() {
    if (pool) return pool;

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
        connectionLimit: 5,
        queueLimit: 0,
    });

    return pool;
}

async function getConfig(key, defaultValue) {
    const db = getDbPool();
    const [rows] = await db.execute(
        "SELECT `value` FROM `Wo_Config` WHERE `name` = ? LIMIT 1",
        [key]
    );
    return rows.length > 0 ? rows[0].value : defaultValue;
}

async function setConfig(key, value) {
    const db = getDbPool();
    await db.execute(
        "UPDATE `Wo_Config` SET `value` = ? WHERE `name` = ?",
        [value.toString(), key]
    );
}

async function closeDb() {
    if (pool) {
        await pool.end();
        pool = null;
    }
}

module.exports = {
    TRDC_CONTRACT, USDT_CONTRACT, WBNB_CONTRACT,
    ERC20_ABI, TRANSFER_TOPIC,
    getDbPool, getConfig, setConfig, closeDb,
};
