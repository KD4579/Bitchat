'use strict';

const mysql = require('mysql2/promise');
const path = require('path');
const log = require('./logger');

const TRDC_CONTRACT = '0x39006641db2d9c3618523a1778974c0d7e98e39d';

// Standard ERC-20 ABI (only transfer + balanceOf + decimals)
const ERC20_ABI = [
    'function transfer(address to, uint256 amount) returns (bool)',
    'function balanceOf(address owner) view returns (uint256)',
    'function decimals() view returns (uint8)',
];

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
        connectionLimit: 3,
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

async function closeDb() {
    if (pool) {
        await pool.end();
        pool = null;
    }
}

module.exports = { TRDC_CONTRACT, ERC20_ABI, getDbPool, getConfig, closeDb };
