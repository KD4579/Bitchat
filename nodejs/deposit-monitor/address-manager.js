'use strict';

const { ethers } = require('ethers');
const log = require('./logger');
const { getDbPool } = require('./config');

/**
 * Derive a BSC deposit address for a given user from HD seed.
 * Path: m/44'/60'/0'/0/{derivationIndex}
 */
function deriveAddress(seedPhrase, derivationIndex) {
    const hdNode = ethers.HDNodeWallet.fromPhrase(seedPhrase, '', "m/44'/60'/0'/0");
    const child = hdNode.deriveChild(derivationIndex);
    return {
        address: child.address,
        privateKey: child.privateKey,
    };
}

/**
 * Get or create a deposit address for a user.
 * Returns { address, derivation_index, created }.
 */
async function getOrCreateAddress(seedPhrase, userId) {
    const db = getDbPool();

    // Check if user already has an address
    const [existing] = await db.execute(
        'SELECT address, derivation_index FROM Wo_Deposit_Addresses WHERE user_id = ?',
        [userId]
    );

    if (existing.length > 0) {
        return { address: existing[0].address, derivation_index: existing[0].derivation_index, created: false };
    }

    // Get next derivation index
    const [maxRow] = await db.execute(
        'SELECT COALESCE(MAX(derivation_index), -1) AS max_idx FROM Wo_Deposit_Addresses'
    );
    const nextIndex = maxRow[0].max_idx + 1;

    // Derive address
    const { address } = deriveAddress(seedPhrase, nextIndex);
    const now = Math.floor(Date.now() / 1000);

    // Insert (handle race condition with IGNORE)
    const [result] = await db.execute(
        `INSERT IGNORE INTO Wo_Deposit_Addresses (user_id, address, derivation_index, created_at)
         VALUES (?, ?, ?, ?)`,
        [userId, address, nextIndex, now]
    );

    if (result.affectedRows === 0) {
        // Race condition — another process created it. Fetch again.
        const [retry] = await db.execute(
            'SELECT address, derivation_index FROM Wo_Deposit_Addresses WHERE user_id = ?',
            [userId]
        );
        return { address: retry[0].address, derivation_index: retry[0].derivation_index, created: false };
    }

    log.info(`Created deposit address for user ${userId}: ${address} (index ${nextIndex})`);
    return { address, derivation_index: nextIndex, created: true };
}

/**
 * Load all monitored deposit addresses into a Set for fast lookup.
 */
async function loadAllAddresses() {
    const db = getDbPool();
    const [rows] = await db.execute('SELECT address, user_id FROM Wo_Deposit_Addresses');
    const addressMap = new Map();
    for (const row of rows) {
        addressMap.set(row.address.toLowerCase(), row.user_id);
    }
    return addressMap;
}

/**
 * Get the wallet (private key) for a deposit address by derivation index.
 */
function getWalletForIndex(seedPhrase, derivationIndex, provider) {
    const { privateKey } = deriveAddress(seedPhrase, derivationIndex);
    return new ethers.Wallet(privateKey, provider);
}

module.exports = { deriveAddress, getOrCreateAddress, loadAllAddresses, getWalletForIndex };
