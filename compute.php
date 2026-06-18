<?php declare(strict_types=1);
// ================================== PARAMETERS ==================================
// $development_mode = FALSE;
require "configs/.config-compute.php"; // for connection to Dash Core (RPC), CoinGecko (API key) and ExchangeRate-API (API key)
require "configs/config.php"; // for fiat currencies & languages list


// ================================== FUNCTIONS ==================================

// curl
function http_get_json(string $url): array {
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_USERAGENT      => "Mozilla/5.0",
		CURLOPT_TIMEOUT        => 12,
	]);
	$resp = curl_exec($ch);
	if ($resp === false) {
		throw new Exception("cURL error (1) : " . curl_error($ch));
	}
	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	if ($httpCode !== 200) {
		throw new Exception("HTTP error (3) : " . $httpCode);
	}
	$json = json_decode($resp, true);
	if ($json === null) {
		throw new Exception("Invalid JSON response (2) : " . substr($resp, 0, 200));
	}
	return $json;
}

// Local Dash Core node request function
function dash_rpc(string $method, array $params = []):array|int|bool {
	global $urlDC, $userDC, $passwordDC;
	$payload = json_encode([
		'method' => $method,
		'params' => $params,
		'id'     => 1
	]);
	$ch = curl_init($urlDC);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
		CURLOPT_USERPWD        => "$userDC:$passwordDC",
		CURLOPT_POSTFIELDS     => $payload,
		CURLOPT_HTTPHEADER     => ['Content-Type: application/json']
	]);
	$response = curl_exec($ch);
	if ($response === false) {
		throw new Exception('cURL error (2) : ' . curl_error($ch));
	}
	$data = json_decode($response, true);
	if (isset($data['error']) && $data['error'] !== null) {
		throw new Exception('RPC Error: ' . json_encode($data['error']));
	}
	return $data['result'];
}

// XKCD's (complex) functions
require("./XKCD-functions.php");



// ================================== JSONs GENERATION ==================================

$now = time();
$collateral = array("MN" => 1000, "Evo" => 4000);

$JSON["generated"]["timestamp"] = $now;
$JSON["generated"]["readable"] = date("Y-m-d H:i:s", $now);


// Possible update of lastPrices.json ================================
// (contains Dash/USD price and USD/fiats prices) ====================

$JSON["lastPrices"] = [];

// Determine if an update is needed or if lastPrices.json is missing
$file_exists = file_exists("cache/lastPrices.json");
$needs_initial_fetch = !$file_exists;
if ($file_exists) {
	$rawContent = file_get_contents("cache/lastPrices.json");
		$data_temp = json_decode($rawContent, true);
		if ($data_temp !== null) {
			$JSON["lastPrices"] = $data_temp;
			$JSON["lastPrices"]["status"] = "JSON read";
		} else {
			$JSON["lastPrices"] = [];
			$JSON["lastPrices"]["status"] = "JSON corrupted, trying to refresh";
		}
}

// Dash price update (CoinGecko) if missing or outdated
$needs_price_update = false;
if ($needs_initial_fetch ||
	empty($JSON["lastPrices"]["USD"]) ||
	(isset($JSON["lastPrices"]["USD"]["time"]["timestamp"]) &&
	($now - $JSON["lastPrices"]["USD"]["time"]["timestamp"]) > ($minutesCoinGecko * 60))) {
	$needs_price_update = true;
}
if ($needs_price_update) {
	$JSON["lastPrices"]["USD"]["source"] = "CoinGecko";
	try {
		$url = "https://api.coingecko.com/api/v3/coins/dash/market_chart?vs_currency=usd&days=365&x_cg_demo_api_key=" . $CoinGeckoAPIkey;
		$data = http_get_json($url);
		if (isset($data["prices"]) && is_array($data["prices"])) {
			$prices = $data["prices"];
			$JSON["lastPrices"]["USD"]["time"] = ["timestamp" => $now, "readable" => date("Y-m-d H:i:s", $now)];
			$JSON["lastPrices"]["USD"]["365d"] = round($prices[0][1], 2);
			$JSON["lastPrices"]["USD"]["current"] = round($prices[count($prices) - 1][1], 2);
			$JSON["lastPrices"]["USD"]["status"] = "Dash/USD price refreshed";
		}
	} catch (Exception $e) {
		$JSON["lastPrices"]["USD"]["status"] = "Fetch failed: " . $e->getMessage();
	}
}

// Exchange rates update (ExchangeRate-API) if missing or outdated
$needs_exchange_update = false;
if (!isset($JSON["lastPrices"]["conversion_rates"]) || !isset($JSON["lastPrices"]["conversion_rates"]["time"]["timestamp"]) || ($now - $JSON["lastPrices"]["conversion_rates"]["time"]["timestamp"]) > ($hoursExchangeRate * 60 * 60)) {
	$needs_exchange_update = true;
}
if ($needs_exchange_update) {
	try {
		$url_exchange = "https://v6.exchangerate-api.com/v6/" . $ExchangeRateAPIKey . "/latest/USD";
		$data_exch = http_get_json($url_exchange);
		if (isset($data_exch["conversion_rates"])) {
			$JSON["lastPrices"]["conversion_rates"] = [
				"source" => "ExchangeRate-API",
				"time" => [
					"timestamp" => $now,
					"readable" => date("Y-m-d H:i:s", $now)
				]
			];
			foreach (array_keys($fiatcurrencies) as $f) {
				if (!isset($data_exch["conversion_rates"][$f])) {
					$JSON["lastPrices"]["conversion_rates"]["status"] = "Warning: missing rate for " . $f;
				}
				$JSON["lastPrices"]["conversion_rates"][$f] = $data_exch["conversion_rates"][$f] ?? 1.0;
			}
			$JSON["lastPrices"]["conversion_rates"]["status"] = "Conversion rates refreshed";
		}
	} catch (Exception $e) {
		$JSON["lastPrices"]["conversion_rates"]["status"] = "Exchange update failed: " . $e->getMessage();
	}
}

// Saving lastPrices.json 
if ($needs_price_update || $needs_exchange_update) {
	file_put_contents("cache/lastPrices.json", json_encode($JSON["lastPrices"], JSON_PRETTY_PRINT), LOCK_EX); // maybe we should prevent saving failures
	$JSON["lastPrices"]["status"] = "JSON saved";
}


	
// Calculation of the two ROIs (APYs) using XKCD's function ==================================

$MNcount = dash_rpc("masternode count");
$nbblocs = (int) dash_rpc('getblockcount');
// The JSON structure in the line below is to prepare a possible APY history in the JSON results, somehow, I don't know when nor why - just to get ready
$JSON["APY"][$now] = getMnApy((int) $MNcount["detailed"]["evo"]["enabled"], (int) $MNcount["detailed"]["regular"]["enabled"], getMnReward($nbblocs));

// saved in the history of both ROIs (APYs) ======
if (file_exists("cache/APYhistory.json") and is_numeric($JSON["APY"][$now]["MN"]) and is_numeric($JSON["APY"][$now]["Evo"])) {
	$handle = fopen("cache/APYhistory.json", "r+");
	if (!$handle) {
		echo "ERROR: history file access impossible";
	} else {
		$contenu = fread($handle, filesize("cache/APYhistory.json"));
		if (!$contenu) {
			echo "ERROR: history reading impossible";
		} else {
			$historiqueAPY = json_decode($contenu, TRUE);
			array_push($historiqueAPY, array($now, $JSON["APY"][$now]["MN"], $JSON["APY"][$now]["Evo"]));
			fseek($handle, 0);
			if (!fwrite($handle, json_encode($historiqueAPY)))
				echo "ERROR: history recording impossible";
		}
		fclose($handle);
	}
}


// Earnings calculation + simulation over 365 days using XKCD's function  ==================================

$dashPriceUSD = $JSON["lastPrices"]["USD"]["current"];

foreach ($collateral as $type => $amount) {
	// Calculation of annual income for a MN and an Evo
	$JSON["rewards"]["yearly"][$type]["DASH"] = round(($JSON["APY"][$now][$type] / 100) * $collateral[$type], 8);
	foreach (array_keys($fiatcurrencies) as $fiatcurrency) {
		$currentfiat = strtoupper($fiatcurrency); 
		$rate = $JSON["lastPrices"]["conversion_rates"][$currentfiat] ?? 1.0;
		$JSON["rewards"]["yearly"][$type][$currentfiat] = round($JSON["rewards"]["yearly"][$type]["DASH"] * $dashPriceUSD * $rate, 0);
	}
	
	// Simulations over the previous 365 days
	$dashPrice365d = $JSON["lastPrices"]["USD"]["365d"];
	foreach (array_keys($fiatcurrencies) as $fiatcurrency) {
		$currentfiat = strtoupper($fiatcurrency); 
		$rate = $JSON["lastPrices"]["conversion_rates"][$currentfiat] ?? 1.0;

		$historiqueAPY = json_decode(file_get_contents("cache/APYhistory.json"), true);
		$rewardspast365d = calculateInterestAndAPY($historiqueAPY, ($now - (365 * 24 * 60 * 60)), $now, $type, $collateral[$type]);
				
		$JSON["simulationpast365d"]["collateralpricepast365d"][$type][$currentfiat] = round($amount * $dashPrice365d * $rate, 0);
		$JSON["simulationpast365d"]["rewardspast365d"][$type]["APY365d"] = round($rewardspast365d["apy"], 1);
		$JSON["simulationpast365d"]["rewardspast365d"][$type]["DASH365d"] = round($rewardspast365d["interest"], 1);
		$JSON["simulationpast365d"]["rewardspast365d"][$type][$fiatcurrency] = round($rewardspast365d["interest"] * $dashPriceUSD * $rate, 0);
		
	}	
}




// ================================== HTML DISPLAY OR PURE JSON ==================================
if (isset($_GET["HTML"])) {
	echo "<pre>"; print_r($JSON); echo "</pre>";
} else {
	header("Content-Type: application/json");
	echo json_encode($JSON);
}

?>
