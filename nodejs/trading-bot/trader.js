'use strict';

const { ethers } = require('ethers');
const log = require('./logger');
const { CONTRACTS } = require('./config');
const { getPoolPrice, getBalances, estimatePoolTVL } = require('./prices');

const erc20Abi   = require('./abis/erc20.json');
const routerAbi  = require('./abis/routerV3.json');

// Token decimals (TRDC=8, USDT=18, WBNB=18)
const DECIMALS = { TRDC: 8, USDT: 18, WBNB: 18 };

// Daily P&L tracker (resets at midnight UTC)
let dailyPnl = 0;
let dailyPnlDate = new Date().toISOString().slice(0, 10);

function resetDailyPnlIfNeeded() {
    const today = new Date().toISOString().slice(0, 10);
    if (today !== dailyPnlDate) {
        log.info(`Daily P&L reset. Yesterday: $${dailyPnl.toFixed(2)}`);
        dailyPnl = 0;
        dailyPnlDate = today;
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
 * Returns { amountOut, txHash, gasUsed }
 */
async function executeSwap(wallet, tokenIn, tokenOut, fee, amountIn, maxSlippagePct) {
    const router = new ethers.Contract(CONTRACTS.PANCAKE_ROUTER, routerAbi, wallet);

    // Calculate minimum output with slippage
    // For safety, we use quoter first in a static call
    const quoterAbi = require('./abis/quoterV2.json');
    const quoter = new ethers.Contract(CONTRACTS.PANCAKE_QUOTER, quoterAbi, wallet.provider);

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

    const minOut = expectedOut * (10000n - BigInt(Math.floor(maxSlippagePct * 100))) / 10000n;

    log.trade(`Swapping`, {
        tokenIn: tokenIn.slice(0, 10),
        tokenOut: tokenOut.slice(0, 10),
        amountIn: amountIn.toString(),
        expectedOut: expectedOut.toString(),
        minOut: minOut.toString(),
    });

    // Ensure approval
    await ensureApproval(wallet, tokenIn, amountIn);

    // Execute swap
    const tx = await router.exactInputSingle({
        tokenIn,
        tokenOut,
        fee,
        recipient: wallet.address,
        amountIn,
        amountOutMinimum: minOut,
        sqrtPriceLimitX96: 0n,
    });

    const receipt = await tx.wait();
    log.trade(`Swap confirmed`, { txHash: tx.hash, gasUsed: receipt.gasUsed.toString() });

    return {
        amountOut: expectedOut,
        txHash: tx.hash,
        gasUsed: receipt.gasUsed,
    };
}

/**
 * GRID MARKET MAKING
 *
 * Places alternating buy/sell trades around the current price.
 * - Below current price: buy TRDC with USDT
 * - Above current price: sell TRDC for USDT
 */
async function runGridTrading(wallet, provider, cfg) {
    const poolAddress  = cfg.bot_pool_trdc_usdt;
    const fee          = parseInt(cfg.bot_pool_usdt_fee);
    const gridLevels   = parseInt(cfg.bot_grid_levels);
    const gridSpacing  = parseFloat(cfg.bot_grid_spacing) / 100;
    const spreadPct    = parseFloat(cfg.bot_spread_percent) / 100;
    const orderSize    = parseFloat(cfg.bot_order_size_trdc);
    const maxSlippage  = parseFloat(cfg.bot_max_slippage);
    const maxTradePct  = parseFloat(cfg.bot_max_trade_percent) / 100;
    const minTvl       = parseFloat(cfg.bot_min_tvl);

    // Get current TRDC/USDT price
    const price = await getPoolPrice(provider, poolAddress, DECIMALS.TRDC, DECIMALS.USDT);
    log.info(`TRDC/USDT price: $${price.toFixed(8)}`);

    // Check TVL
    const tvl = await estimatePoolTVL(provider, poolAddress, price);
    log.info(`Pool TVL: $${tvl.toFixed(2)}`);
    if (tvl < minTvl) {
        log.warn(`TVL $${tvl.toFixed(2)} below minimum $${minTvl}. Skipping grid trades.`);
        return;
    }

    // Calculate trade size cap based on pool TVL
    const maxTradeUsd = tvl * maxTradePct;
    const tradeSize = Math.min(orderSize, maxTradeUsd / price);

    // Check balances
    const balances = await getBalances(provider, wallet.address, {
        TRDC: CONTRACTS.TRDC,
        USDT: CONTRACTS.USDT,
    });
    log.info(`Balances`, balances);

    const buyLevels  = Math.floor(gridLevels / 2);
    const sellLevels = gridLevels - buyLevels;

    // Determine which side to trade based on spread
    // If price moved down from mid → buy, if up → sell
    // For simplicity, alternate one trade per cycle
    const midPrice = price;
    const buyPrice  = midPrice * (1 - spreadPct / 2);
    const sellPrice = midPrice * (1 + spreadPct / 2);

    // Execute one buy or one sell per cycle (to stay within cooldown)
    const trdcBalance = parseFloat(balances.TRDC);
    const usdtBalance = parseFloat(balances.USDT);

    // Buy TRDC if we have USDT and price is at/below buy level
    if (usdtBalance >= tradeSize * buyPrice) {
        const amountIn = ethers.parseUnits(
            (tradeSize * buyPrice).toFixed(DECIMALS.USDT),
            DECIMALS.USDT
        );

        log.trade(`Grid BUY: spending ~$${(tradeSize * buyPrice).toFixed(2)} USDT for ~${tradeSize.toFixed(2)} TRDC`);

        const result = await executeSwap(
            wallet, CONTRACTS.USDT, CONTRACTS.TRDC, fee, amountIn, maxSlippage
        );

        if (result) {
            const gotTrdc = parseFloat(ethers.formatUnits(result.amountOut, DECIMALS.TRDC));
            const costUsd = tradeSize * buyPrice;
            const valueUsd = gotTrdc * price;
            dailyPnl += (valueUsd - costUsd);
            log.trade(`Grid BUY complete. Got ${gotTrdc.toFixed(2)} TRDC. Daily P&L: $${dailyPnl.toFixed(2)}`);
        }
    }
    // Sell TRDC if we have enough
    else if (trdcBalance >= tradeSize) {
        const amountIn = ethers.parseUnits(
            tradeSize.toFixed(DECIMALS.TRDC),
            DECIMALS.TRDC
        );

        log.trade(`Grid SELL: selling ${tradeSize.toFixed(2)} TRDC for ~$${(tradeSize * sellPrice).toFixed(2)} USDT`);

        const result = await executeSwap(
            wallet, CONTRACTS.TRDC, CONTRACTS.USDT, fee, amountIn, maxSlippage
        );

        if (result) {
            const gotUsdt = parseFloat(ethers.formatUnits(result.amountOut, DECIMALS.USDT));
            const costUsd = tradeSize * price;
            dailyPnl += (gotUsdt - costUsd);
            log.trade(`Grid SELL complete. Got $${gotUsdt.toFixed(2)} USDT. Daily P&L: $${dailyPnl.toFixed(2)}`);
        }
    } else {
        log.warn('Insufficient balance for grid trade', { trdcBalance, usdtBalance, neededTrdc: tradeSize, neededUsdt: tradeSize * buyPrice });
    }
}

/**
 * ARBITRAGE
 *
 * Compares TRDC price on TRDC/USDT vs TRDC/WBNB pools.
 * If price gap > threshold, buys on cheaper pool, sells on expensive pool.
 */
async function runArbitrage(wallet, provider, cfg) {
    const usdtPool  = cfg.bot_pool_trdc_usdt;
    const wbnbPool  = cfg.bot_pool_trdc_wbnb;
    const usdtFee   = parseInt(cfg.bot_pool_usdt_fee);
    const wbnbFee   = parseInt(cfg.bot_pool_wbnb_fee);
    const minProfit  = parseFloat(cfg.bot_min_arb_profit) / 100;
    const maxSize    = parseFloat(cfg.bot_arb_max_size);
    const maxSlippage = parseFloat(cfg.bot_max_slippage);

    // Get TRDC price from both pools
    const priceUsdt = await getPoolPrice(provider, usdtPool, DECIMALS.TRDC, DECIMALS.USDT);
    const priceWbnb = await getPoolPrice(provider, wbnbPool, DECIMALS.TRDC, DECIMALS.WBNB);

    // Get BNB/USD price (approximate from WBNB pool)
    // priceWbnb = TRDC per WBNB, so TRDC price in WBNB = 1/priceWbnb
    // We need an external BNB/USD reference. Use a simple approach:
    // trdcInUsd from USDT pool, trdcInWbnb from WBNB pool → BNB price = trdcInUsd / trdcInWbnb
    const bnbPriceUsd = priceUsdt / priceWbnb || 600; // fallback

    const trdcPriceUsdt = priceUsdt;
    const trdcPriceViaWbnb = priceWbnb * bnbPriceUsd;

    const priceDiff = Math.abs(trdcPriceUsdt - trdcPriceViaWbnb);
    const priceDiffPct = priceDiff / Math.min(trdcPriceUsdt, trdcPriceViaWbnb);

    log.info(`Arb check: USDT pool=$${trdcPriceUsdt.toFixed(8)}, WBNB pool=$${trdcPriceViaWbnb.toFixed(8)}, diff=${(priceDiffPct*100).toFixed(3)}%`);

    if (priceDiffPct < minProfit) {
        log.info(`Arb spread ${(priceDiffPct*100).toFixed(3)}% < min ${(minProfit*100).toFixed(1)}%. No opportunity.`);
        return;
    }

    // Determine direction: buy cheap, sell expensive
    const tradeSize = Math.min(maxSize, maxSize); // Could cap further by balance
    const amountTrdc = ethers.parseUnits(tradeSize.toFixed(DECIMALS.TRDC), DECIMALS.TRDC);

    if (trdcPriceUsdt < trdcPriceViaWbnb) {
        // TRDC cheaper on USDT pool → buy TRDC with USDT, sell TRDC for WBNB
        log.trade(`Arb: Buy TRDC on USDT pool (cheaper), sell on WBNB pool`);

        const usdtNeeded = ethers.parseUnits(
            (tradeSize * trdcPriceUsdt * 1.01).toFixed(DECIMALS.USDT),
            DECIMALS.USDT
        );

        // Step 1: Buy TRDC with USDT
        const buyResult = await executeSwap(
            wallet, CONTRACTS.USDT, CONTRACTS.TRDC, usdtFee, usdtNeeded, maxSlippage
        );
        if (!buyResult) return;

        // Step 2: Sell TRDC for WBNB
        const sellResult = await executeSwap(
            wallet, CONTRACTS.TRDC, CONTRACTS.WBNB, wbnbFee, buyResult.amountOut, maxSlippage
        );
        if (!sellResult) return;

        const profitUsd = priceDiff * tradeSize;
        dailyPnl += profitUsd;
        log.trade(`Arb complete. Est. profit: $${profitUsd.toFixed(2)}. Daily P&L: $${dailyPnl.toFixed(2)}`);
    } else {
        // TRDC cheaper on WBNB pool → buy TRDC with WBNB, sell TRDC for USDT
        log.trade(`Arb: Buy TRDC on WBNB pool (cheaper), sell on USDT pool`);

        const wbnbNeeded = ethers.parseUnits(
            (tradeSize * priceWbnb * 1.01).toFixed(DECIMALS.WBNB),
            DECIMALS.WBNB
        );

        // Step 1: Buy TRDC with WBNB
        const buyResult = await executeSwap(
            wallet, CONTRACTS.WBNB, CONTRACTS.TRDC, wbnbFee, wbnbNeeded, maxSlippage
        );
        if (!buyResult) return;

        // Step 2: Sell TRDC for USDT
        const sellResult = await executeSwap(
            wallet, CONTRACTS.TRDC, CONTRACTS.USDT, usdtFee, buyResult.amountOut, maxSlippage
        );
        if (!sellResult) return;

        const profitUsd = priceDiff * tradeSize;
        dailyPnl += profitUsd;
        log.trade(`Arb complete. Est. profit: $${profitUsd.toFixed(2)}. Daily P&L: $${dailyPnl.toFixed(2)}`);
    }
}

module.exports = { runGridTrading, runArbitrage, dailyPnl: () => dailyPnl, resetDailyPnlIfNeeded };
