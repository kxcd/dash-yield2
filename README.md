# Dash Yield!


Source code to:[Dash-rewards-masternode-Evonode-earnings](https://mnowatch.org/Dash-rewards-masternode-Evonode-earnings/)

**Example contents of untracked/hidden file ./configs/.config-compute.php**

    <?php
    $urlDC = "http://127.0.0.1:9998/";
    $userDC = "";
    $passwordDC = "";

    $CoinGeckoAPIkey = ""; // demo API key CoinGecko.com
    $minutesCoinGecko = 5; // minutes triggering CoinGecko call
    // CoinGecko API limit (free mode) : "5 to 15 calls par minute" — https://support.coingecko.com/hc/en-us/articles/4538771776153-What-is-the-rate-limit-for-CoinGecko-API-public-plan
    // CoinGecko API limit (demo mode with API key in config.php) : 30 calls/minutes, 10000 calls/months = about 1 call every 5 min.


    $ExchangeRateAPIKey = ""; // free plan ExchangeRate-API.com
    $hoursExchangeRate = 12;  // hours triggering ExchangeRate-API call (should be enough)

    $development=true;




## Installation

Copy files to location, requires PHP and cURL enabled, requires access to a synced Dashd daemon.


