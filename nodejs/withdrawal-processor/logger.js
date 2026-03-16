'use strict';

const fs = require('fs');
const path = require('path');

const LOG_DIR = path.join(__dirname, 'logs');
if (!fs.existsSync(LOG_DIR)) fs.mkdirSync(LOG_DIR, { recursive: true });

function ts() {
    return new Date().toISOString();
}

function getLogFile() {
    const date = new Date().toISOString().slice(0, 10);
    return path.join(LOG_DIR, `withdrawal-${date}.log`);
}

function write(level, msg, data) {
    const line = `[${ts()}] [${level}] ${msg}` + (data ? ' ' + JSON.stringify(data) : '') + '\n';
    process.stdout.write(line);
    fs.appendFileSync(getLogFile(), line);
}

module.exports = {
    info:  (msg, data) => write('INFO',  msg, data),
    warn:  (msg, data) => write('WARN',  msg, data),
    error: (msg, data) => write('ERROR', msg, data),
};
