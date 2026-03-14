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
    const price = Number(sqrtPriceX96) ** 2 / (2 ** 192) * (10 ** (token0Decimals - token1Decimals));
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
 * Get BNB price in USD by comparing TRDC price across both pools.
 * BNB/USD = (TRDC-in-USDT) / (TRDC-in-WBNB)
 */
async function getBnbPriceUsd(provider, usdtPoolAddress, wbnbPoolAddress) {
    try {
        const priceUsdt = await getPoolPrice(provider, usdtPoolAddress, 18, 18);
        const priceWbnb = await getPoolPrice(provider, wbnbPoolAddress, 18, 18);
        if (priceUsdt > 0 && priceWbnb > 0) {
            const bnbPrice = priceUsdt / priceWbnb;
            if (isFinite(bnbPrice) && bnbPrice > 0) return bnbPrice;
        }
    } catch (e) { /* fall through */ }
    return 600; // Fallback estimate if pools unavailable
}

module.exports = { getPoolPrice, getPoolLiquidity, getBalances, estimatePoolTVL, getBnbPriceUsd };
