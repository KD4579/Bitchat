'use strict';

const { ethers } = require('ethers');
const log = require('./logger');
const { CONTRACTS, saveTrade } = require('./config');
const { getPoolPrice, getBalances, estimatePoolTVL, getBnbPriceUsd } = require('./prices');

const erc20Abi   = require('./abis/erc20.json');
const routerAbi  = require('./abis/routerV3.json');

// All BSC tokens are 18 decimals (TRDC, USDT, WBNB)
const DEC = 18;

/**
 * Randomize a value by ±25% to make trade sizes look natural on-chain.
 * E.g. base=2000 → returns random value between 1500 and 2500.
 */
function randomizeSize(base) {
    const variation = 0.25;
    const min = base * (1 - variation);
    const max = base * (1 + variation);
    return min + Math.random() * (max - min);
}

// Daily P&L tracker (resets at midnight UTC)
let dailyPnl = 0;
let dailyPnlDate = new Date().toISOString().slice(0, 10);

// Track recent trade directions for organic-looking patterns
let lastTradeDirection = null; // 'buy' or 'sell'
let recentDirections = []; // rolling window of last 6 trades

function resetDailyPnlIfNeeded() {
    const today = new Date().toISOString().slice(0, 10);
    if (today !== dailyPnlDate) {
        log.info(`Daily P&L reset. Yesterday: $${dailyPnl.toFixed(4)}`);
        dailyPnl = 0;
        dailyPnlDate = today;
        lastTradeDirection = null;
        recentDirections = [];
    }
}

/**
 * Ensure the router has enough allowance for a token.
 */
async function ensureApproval(wallet, tokenAddress, amount) {
    const token = new ethers.Contract(tokenAddress, erc20Abi, wallet);
    const allowance = await token.allowance(wallet.address, CONTRACTS.PANCAKE_ROUTER);

    if (allowance < amount) {
        log.info(`Approving ${tokenAddress} for router...`);
        const tx = await token.approve(CONTRACTS.PANCAKE_ROUTER, ethers.MaxUint256);
        await tx.wait();
        log.info(`Approval confirmed: ${tx.hash}`);
    }
}

/**
 * Execute a swap on PancakeSwap V3.
 * Returns { amountOut, txHash, gasUsed } or null on failure.
 */
async function executeSwap(wallet, tokenIn, tokenOut, fee, amountIn, maxSlippagePct) {
    const router = new ethers.Contract(CONTRACTS.PANCAKE_ROUTER, routerAbi, wallet);
    const quoterAbi = require('./abis/quoterV2.json');
    const quoter = new ethers.Contract(CONTRACTS.PANCAKE_QUOTER, quoterAbi, wallet.provider);

    // Get quote first
    let expectedOut;
    try {
        const quoteResult = await quoter.quoteExactInputSingle.staticCall({
            tokenIn,
            tokenOut,
            amountIn,
            fee,
            sqrtPriceLimitX96: 0n,
        });
        expectedOut = quoteResult[0];
    } catch (e) {
        log.error('Quote failed', { error: e.message });
        return null;
    }

    if (expectedOut === 0n) {
        log.warn('Quote returned 0 output. Skipping trade.');
        return null;
    }

    const slippageBps = BigInt(Math.floor(maxSlippagePct * 100));
    const minOut = expectedOut * (10000n - slippageBps) / 10000n;

    log.trade(`Swapping`, {
        tokenIn: tokenIn.slice(0, 10),
        tokenOut: tokenOut.slice(0, 10),
        amountIn: ethers.formatUnits(amountIn, DEC),
        expectedOut: ethers.formatUnits(expectedOut, DEC),
        minOut: ethers.formatUnits(minOut, DEC),
    });

    // Ensure approval
    await ensureApproval(wallet, tokenIn, amountIn);

    // Execute swap with explicit nonce to prevent conflicts on restart
    try {
        const nonce = await wallet.getNonce();
        const tx = await router.exactInputSingle({
            tokenIn,
            tokenOut,
            fee,
            recipient: wallet.address,
            amountIn,
            amountOutMinimum: minOut,
            sqrtPriceLimitX96: 0n,
        }, { nonce });

        const receipt = await tx.wait();
        const gasPrice = receipt.gasPrice || 3000000000n; // actual gas price from receipt
        log.trade(`Swap confirmed`, { txHash: tx.hash, gasUsed: receipt.gasUsed.toString(), gasPriceGwei: ethers.formatUnits(gasPrice, 'gwei') });

        return {
            amountOut: expectedOut,
            txHash: tx.hash,
            gasUsed: receipt.gasUsed,
            gasPrice,
        };
    } catch (e) {
        log.error('Swap transaction failed', { error: e.message });
        return null;
    }
}

/**
 * GRID MARKET MAKING
 *
 * Alternates between buying and selling TRDC to provide liquidity.
 * - Cycle N: buy TRDC with USDT
 * - Cycle N+1: sell TRDC for USDT
 * Spread between buy/sell price creates profit margin.
 */
async function runGridTrading(wallet, provider, cfg) {
    const poolAddress  = cfg.bot_pool_trdc_usdt;
    const fee          = parseInt(cfg.bot_pool_usdt_fee);
    const spreadPct    = parseFloat(cfg.bot_spread_percent) / 100;
    const orderSizeMin = parseFloat(cfg.bot_order_size_min || cfg.bot_order_size_trdc || 1500);
    const orderSizeMax = parseFloat(cfg.bot_order_size_max || cfg.bot_order_size_trdc || 2500);
    const maxSlippage  = parseFloat(cfg.bot_max_slippage);
    const maxTradePct  = parseFloat(cfg.bot_max_trade_percent) / 100;
    const minTvl       = parseFloat(cfg.bot_min_tvl);

    // Get current TRDC/USDT price (both 18 decimals)
    const price = await getPoolPrice(provider, poolAddress, DEC, DEC);
    if (price <= 0 || !isFinite(price)) {
        log.warn(`Invalid TRDC price: ${price}. Skipping grid trade.`);
        return;
    }
    log.info(`TRDC/USDT price: $${price.toFixed(10)}`);

    // Check TVL
    const tvl = await estimatePoolTVL(provider, poolAddress, price);
    log.info(`Pool TVL: $${tvl.toFixed(2)}`);
    if (tvl < minTvl) {
        log.warn(`TVL $${tvl.toFixed(2)} below minimum $${minTvl}. Skipping.`);
        return;
    }

    // Randomize order size between admin-configured min and max, then cap by pool TVL
    const randomizedSize = orderSizeMin + Math.random() * (orderSizeMax - orderSizeMin);
    const maxTradeUsd = tvl * maxTradePct;
    const maxTrdcByTvl = maxTradeUsd / price;
    const tradeSize = Math.min(randomizedSize, maxTrdcByTvl);
    const tradeValueUsd = tradeSize * price;

    log.info(`Trade size: ${tradeSize.toFixed(2)} TRDC (~$${tradeValueUsd.toFixed(4)})`);

    // Check balances
    const balances = await getBalances(provider, wallet.address, {
        TRDC: CONTRACTS.TRDC,
        USDT: CONTRACTS.USDT,
    });
    log.info(`Balances`, balances);

    const trdcBalance = parseFloat(balances.TRDC);
    const usdtBalance = parseFloat(balances.USDT);

    // Weighted random direction — looks organic on-chain
    // Admin controls max consecutive same-direction trades
    let direction;
    const trdcValueUsd = trdcBalance * price;
    const balanceRatio = usdtBalance > 0 ? trdcValueUsd / usdtBalance : 999;
    const maxConsecutive = parseInt(cfg.bot_max_consecutive || 3);

    // Count current consecutive streak
    let streak = 0;
    for (let i = recentDirections.length - 1; i >= 0; i--) {
        if (recentDirections[i] === lastTradeDirection) streak++;
        else break;
    }

    if (lastTradeDirection === null) {
        // First trade: balance-based
        direction = trdcValueUsd > usdtBalance ? 'sell' : 'buy';
        log.info(`First trade: ${direction} (TRDC=$${trdcValueUsd.toFixed(2)}, USDT=$${usdtBalance.toFixed(2)})`);
    } else if (streak >= maxConsecutive) {
        // Hard cap reached — force opposite direction
        direction = lastTradeDirection === 'buy' ? 'sell' : 'buy';
        log.info(`Direction: ${direction} (forced — hit max ${maxConsecutive} consecutive ${lastTradeDirection}s)`);
    } else {
        // Count recent buys/sells (last 6 trades)
        const recentBuys = recentDirections.filter(d => d === 'buy').length;
        const recentSells = recentDirections.filter(d => d === 'sell').length;

        // Base probability: 50/50
        let buyProb = 0.5;

        // Bias toward opposite of last trade (60% chance to flip)
        if (lastTradeDirection === 'buy') buyProb -= 0.10;
        else buyProb += 0.10;

        // Bias toward rebalancing if one side dominated recently
        if (recentBuys > recentSells + 1) buyProb -= 0.15;
        else if (recentSells > recentBuys + 1) buyProb += 0.15;

        // Bias toward rebalancing wallet (if too much TRDC, sell more)
        if (balanceRatio > 2.0) buyProb -= 0.10;
        else if (balanceRatio < 0.5) buyProb += 0.10;

        // Clamp probability between 20% and 80%
        buyProb = Math.max(0.20, Math.min(0.80, buyProb));

        direction = Math.random() < buyProb ? 'buy' : 'sell';
        log.info(`Direction: ${direction} (buyProb=${(buyProb*100).toFixed(0)}%, last=${lastTradeDirection}, streak=${streak}/${maxConsecutive}, recent=${recentBuys}B/${recentSells}S)`);
    }

    const buyPrice  = price * (1 - spreadPct / 2);
    const sellPrice = price * (1 + spreadPct / 2);

    // Fallback if insufficient balance for chosen direction
    if (direction === 'buy') {
        const usdtNeeded = tradeSize * buyPrice;
        if (usdtBalance < usdtNeeded) {
            log.warn(`Insufficient USDT ($${usdtBalance.toFixed(4)} < $${usdtNeeded.toFixed(4)}). Switching to sell.`);
            direction = 'sell';
        }
    }
    if (direction === 'sell') {
        if (trdcBalance < tradeSize) {
            log.warn(`Insufficient TRDC (${trdcBalance.toFixed(2)} < ${tradeSize.toFixed(2)}). Switching to buy.`);
            direction = 'buy';
        }
    }

    if (direction === 'buy') {
        const usdtNeeded = tradeSize * buyPrice;
        if (usdtBalance < usdtNeeded) {
            log.warn('Insufficient balance for any trade.', { usdtBalance, trdcBalance });
            return;
        }

        const amountIn = ethers.parseUnits(usdtNeeded.toFixed(DEC), DEC);
        log.trade(`Grid BUY: spending $${usdtNeeded.toFixed(4)} USDT for ~${tradeSize.toFixed(2)} TRDC`);

        const bnbPriceUsd = await getBnbPriceUsd(provider, cfg.bot_pool_trdc_usdt, cfg.bot_pool_trdc_wbnb);
        const result = await executeSwap(wallet, CONTRACTS.USDT, CONTRACTS.TRDC, fee, amountIn, maxSlippage);
        if (result) {
            const gotTrdc = parseFloat(ethers.formatUnits(result.amountOut, DEC));
            const valueUsd = gotTrdc * price;
            const gasCostBnb = parseFloat(ethers.formatUnits(result.gasUsed * result.gasPrice, 'ether'));
            const gasCostUsd = gasCostBnb * bnbPriceUsd;
            const tradePnl = (valueUsd - usdtNeeded) - gasCostUsd;
            dailyPnl += tradePnl;
            lastTradeDirection = 'buy';
            recentDirections.push('buy');
            if (recentDirections.length > 6) recentDirections.shift();
            log.trade(`Grid BUY done. Got ${gotTrdc.toFixed(2)} TRDC (~$${valueUsd.toFixed(4)}). Gas: ${gasCostBnb.toFixed(6)} BNB (~$${gasCostUsd.toFixed(4)}). P&L: $${tradePnl.toFixed(4)} (daily: $${dailyPnl.toFixed(4)})`);
            await saveTrade({
                strategy: 'grid', direction: 'buy', tokenIn: 'USDT', tokenOut: 'TRDC',
                amountIn: usdtNeeded.toFixed(6), amountOut: gotTrdc.toFixed(4),
                priceUsd: price.toFixed(10), tradeValueUsd: valueUsd.toFixed(6),
                gasUsed: result.gasUsed.toString(), gasCostBnb: gasCostBnb.toFixed(8),
                txHash: result.txHash, pnlUsd: tradePnl.toFixed(6),
                dailyPnlUsd: dailyPnl.toFixed(6), poolTvl: tvl.toFixed(2),
            });
        }
    } else {
        const amountIn = ethers.parseUnits(tradeSize.toFixed(DEC), DEC);
        const expectedUsdt = tradeSize * sellPrice;
        log.trade(`Grid SELL: selling ${tradeSize.toFixed(2)} TRDC for ~$${expectedUsdt.toFixed(4)} USDT`);

        const bnbPriceUsd = await getBnbPriceUsd(provider, cfg.bot_pool_trdc_usdt, cfg.bot_pool_trdc_wbnb);
        const result = await executeSwap(wallet, CONTRACTS.TRDC, CONTRACTS.USDT, fee, amountIn, maxSlippage);
        if (result) {
            const gotUsdt = parseFloat(ethers.formatUnits(result.amountOut, DEC));
            const costUsd = tradeSize * price;
            const gasCostBnb = parseFloat(ethers.formatUnits(result.gasUsed * result.gasPrice, 'ether'));
            const gasCostUsd = gasCostBnb * bnbPriceUsd;
            const tradePnl = (gotUsdt - costUsd) - gasCostUsd;
            dailyPnl += tradePnl;
            lastTradeDirection = 'sell';
            recentDirections.push('sell');
            if (recentDirections.length > 6) recentDirections.shift();
            log.trade(`Grid SELL done. Got $${gotUsdt.toFixed(4)} USDT. Gas: ${gasCostBnb.toFixed(6)} BNB (~$${gasCostUsd.toFixed(4)}). P&L: $${tradePnl.toFixed(4)} (daily: $${dailyPnl.toFixed(4)})`);
            await saveTrade({
                strategy: 'grid', direction: 'sell', tokenIn: 'TRDC', tokenOut: 'USDT',
                amountIn: tradeSize.toFixed(4), amountOut: gotUsdt.toFixed(6),
                priceUsd: price.toFixed(10), tradeValueUsd: gotUsdt.toFixed(6),
                gasUsed: result.gasUsed.toString(), gasCostBnb: gasCostBnb.toFixed(8),
                txHash: result.txHash, pnlUsd: tradePnl.toFixed(6),
                dailyPnlUsd: dailyPnl.toFixed(6), poolTvl: tvl.toFixed(2),
            });
        }
    }
}

/**
 * ARBITRAGE
 *
 * Compares TRDC price on TRDC/USDT vs TRDC/WBNB pools.
 * If price gap > threshold, buys on cheaper pool, sells on expensive pool.
 */
async function runArbitrage(wallet, provider, cfg) {
    const usdtPool   = cfg.bot_pool_trdc_usdt;
    const wbnbPool   = cfg.bot_pool_trdc_wbnb;
    const usdtFee    = parseInt(cfg.bot_pool_usdt_fee);
    const wbnbFee    = parseInt(cfg.bot_pool_wbnb_fee);
    const minProfit  = parseFloat(cfg.bot_min_arb_profit) / 100;
    const maxSize    = parseFloat(cfg.bot_arb_max_size);
    const maxSlippage = parseFloat(cfg.bot_max_slippage);

    // Get TRDC price from both pools (all 18 decimals)
    const priceUsdt = await getPoolPrice(provider, usdtPool, DEC, DEC);
    const priceWbnb = await getPoolPrice(provider, wbnbPool, DEC, DEC);

    if (priceUsdt <= 0 || priceWbnb <= 0) {
        log.warn(`Invalid pool prices. USDT: ${priceUsdt}, WBNB: ${priceWbnb}. Skipping arb.`);
        return;
    }

    // priceUsdt = USDT per TRDC, priceWbnb = WBNB per TRDC
    // BNB/USD = TRDC-in-USDT / TRDC-in-WBNB
    const bnbPriceUsd = priceUsdt / priceWbnb;
    if (!isFinite(bnbPriceUsd) || bnbPriceUsd <= 0) {
        log.warn(`Cannot derive BNB price. Skipping arb.`);
        return;
    }

    const trdcPriceUsdt    = priceUsdt;
    const trdcPriceViaWbnb = priceWbnb * bnbPriceUsd;

    const priceDiff = Math.abs(trdcPriceUsdt - trdcPriceViaWbnb);
    const minPrice = Math.min(trdcPriceUsdt, trdcPriceViaWbnb);
    const priceDiffPct = minPrice > 0 ? priceDiff / minPrice : 0;

    log.info(`Arb: USDT=$${trdcPriceUsdt.toFixed(10)}, viaWBNB=$${trdcPriceViaWbnb.toFixed(10)}, diff=${(priceDiffPct*100).toFixed(3)}%, BNB=$${bnbPriceUsd.toFixed(2)}`);

    if (priceDiffPct < minProfit) {
        log.info(`No arb opportunity (${(priceDiffPct*100).toFixed(3)}% < ${(minProfit*100).toFixed(1)}%).`);
        return;
    }

    const balances = await getBalances(provider, wallet.address, {
        TRDC: CONTRACTS.TRDC,
        USDT: CONTRACTS.USDT,
        WBNB: CONTRACTS.WBNB,
    });

    const randomizedMaxSize = randomizeSize(maxSize);
    const tradeSize = Math.min(randomizedMaxSize, parseFloat(balances.TRDC) * 0.5);
    if (tradeSize < 100) {
        log.warn('Trade size too small for arb.');
        return;
    }

    if (trdcPriceUsdt < trdcPriceViaWbnb) {
        const usdtNeeded = tradeSize * trdcPriceUsdt * 1.01;
        if (parseFloat(balances.USDT) < usdtNeeded) {
            log.warn(`Insufficient USDT for arb ($${parseFloat(balances.USDT).toFixed(4)} < $${usdtNeeded.toFixed(4)})`);
            return;
        }

        log.trade(`Arb: Buy ${tradeSize.toFixed(0)} TRDC on USDT pool, sell on WBNB pool`);

        const buyAmountIn = ethers.parseUnits(usdtNeeded.toFixed(DEC), DEC);
        const buyResult = await executeSwap(wallet, CONTRACTS.USDT, CONTRACTS.TRDC, usdtFee, buyAmountIn, maxSlippage);
        if (!buyResult) return;

        const sellResult = await executeSwap(wallet, CONTRACTS.TRDC, CONTRACTS.WBNB, wbnbFee, buyResult.amountOut, maxSlippage);
        if (!sellResult) return;

        const gasCost1 = buyResult.gasUsed * buyResult.gasPrice + sellResult.gasUsed * sellResult.gasPrice;
        const gasCostBnb1 = parseFloat(ethers.formatUnits(gasCost1, 'ether'));
        const gasCostUsd1 = gasCostBnb1 * bnbPriceUsd;
        const profitEst = (priceDiff * tradeSize) - gasCostUsd1;
        dailyPnl += profitEst;
        log.trade(`Arb done. Spread profit: $${(priceDiff * tradeSize).toFixed(4)}, Gas: $${gasCostUsd1.toFixed(4)}. Net P&L: $${profitEst.toFixed(4)} (daily: $${dailyPnl.toFixed(4)})`);
        await saveTrade({
            strategy: 'arbitrage', direction: 'buy', tokenIn: 'USDT', tokenOut: 'WBNB',
            amountIn: usdtNeeded.toFixed(6), amountOut: ethers.formatUnits(sellResult.amountOut, DEC),
            priceUsd: priceUsdt.toFixed(10), tradeValueUsd: (usdtNeeded).toFixed(6),
            gasUsed: (buyResult.gasUsed + sellResult.gasUsed).toString(),
            gasCostBnb: gasCostBnb1.toFixed(8),
            txHash: sellResult.txHash, pnlUsd: profitEst.toFixed(6),
            dailyPnlUsd: dailyPnl.toFixed(6), poolTvl: '0',
        });
    } else {
        const wbnbNeeded = tradeSize * priceWbnb * 1.01;
        if (parseFloat(balances.WBNB) < wbnbNeeded) {
            log.warn(`Insufficient WBNB for arb (${parseFloat(balances.WBNB).toFixed(6)} < ${wbnbNeeded.toFixed(6)})`);
            return;
        }

        log.trade(`Arb: Buy ${tradeSize.toFixed(0)} TRDC on WBNB pool, sell on USDT pool`);

        const buyAmountIn = ethers.parseUnits(wbnbNeeded.toFixed(DEC), DEC);
        const buyResult = await executeSwap(wallet, CONTRACTS.WBNB, CONTRACTS.TRDC, wbnbFee, buyAmountIn, maxSlippage);
        if (!buyResult) return;

        const sellResult = await executeSwap(wallet, CONTRACTS.TRDC, CONTRACTS.USDT, usdtFee, buyResult.amountOut, maxSlippage);
        if (!sellResult) return;

        const gasCost2 = buyResult.gasUsed * buyResult.gasPrice + sellResult.gasUsed * sellResult.gasPrice;
        const gasCostBnb2 = parseFloat(ethers.formatUnits(gasCost2, 'ether'));
        const gasCostUsd2 = gasCostBnb2 * bnbPriceUsd;
        const profitEst = (priceDiff * tradeSize) - gasCostUsd2;
        dailyPnl += profitEst;
        log.trade(`Arb done. Spread profit: $${(priceDiff * tradeSize).toFixed(4)}, Gas: $${gasCostUsd2.toFixed(4)}. Net P&L: $${profitEst.toFixed(4)} (daily: $${dailyPnl.toFixed(4)})`);
        await saveTrade({
            strategy: 'arbitrage', direction: 'buy', tokenIn: 'WBNB', tokenOut: 'USDT',
            amountIn: wbnbNeeded.toFixed(8), amountOut: ethers.formatUnits(sellResult.amountOut, DEC),
            priceUsd: priceUsdt.toFixed(10), tradeValueUsd: (wbnbNeeded * bnbPriceUsd).toFixed(6),
            gasUsed: (buyResult.gasUsed + sellResult.gasUsed).toString(),
            gasCostBnb: gasCostBnb2.toFixed(8),
            txHash: sellResult.txHash, pnlUsd: profitEst.toFixed(6),
            dailyPnlUsd: dailyPnl.toFixed(6), poolTvl: '0',
        });
    }
}

module.exports = {
    runGridTrading, runArbitrage,
    dailyPnl: () => dailyPnl,
    nextDirection: () => lastTradeDirection === 'buy' ? 'sell' : lastTradeDirection === 'sell' ? 'buy' : 'auto',
    resetDailyPnlIfNeeded,
};
