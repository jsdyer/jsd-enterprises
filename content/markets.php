<?php

declare(strict_types=1);

// The prices and drift below only seed synthetic data. Add instruments here.
return [
    '^GSPTSE' => ['name' => 'S&P/TSX Composite', 'sector' => 'Canadian equities', 'exchange' => 'TSX', 'type' => 'index', 'base' => 27853.20, 'drift' => 0.166, 'volatility' => 0.025],
    '^SPCDNX' => ['name' => 'S&P/TSX Venture Composite', 'sector' => 'Canadian small caps', 'exchange' => 'TSXV', 'type' => 'index', 'base' => 891.36, 'drift' => 0.102, 'volatility' => 0.048],
    'CAD=X' => ['name' => 'USD to CAD', 'sector' => 'Foreign exchange', 'exchange' => 'FX', 'type' => 'fx', 'base' => 1.3742, 'drift' => -0.014, 'volatility' => 0.011],
    'RY' => ['name' => 'Royal Bank of Canada', 'sector' => 'Financials', 'type' => 'stock', 'base' => 168.21, 'drift' => 0.184, 'volatility' => 0.027],
    'TD' => ['name' => 'Toronto-Dominion Bank', 'sector' => 'Financials', 'type' => 'stock', 'base' => 112.39, 'drift' => 0.119, 'volatility' => 0.033],
    'ENB' => ['name' => 'Enbridge', 'sector' => 'Energy', 'type' => 'stock', 'base' => 62.87, 'drift' => 0.057, 'volatility' => 0.018],
    'CNR' => ['name' => 'Canadian National Railway', 'sector' => 'Industrials', 'type' => 'stock', 'base' => 143.25, 'drift' => -0.064, 'volatility' => 0.026],
    'SHOP' => ['name' => 'Shopify', 'sector' => 'Technology', 'type' => 'stock', 'base' => 151.52, 'drift' => 0.238, 'volatility' => 0.070],
    'SU' => ['name' => 'Suncor Energy', 'sector' => 'Energy', 'type' => 'stock', 'base' => 54.31, 'drift' => 0.040, 'volatility' => 0.044],
    'BCE' => ['name' => 'BCE', 'sector' => 'Telecom', 'type' => 'stock', 'base' => 36.74, 'drift' => -0.114, 'volatility' => 0.032],
    'BNS' => ['name' => 'Bank of Nova Scotia', 'sector' => 'Financials', 'type' => 'stock', 'base' => 79.84, 'drift' => 0.142, 'volatility' => 0.031],
    'WCN' => ['name' => 'Waste Connections', 'sector' => 'Industrials', 'type' => 'stock', 'base' => 224.69, 'drift' => 0.116, 'volatility' => 0.024],
    'XIU' => ['name' => 'iShares S&P/TSX 60 Index ETF', 'sector' => 'Canadian equities', 'type' => 'etf', 'base' => 40.98, 'drift' => 0.147, 'volatility' => 0.021],
    'VFV' => ['name' => 'Vanguard S&P 500 Index ETF', 'sector' => 'U.S. equities', 'type' => 'etf', 'base' => 149.63, 'drift' => 0.123, 'volatility' => 0.029],
    'XEQT' => ['name' => 'iShares Core Equity ETF Portfolio', 'sector' => 'Global equities', 'type' => 'etf', 'base' => 37.15, 'drift' => 0.136, 'volatility' => 0.022],
];
