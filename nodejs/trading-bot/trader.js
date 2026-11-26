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

/**
 * Restore today's P&L saved in the DB, so a restart does not reset the daily loss limit.
 */
function restoreDailyPnl(value, date) {
    const today = new Date().toISOString().slice(0, 10);
    const v = parseFloat(value);
    if (date === today && isFinite(v)) {
        dailyPnl = v;
        dailyPnlDate = today;
        log.info(`Restored today's P&L from database: $${v.toFixed(4)}`);
    }
}

// All on-chain transactions go through this lock, so the grid loop and the arb
// monitor never send at the same time and fight over the same nonce.
let txChain = Promise.resolve();
function withTxLock(fn) {
    const run = txChain.then(fn, fn);
    txChain = run.catch(() => {});
    return run;
}

/**
 * Quote an exact-input single-pool swap. Returns amountOut (bigint) or null if the
 * pool cannot fill it (the quoter reverts when liquidity runs out).
 */
async function quoteExactIn(provider, tokenIn, tokenOut, fee, amountIn) {
    const quoterAbi = require('./abis/quoterV2.json');
    const quoter = new ethers.Contract(CONTRACTS.PANCAKE_QUOTER, quoterAbi, provider);
    try {
        const r = await quoter.quoteExactInputSingle.staticCall({
            tokenIn, tokenOut, amountIn, fee, sqrtPriceLimitX96: 0n,
        });
        return r[0] > 0n ? r[0] : null;
    } catch (e) {
        return null;
    }
}

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
    const outToken = new ethers.Contract(tokenOut, erc20Abi, wallet.provider);

    // Fresh quote right before sending
    const expectedOut = await quoteExactIn(wallet.provider, tokenIn, tokenOut, fee, amountIn);
    if (expectedOut === null) {
        log.error('Quote failed: pool cannot fill this swap (not enough liquidity in range).', {
            tokenIn: tokenIn.slice(0, 10), tokenOut: tokenOut.slice(0, 10), amountIn: ethers.formatUnits(amountIn, DEC),
        });
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
        const balanceBefore = await outToken.balanceOf(wallet.address);
        const nonce = await wallet.getNonce('pending');
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
        const gasPrice = receipt.gasPrice || tx.gasPrice || 0n; // actual gas price from receipt
        // What the wallet really received (not the quote)
        const received = (await outToken.balanceOf(wallet.address)) - balanceBefore;
        log.trade(`Swap confirmed`, { txHash: tx.hash, gasUsed: receipt.gasUsed.toString(), gasPriceGwei: ethers.formatUnits(gasPrice, 'gwei') });

        return {
            amountOut: received > 0n ? received : 0n,
            quotedOut: expectedOut,
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
function runGridTrading(wallet, provider, cfg) {
    return withTxLock(() => gridTradeOnce(wallet, provider, cfg));
}

async function gridTradeOnce(wallet, provider, cfg) {
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
    const tvl = await estimatePoolTVL(provider, poolAddress, price, await getBnbPriceUsd(provider));
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

        const bnbPriceUsd = await getBnbPriceUsd(provider);
        if (!(bnbPriceUsd > 0)) { log.warn("No BNB price; skipping grid trade."); return; }
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

        const bnbPriceUsd = await getBnbPriceUsd(provider);
        if (!(bnbPriceUsd > 0)) { log.warn("No BNB price; skipping grid trade."); return; }
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
 * Compares the TRDC price in the TRDC/USDT and TRDC/WBNB pools. When they differ,
 * it quotes the full round trip (buy TRDC in the cheaper pool, sell it in the dearer
 * one) at a few trade sizes, and trades only if the quoted result beats gas by at
 * least bot_arb_min_profit_usd.
 *
 * Always returns { status, detail } so the monitor and dashboard can show what
 * happened. Statuses:
 *   no_price, pool_too_thin, no_spread, no_balance, too_small, not_profitable  (no trade)
 *   executed                                                         (both legs done)
 *   leg1_failed, leg2_failed                                         (trade attempted, failed)
 */
async function runArbitrage(wallet, provider, cfg, gasPriceWei) {
    const usdtPool     = cfg.bot_pool_trdc_usdt;
    const wbnbPool     = cfg.bot_pool_trdc_wbnb;
    const usdtFee      = parseInt(cfg.bot_pool_usdt_fee);
    const wbnbFee      = parseInt(cfg.bot_pool_wbnb_fee);
    const minSpread    = parseFloat(cfg.bot_min_arb_profit) / 100;
    const minPoolUsd   = parseFloat(cfg.bot_arb_min_pool_usd);
    const minProfitUsd = parseFloat(cfg.bot_arb_min_profit_usd);
    const maxTrdc      = parseFloat(cfg.bot_arb_max_size);
    const maxTradePct  = parseFloat(cfg.bot_max_trade_percent) / 100;
    const maxSlippage  = parseFloat(cfg.bot_max_slippage);

    const [priceUsdt, priceWbnb, bnbPriceUsd] = await Promise.all([
        getPoolPrice(provider, usdtPool, DEC, DEC),
        getPoolPrice(provider, wbnbPool, DEC, DEC),
        getBnbPriceUsd(provider),
    ]);
    if (!(priceUsdt > 0) || !(priceWbnb > 0) || !(bnbPriceUsd > 0)) {
        return { status: 'no_price', detail: { message: 'Could not read TRDC or BNB price; skipping.' } };
    }

    const trdcViaWbnb = priceWbnb * bnbPriceUsd;
    const spread = Math.abs(priceUsdt - trdcViaWbnb) / Math.min(priceUsdt, trdcViaWbnb);

    const [usdtTvl, wbnbTvl] = await Promise.all([
        estimatePoolTVL(provider, usdtPool, priceUsdt, bnbPriceUsd),
        estimatePoolTVL(provider, wbnbPool, priceUsdt, bnbPriceUsd),
    ]);
    const smallerPool = Math.min(usdtTvl, wbnbTvl);

    const detail = {
        usdt_pool_usd: +usdtTvl.toFixed(2),
        wbnb_pool_usd: +wbnbTvl.toFixed(2),
        spread_pct: +(spread * 100).toFixed(3),
        trdc_usdt: priceUsdt,
        trdc_via_wbnb: trdcViaWbnb,
        bnb_usd: +bnbPriceUsd.toFixed(2),
        min_pool_usd: minPoolUsd,
    };

    if (smallerPool < minPoolUsd) {
        const thin = wbnbTvl < usdtTvl ? 'TRDC/WBNB' : 'TRDC/USDT';
        detail.message = `${thin} pool has $${smallerPool.toFixed(2)}, below the $${minPoolUsd} minimum. Add liquidity or lower the minimum.`;
        return { status: 'pool_too_thin', detail };
    }

    // Swap fees on both legs come off the spread
    const netSpread = spread - (usdtFee + wbnbFee) / 1e6;
    if (spread < minSpread || netSpread <= 0) {
        detail.message = `Spread ${(spread * 100).toFixed(3)}% is below the ${(minSpread * 100).toFixed(2)}% trigger (or eaten by swap fees).`;
        return { status: 'no_spread', detail };
    }

    // Direction: buy TRDC where it is cheaper
    const buyOnUsdt = priceUsdt < trdcViaWbnb;
    const leg = buyOnUsdt
        ? { tokenIn: CONTRACTS.USDT, inSym: 'USDT', inPrice: 1, fee1: usdtFee,
            tokenOut: CONTRACTS.WBNB, outSym: 'WBNB', outPrice: bnbPriceUsd, fee2: wbnbFee,
            direction: 'buy_usdt_sell_wbnb' }
        : { tokenIn: CONTRACTS.WBNB, inSym: 'WBNB', inPrice: bnbPriceUsd, fee1: wbnbFee,
            tokenOut: CONTRACTS.USDT, outSym: 'USDT', outPrice: 1, fee2: usdtFee,
            direction: 'buy_wbnb_sell_usdt' };

    // Size from pool depth: ~ (combined depth) x (net spread) / 4 is the size that
    // closes the gap. Cap by max % of the smaller pool, max TRDC size and wallet balance.
    const combinedDepth = usdtTvl * wbnbTvl / (usdtTvl + wbnbTvl);
    let sizeUsd = combinedDepth * netSpread / 4;
    sizeUsd = Math.min(sizeUsd, smallerPool * maxTradePct);
    if (maxTrdc > 0) sizeUsd = Math.min(sizeUsd, maxTrdc * priceUsdt);

    const balances = await getBalances(provider, wallet.address, { [leg.inSym]: leg.tokenIn });
    const walletUsd = parseFloat(balances[leg.inSym]) * leg.inPrice;
    sizeUsd = Math.min(sizeUsd, walletUsd * 0.98);
    detail.direction = leg.direction;
    if (sizeUsd < 0.05) {
        detail.message = walletUsd < 0.05
            ? `Bot wallet has no ${leg.inSym} to start this arb (needs ${leg.inSym}).`
            : `Best trade size is only $${sizeUsd.toFixed(3)}; too small to bother.`;
        return { status: walletUsd < 0.05 ? 'no_balance' : 'too_small', detail };
    }

    // Quote the full round trip at a few sizes; keep the best net result
    const gasUsd = parseFloat(ethers.formatEther(gasPriceWei * 300000n)) * bnbPriceUsd; // 2 swaps ~280k gas
    let best = null;
    for (const m of [0.5, 1, 1.5]) {
        const usd = sizeUsd * m;
        if (usd > walletUsd * 0.98) continue;
        const amountIn = ethers.parseUnits((usd / leg.inPrice).toFixed(DEC), DEC);
        if (amountIn === 0n) continue;
        const trdcOut = await quoteExactIn(provider, leg.tokenIn, CONTRACTS.TRDC, leg.fee1, amountIn);
        if (trdcOut === null) continue;
        const finalOut = await quoteExactIn(provider, CONTRACTS.TRDC, leg.tokenOut, leg.fee2, trdcOut);
        if (finalOut === null) continue;
        const outUsd = parseFloat(ethers.formatUnits(finalOut, DEC)) * leg.outPrice;
        const net = outUsd - usd - gasUsd;
        if (!best || net > best.net) best = { amountIn, usd, net, outUsd };
    }

    if (!best) {
        detail.message = 'Pools could not quote the round trip at any size (liquidity out of range?).';
        return { status: 'not_profitable', detail };
    }
    detail.size_usd = +best.usd.toFixed(4);
    detail.expected_net_usd = +best.net.toFixed(5);
    detail.gas_usd = +gasUsd.toFixed(5);
    if (best.net < minProfitUsd) {
        detail.message = `Best round trip nets $${best.net.toFixed(4)} after gas ($${gasUsd.toFixed(4)}), below the $${minProfitUsd} minimum.`;
        return { status: 'not_profitable', detail };
    }

    // Execute both legs under the tx lock so the grid loop cannot interleave
    return withTxLock(async () => {
        log.trade(`Arb: ${leg.direction} size $${best.usd.toFixed(4)}, expected net $${best.net.toFixed(4)}`);

        const leg1 = await executeSwap(wallet, leg.tokenIn, CONTRACTS.TRDC, leg.fee1, best.amountIn, maxSlippage);
        if (!leg1) {
            detail.message = 'First swap failed; nothing traded.';
            return { status: 'leg1_failed', detail };
        }
        const gas1Usd = parseFloat(ethers.formatEther(leg1.gasUsed * leg1.gasPrice)) * bnbPriceUsd;

        const leg2 = await executeSwap(wallet, CONTRACTS.TRDC, leg.tokenOut, leg.fee2, leg1.amountOut, maxSlippage);
        if (!leg2) {
            // Bought TRDC but could not sell it: the wallet now holds the TRDC.
            dailyPnl -= gas1Usd;
            detail.message = `Second swap failed; wallet now holds ${ethers.formatUnits(leg1.amountOut, DEC)} TRDC from the first swap.`;
            log.error(`[Arb] ${detail.message}`);
            await saveTrade({
                strategy: 'arbitrage', direction: `${leg.direction}_leg1_only`, tokenIn: leg.inSym, tokenOut: 'TRDC',
                amountIn: ethers.formatUnits(best.amountIn, DEC), amountOut: ethers.formatUnits(leg1.amountOut, DEC),
                priceUsd: priceUsdt.toFixed(10), tradeValueUsd: best.usd.toFixed(6),
                gasUsed: leg1.gasUsed.toString(), gasCostBnb: ethers.formatEther(leg1.gasUsed * leg1.gasPrice),
                txHash: leg1.txHash, pnlUsd: (-gas1Usd).toFixed(6),
                dailyPnlUsd: dailyPnl.toFixed(6), poolTvl: smallerPool.toFixed(2),
            });
            return { status: 'leg2_failed', detail };
        }

        // Real P&L from what actually came back
        const gasBnb = leg1.gasUsed * leg1.gasPrice + leg2.gasUsed * leg2.gasPrice;
        const gasCostUsd = parseFloat(ethers.formatEther(gasBnb)) * bnbPriceUsd;
        const outUsd = parseFloat(ethers.formatUnits(leg2.amountOut, DEC)) * leg.outPrice;
        const pnl = outUsd - best.usd - gasCostUsd;
        dailyPnl += pnl;
        detail.actual_net_usd = +pnl.toFixed(5);
        detail.message = `Arb done: in $${best.usd.toFixed(4)} ${leg.inSym}, out $${outUsd.toFixed(4)} ${leg.outSym}, gas $${gasCostUsd.toFixed(4)}, net $${pnl.toFixed(4)}.`;
        log.trade(`[Arb] ${detail.message} (daily: $${dailyPnl.toFixed(4)})`);

        await saveTrade({
            strategy: 'arbitrage', direction: leg.direction, tokenIn: leg.inSym, tokenOut: leg.outSym,
            amountIn: ethers.formatUnits(best.amountIn, DEC), amountOut: ethers.formatUnits(leg2.amountOut, DEC),
            priceUsd: priceUsdt.toFixed(10), tradeValueUsd: best.usd.toFixed(6),
            gasUsed: (leg1.gasUsed + leg2.gasUsed).toString(), gasCostBnb: ethers.formatEther(gasBnb),
            txHash: leg2.txHash, pnlUsd: pnl.toFixed(6),
            dailyPnlUsd: dailyPnl.toFixed(6), poolTvl: smallerPool.toFixed(2),
        });
        return { status: 'executed', detail };
    });
}

module.exports = {
    runGridTrading, runArbitrage, restoreDailyPnl,
    dailyPnl: () => dailyPnl,
    dailyPnlDate: () => dailyPnlDate,
    nextDirection: () => lastTradeDirection === 'buy' ? 'sell' : lastTradeDirection === 'sell' ? 'buy' : 'auto',
    resetDailyPnlIfNeeded,
};
