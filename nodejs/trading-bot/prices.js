'use strict';

const { ethers } = require('ethers');
const log = require('./logger');
const poolAbi = require('./abis/poolV3.json');

/**
 * Get the current price from a PancakeSwap V3 pool.
 * Returns price of token0 in terms of token1.
 */
async function getPoolPrice(provider, poolAddress, token0Decimals, token1Decimals) {
    const pool = new ethers.Contract(poolAddress, poolAbi, provider);
    const slot0 = await pool.slot0();
    const sqrtPriceX96 = slot0[0];

    // price = (sqrtPriceX96 / 2^96)^2 * 10^(token0Decimals - token1Decimals)
    // Use BigInt math to preserve precision for large sqrtPriceX96 values
    const sqrtPrice = BigInt(sqrtPriceX96.toString());
    const Q96 = 1n << 96n;
    const PRECISION = 10n ** 18n;
    const priceRaw = (sqrtPrice * sqrtPrice * PRECISION) / (Q96 * Q96);
    const decimalAdj = token0Decimals - token1Decimals;
    let price;
    if (decimalAdj >= 0) {
        price = Number(priceRaw * (10n ** BigInt(decimalAdj))) / Number(PRECISION);
    } else {
        price = Number(priceRaw) / Number(PRECISION) / (10 ** Math.abs(decimalAdj));
    }
    return price;
}

/**
 * Get pool liquidity (raw uint128).
 */
async function getPoolLiquidity(provider, poolAddress) {
    const pool = new ethers.Contract(poolAddress, poolAbi, provider);
    return await pool.liquidity();
}

/**
 * Get token balances for the bot wallet.
 */
async function getBalances(provider, wallet, tokens) {
    const erc20Abi = require('./abis/erc20.json');
    const balances = {};

    // BNB balance
    balances.BNB = ethers.formatEther(await provider.getBalance(wallet));

    for (const [symbol, address] of Object.entries(tokens)) {
        const contract = new ethers.Contract(address, erc20Abi, provider);
        const decimals = await contract.decimals();
        const raw = await contract.balanceOf(wallet);
        balances[symbol] = ethers.formatUnits(raw, decimals);
    }

    return balances;
}

/**
 * Estimate TVL of a pool in USD.
 * Uses token balances in the pool * their USD prices.
 */
async function estimatePoolTVL(provider, poolAddress, trdcPriceUsd) {
    const erc20Abi = require('./abis/erc20.json');
    const poolContract = new ethers.Contract(poolAddress, poolAbi, provider);

    const token0Addr = await poolContract.token0();
    const token1Addr = await poolContract.token1();

    const token0 = new ethers.Contract(token0Addr, erc20Abi, provider);
    const token1 = new ethers.Contract(token1Addr, erc20Abi, provider);

    const [dec0, dec1, bal0, bal1] = await Promise.all([
        token0.decimals(),
        token1.decimals(),
        token0.balanceOf(poolAddress),
        token1.balanceOf(poolAddress),
    ]);

    const amount0 = parseFloat(ethers.formatUnits(bal0, dec0));
    const amount1 = parseFloat(ethers.formatUnits(bal1, dec1));

    // Rough TVL: assume token0 or token1 is USDT (price=1) or TRDC (use trdcPriceUsd)
    // This is simplified — works for TRDC/USDT pool directly
    const USDT = '0x55d398326f99059ff775485246999027b3197955';

    if (token0Addr.toLowerCase() === USDT.toLowerCase()) {
        return amount0 * 2; // USDT side * 2
    } else if (token1Addr.toLowerCase() === USDT.toLowerCase()) {
        return amount1 * 2;
    } else {
        // TRDC/WBNB pool — use TRDC price
        return (amount0 * trdcPriceUsd + amount1 * trdcPriceUsd) || 0;
    }
}

/**
 * PancakeSwap V3 WBNB/USDT pool (0.01% fee tier) — high liquidity, reliable BNB price.
 */
const WBNB_USDT_POOL = '0x172fcd41e0913e95784454622d1c3724f546f849';

/**
 * Get BNB price in USD from the WBNB/USDT pool on PancakeSwap.
 * Falls back to CoinGecko API if on-chain price fails.
 */
async function getBnbPriceUsd(provider) {
    // Primary: read from WBNB/USDT pool (both 18 decimals)
    try {
        const price = await getPoolPrice(provider, WBNB_USDT_POOL, 18, 18);
        if (price > 0 && isFinite(price)) return price;
    } catch (e) { /* fall through */ }

    // Fallback: CoinGecko API
    try {
        const resp = await fetch('https://api.coingecko.com/api/v3/simple/price?ids=binancecoin&vs_currencies=usd');
        const data = await resp.json();
        if (data?.binancecoin?.usd > 0) return data.binancecoin.usd;
    } catch (e) {
        log.warn('CoinGecko BNB price failed, using last known');
    }
    return 600; // absolute last resort
}

module.exports = { getPoolPrice, getPoolLiquidity, getBalances, estimatePoolTVL, getBnbPriceUsd };
