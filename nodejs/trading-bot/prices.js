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

const USDT_ADDR = '0x55d398326f99059ff775485246999027b3197955';
const WBNB_ADDR = '0xbb4cdb9cbd36b01bd1cbaebf2de08d9173bc095c';

/**
 * Estimate TVL of a pool in USD: value of both token balances held by the pool.
 * USDT = $1, WBNB = bnbPriceUsd, anything else (TRDC) = trdcPriceUsd.
 * Returns 0 if a needed price is missing, so callers treat the pool as too thin.
 */
async function estimatePoolTVL(provider, poolAddress, trdcPriceUsd, bnbPriceUsd) {
    const erc20Abi = require('./abis/erc20.json');
    const poolContract = new ethers.Contract(poolAddress, poolAbi, provider);

    const [token0Addr, token1Addr] = await Promise.all([poolContract.token0(), poolContract.token1()]);
    const token0 = new ethers.Contract(token0Addr, erc20Abi, provider);
    const token1 = new ethers.Contract(token1Addr, erc20Abi, provider);

    const [dec0, dec1, bal0, bal1] = await Promise.all([
        token0.decimals(),
        token1.decimals(),
        token0.balanceOf(poolAddress),
        token1.balanceOf(poolAddress),
    ]);

    const priceOf = (addr) => {
        const a = addr.toLowerCase();
        if (a === USDT_ADDR) return 1;
        if (a === WBNB_ADDR) return bnbPriceUsd > 0 ? bnbPriceUsd : NaN;
        return trdcPriceUsd > 0 ? trdcPriceUsd : NaN;
    };

    const value = parseFloat(ethers.formatUnits(bal0, dec0)) * priceOf(token0Addr)
                + parseFloat(ethers.formatUnits(bal1, dec1)) * priceOf(token1Addr);
    return isFinite(value) ? value : 0;
}

/**
 * PancakeSwap V3 WBNB/USDT pool (0.01% fee tier) — high liquidity, reliable BNB price.
 */
const WBNB_USDT_POOL = '0x172fcd41e0913e95784454622d1c3724f546f849';

/**
 * Get BNB price in USD from the WBNB/USDT pool on PancakeSwap.
 * Falls back to CoinGecko API if on-chain price fails; returns 0 if both fail.
 */
async function getBnbPriceUsd(provider) {
    // Primary: read from WBNB/USDT pool (both 18 decimals)
    // Pool token0 = USDT, token1 = WBNB, so getPoolPrice returns USDT/WBNB (~0.0016)
    // We need WBNB/USDT (~600), so invert it
    try {
        const usdtPerWbnb = await getPoolPrice(provider, WBNB_USDT_POOL, 18, 18);
        if (usdtPerWbnb > 0 && isFinite(usdtPerWbnb)) {
            const bnbPrice = 1 / usdtPerWbnb;
            if (bnbPrice > 0 && isFinite(bnbPrice)) return bnbPrice;
        }
    } catch (e) { /* fall through */ }

    // Fallback: CoinGecko API
    try {
        const resp = await fetch('https://api.coingecko.com/api/v3/simple/price?ids=binancecoin&vs_currencies=usd');
        const data = await resp.json();
        if (data?.binancecoin?.usd > 0) return data.binancecoin.usd;
    } catch (e) {
        log.warn('CoinGecko BNB price failed', { error: e.message });
    }
    // No reliable price: return 0 so callers skip trading. A guessed price could
    // create a fake spread between the USDT and WBNB pools and trigger bad trades.
    return 0;
}

module.exports = { getPoolPrice, getPoolLiquidity, getBalances, estimatePoolTVL, getBnbPriceUsd };
