#!/usr/bin/env node
'use strict';

/**
 * CLI tool for PHP to derive a BSC deposit address for a given user.
 * Usage: node derive-address.js <userId>
 *
 * Reads HD_SEED_PHRASE from .env file.
 * Outputs JSON: { "address": "0x...", "derivation_index": N, "created": true/false }
 */

require('dotenv').config({ path: __dirname + '/.env' });

const { getOrCreateAddress, deriveAddress } = require('./address-manager');
const { closeDb } = require('./config');

async function main() {
    const userId = parseInt(process.argv[2]);

    if (!userId || isNaN(userId)) {
        console.error(JSON.stringify({ error: 'Usage: node derive-address.js <userId>' }));
        process.exit(1);
    }

    const seedPhrase = process.env.HD_SEED_PHRASE;
    if (!seedPhrase || seedPhrase.includes('replace with actual')) {
        console.error(JSON.stringify({ error: 'HD_SEED_PHRASE not set in .env' }));
        process.exit(1);
    }

    try {
        const result = await getOrCreateAddress(seedPhrase, userId);
        console.log(JSON.stringify(result));
    } catch (e) {
        console.error(JSON.stringify({ error: e.message }));
        process.exit(1);
    } finally {
        await closeDb();
    }
}

main();
