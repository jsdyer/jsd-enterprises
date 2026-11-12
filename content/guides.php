<?php

declare(strict_types=1);

// The array key is the guide's URL slug. Add sections and sources as needed.
return [
    'ibkr-tws-api' => [
        'category' => 'Brokerage APIs',
        'title' => 'Make your first read-only IBKR API call',
        'description' => 'Connect a local Python script to Trader Workstation in paper mode and read the server clock.',
        'minutes' => 6,
        'sections' => [
            [
                'heading' => 'Set up a local paper session',
                'steps' => [
                    'Install Trader Workstation (TWS) and the official TWS API SDK from Interactive Brokers. Use SDK 10.40 or later for the Python example below.',
                    'Sign in to TWS in Paper Trading mode. In Global Configuration, open API → Settings and enable ActiveX and socket clients.',
                    'Leave Read-Only API and localhost-only connections enabled. Confirm the socket port shown in TWS. The paper-session default is 7497.',
                    'Create and activate a Python virtual environment. In the extracted SDK’s source/pythonclient directory, run python -m pip install . to install that local copy.',
                ],
            ],
            [
                'heading' => 'Read the TWS clock',
                'paragraphs' => [
                    'Save this as check_tws.py and run python check_tws.py on the same computer as TWS. Client ID 7 identifies this connection. Choose another unused ID if a second script already uses it.',
                ],
                'code' => <<<'PYTHON'
from datetime import datetime, timezone
from ibapi.sync_wrapper import TWSSyncWrapper

tws = TWSSyncWrapper(timeout=10)
if not tws.connect_and_start(host="127.0.0.1", port=7497, client_id=7):
    raise SystemExit("Could not connect to TWS in paper mode.")

try:
    timestamp = tws.get_current_time()
    print(datetime.fromtimestamp(timestamp, tz=timezone.utc).isoformat())
finally:
    tws.disconnect_and_stop()
PYTHON,
            ],
            [
                'heading' => 'Check the connection and permissions',
                'paragraphs' => [
                    'If the script cannot connect, confirm TWS has finished signing in, the socket setting is enabled, and the script’s port matches the configured port. If the clock works but quotes fail, check the instrument’s market data permissions in TWS.',
                    'IBKR Canada restricts API order submission for products traded on Canadian exchanges and other Canadian marketplaces, including orders from third-party API integrations. The local clock check only reads from TWS.',
                ],
            ],
        ],
        'sources' => [
            ['label' => 'IBKR Campus · Configuring TWS for the API', 'url' => 'https://www.interactivebrokers.com/campus/trading-lessons/installing-configuring-tws-for-the-api/'],
            ['label' => 'IBKR Campus · Native API and localhost setup', 'url' => 'https://www.interactivebrokers.com/campus/?p=203269'],
            ['label' => 'IBKR · Install the SDK on macOS and Linux', 'url' => 'https://www.interactivebrokers.com/docs/tws-api/doc/install-the-tws-api-on-mac-os-linux'],
            ['label' => 'IBKR · Synchronous Python wrapper', 'url' => 'https://www.interactivebrokers.com/docs/tws-api/sync-doc/introduction'],
            ['label' => 'IBKR · Server time request', 'url' => 'https://www.interactivebrokers.com/docs/tws-api/sync-doc/current-time'],
            ['label' => 'IBKR · Canada and TWS API limitations', 'url' => 'https://www.interactivebrokers.com/docs/tws-api/doc/notes-limitations/tws-api-limitations'],
        ],
    ],
    'rbc-direct-investing-statements' => [
        'category' => 'Brokerage',
        'title' => 'Archive your RBC Direct Investing statements',
        'description' => 'Download account statements and trade confirmations, then build a simple local holdings snapshot.',
        'minutes' => 4,
        'sections' => [
            [
                'heading' => 'Download a statement',
                'steps' => [
                    'Sign in to RBC Direct Investing on the web. Open the profile menu and choose Documents & Tax Slips.',
                    'Choose the statement document type, then select the account and statement month and year.',
                    'Select Show Documents and use the resulting download link. Repeat for each account you want to reconcile and save any relevant trade confirmations separately.',
                    'If statements are missing, open Manage document delivery preferences and check whether the account receives statements electronically.',
                ],
            ],
            [
                'heading' => 'Create an end-of-month snapshot',
                'paragraphs' => [
                    'Create a row for each position with the statement date, an account nickname, exchange, symbol, currency and number of shares or units. Use separate rows for CAD and USD cash balances. Keeping currencies in separate columns or rows makes it easier to reconcile your totals.',
                    'Name the PDF by period and account nickname, for example 2026-10-TFSA-statement.pdf. Keep statements, trade confirmations and tax slips in private storage. Use just the symbols when copying names to a watchlist.',
                ],
                'code' => <<<'CSV'
statement_date,account_label,exchange,symbol,currency,quantity
2026-10-31,TFSA,TSX,EXAMPLE,CAD,25
CSV,
            ],
            [
                'heading' => 'Look up a recent transfer',
                'paragraphs' => [
                    'RBC lists cash and security transfer history under Trade & Transfer → Transactions → View Activity. Its guide says the transfer view covers 15 months. For older reconciliations, start with your archived statements.',
                ],
            ],
        ],
        'sources' => [
            ['label' => 'RBC Direct Investing · Electronic documents', 'url' => 'https://www.rbcdirectinvesting.com/learn/en/di/hubs/investing-academy/article/what-documents-can-i-get-electronically/k11cl531'],
            ['label' => 'RBC Direct Investing · Activity FAQs', 'url' => 'https://www.rbcdirectinvesting.com/learn/en/di/reference/article/activity-faqs/kbezz6y9'],
        ],
    ],
    'canadian-registered-accounts' => [
        'category' => 'Registered accounts',
        'title' => 'TFSA, RRSP and FHSA essentials',
        'description' => 'Compare contributions, withdrawals and where to verify how much room you have.',
        'minutes' => 5,
        'sections' => [
            [
                'heading' => 'TFSA · Flexible savings and investing',
                'paragraphs' => [
                    'TFSA contributions use after-tax money. Investment income and withdrawals are generally tax-free. A withdrawal adds back the same amount of contribution room on January 1 of the following year.',
                    'If you withdraw $2,000 in October and have used all your available room, January 1 is when that $2,000 becomes available to contribute again. Use your records from every TFSA issuer to verify the available amount. CRA account information can lag recent transactions.',
                ],
            ],
            [
                'heading' => 'RRSP · Retirement savings',
                'paragraphs' => [
                    'Eligible RRSP contributions can reduce taxable income. Investment earnings generally grow tax-deferred and ordinary withdrawals generally count as taxable income.',
                    'Check the RRSP statement on your latest notice of assessment or reassessment. It shows both the deduction limit and available contribution room, which can differ if you have previously reported contributions you have yet to deduct.',
                ],
            ],
            [
                'heading' => 'FHSA · A qualifying first home',
                'paragraphs' => [
                    'FHSA participation room starts when you open your first FHSA. Eligible contributions are generally deductible, and qualifying withdrawals for a first home are tax-free. A direct transfer from an RRSP uses FHSA room and provides no additional deduction.',
                    'Check CRA’s conditions for opening an FHSA and for making a qualifying withdrawal. Record contributions and direct transfers separately so you can reconcile the amount that used room and the amount eligible for a deduction.',
                ],
            ],
        ],
        'sources' => [
            ['label' => 'CRA · What is a TFSA?', 'url' => 'https://www.canada.ca/en/revenue-agency/services/tax/individuals/topics/tax-free-savings-account/what.html'],
            ['label' => 'CRA · Calculate your TFSA room', 'url' => 'https://www.canada.ca/en/revenue-agency/services/tax/individuals/topics/tax-free-savings-account/contributing/calculate-room.html'],
            ['label' => 'CRA · RRSPs', 'url' => 'https://www.canada.ca/en/revenue-agency/services/tax/individuals/topics/rrsps-related-plans/registered-retirement-savings-plan-rrsp.html'],
            ['label' => 'CRA · Reading your notice of assessment', 'url' => 'https://www.canada.ca/en/services/taxes/income-tax/personal-income-tax/after-you-file/noa-nor.html'],
            ['label' => 'CRA · First Home Savings Accounts', 'url' => 'https://www.canada.ca/en/revenue-agency/services/tax/individuals/topics/first-home-savings-account.html'],
        ],
    ],
    'read-etf-facts' => [
        'category' => 'Stock research',
        'title' => 'Read a Canadian ETF Facts sheet',
        'description' => 'Find what a fund holds, how it trades and which costs to compare before adding it to your research list.',
        'minutes' => 4,
        'sections' => [
            [
                'heading' => 'Match the document to the listing',
                'steps' => [
                    'Find the latest ETF Facts document on the fund provider’s website. Match the ticker, exchange and currency to the listing you are researching.',
                    'Read the investment objective, top holdings and investment mix. Note the underlying index or strategy and the date of the holdings snapshot.',
                    'Check whether the holdings overlap with another ETF you already follow. Similar names can cover different markets, and different names can hold many of the same securities.',
                ],
            ],
            [
                'heading' => 'Compare holding and trading costs',
                'paragraphs' => [
                    'The management expense ratio (MER) is an annual measure of the fund’s management and operating expenses. A 0.25% MER is roughly $25 per year on a hypothetical $10,000 position whose value stays constant.',
                    'Read the trading information too. Your brokerage may charge a commission, and the bid-ask spread affects the price you can get when you buy or sell. Compare these costs separately from the fund’s MER.',
                ],
            ],
        ],
        'sources' => [
            ['label' => 'Ontario Securities Commission · Annotated ETF Facts', 'url' => 'https://www.getsmarteraboutmoney.ca/tool-embeds/etf-facts/'],
        ],
    ],
    'bank-of-canada-valet-api' => [
        'category' => 'Market data',
        'title' => 'Get USD/CAD from the Bank of Canada API',
        'description' => 'Fetch five published daily exchange rates and see what to keep when you chart or store them.',
        'minutes' => 5,
        'sections' => [
            [
                'heading' => 'Fetch the observations',
                'paragraphs' => [
                    'The Bank of Canada’s Valet API publishes exchange rates and other economic series. FXUSDCAD gives the daily average number of Canadian dollars per U.S. dollar.',
                    'This example uses curl and jq to print the date and rate in chronological order. The JSON response includes the series metadata, and each observation carries its own date.',
                ],
                'code' => <<<'SHELL'
curl -fsS 'https://www.bankofcanada.ca/valet/observations/FXUSDCAD/json?recent=5' |
  jq -r '.observations | sort_by(.d)[] | [.d, .FXUSDCAD.v] | @tsv'
SHELL,
            ],
            [
                'heading' => 'Preserve dates and units',
                'paragraphs' => [
                    'Store the series ID, observation date, source and value. Cache the response and label the chart in CAD per USD. Five recent observations are five published dates, which can span more than five calendar days.',
                    'To express an amount in CAD at the published rate, multiply the USD amount by the observation. An illustrative rate of 1.38 CAD per USD converts US$100 to CA$138. Your bank or broker may apply a different conversion rate and fees.',
                ],
            ],
            [
                'heading' => 'Choose a separate source for stocks',
                'paragraphs' => [
                    'For a website showing TSX prices, check that your provider covers the exchange and grants the required display and redistribution rights. TMX publishes separate contract documents for delayed data and external distribution. Keep the source, timestamp, currency and any delay visible alongside a chart.',
                ],
            ],
        ],
        'sources' => [
            ['label' => 'Bank of Canada · Valet API how-to', 'url' => 'https://www.bankofcanada.ca/valet-api-how-to/'],
            ['label' => 'Bank of Canada · FXUSDCAD sample response', 'url' => 'https://www.bankofcanada.ca/valet/observations/FXUSDCAD/json?recent=5'],
            ['label' => 'TMX · Pricing and contract documents', 'url' => 'https://www.tmxinfoservices.com/market-data/pricing-and-contract-documents'],
        ],
    ],
    'annual-fee-check' => [
        'category' => 'Personal finance',
        'title' => 'Run an annual fee check',
        'description' => 'Put monthly bank fees and card renewals on the same timeline so you can see the full cost.',
        'minutes' => 4,
        'sections' => [
            [
                'heading' => 'Start with what you paid',
                'steps' => [
                    'Review a year of statements. Record each bank package fee, card annual fee, transaction charge and rebate. Tag any temporary waiver with its end date.',
                    'Compare the features you use, such as ATM access, transfers, foreign-currency purchases and travel coverage. Use the FCAC comparison tools to find accounts and cards with those features, then check their current terms.',
                    'Calculate the cost over the next 12 months and for a regular renewal year. If you carry a credit card balance, include estimated interest alongside the annual fee.',
                ],
            ],
            [
                'heading' => 'Put a dollar figure on the renewal',
                'paragraphs' => [
                    'A chequing plan at $11 per month and a card at $120 per year cost $252 over a full year before any rebates or other charges. If the card has a first-year waiver, note the $120 fee separately for the second year. Value card rewards using the redemptions you actually make.',
                    'If a fee is waived when you maintain a minimum balance, include what that balance could earn in an alternative account. For example, $4,000 earning an illustrative 2.5% would produce $100 a year before tax.',
                ],
            ],
        ],
        'sources' => [
            ['label' => 'Financial Consumer Agency of Canada · Chequing accounts', 'url' => 'https://www.canada.ca/en/financial-consumer-agency/services/banking/bank-accounts/chequing-accounts.html'],
            ['label' => 'Financial Consumer Agency of Canada · Choosing a credit card', 'url' => 'https://www.canada.ca/en/financial-consumer-agency/services/credit-cards/choose-credit-card.html'],
            ['label' => 'Government of Canada · Account and card comparison tools', 'url' => 'https://www.canada.ca/en/services/finance/tools.html'],
        ],
    ],
];
