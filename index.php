<?php declare(strict_types=1);
// $development_mode = true;
// ================================== PARAMETERS ==================================
require "configs/config.php";

// Functions ===============
function pretty(float $number, int $decimals):string {
	$number = number_format($number, $decimals, ".", "&nbsp;");
	if (strpos($number, '.') === false)
		return $number;
	list($int, $dec) = explode('.', $number, 2);
	return $int . ".<span class=\"decimals\">" . $dec . "</span>";
}
function is_private_ip(string $ip): bool {
	return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

function disguise_curl(string $url):string|false {
	$curl = curl_init();
	// Setup headers - I used the same headers from Firefox version 2.0.0.6
	// below was split up because php.net said the line was too long. :/
	$header[0] = "Accept: text/xml,application/xml,application/xhtml+xml,";
	$header[0] .= "text/html;q=0.9,text/plain;q=0.8,image/png,*/*;q=0.5";
	$header[] = "Cache-Control: max-age=0";
	$header[] = "Connection: keep-alive";
	$header[] = "Keep-Alive: 300";
	$header[] = "Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7";
	$header[] = "Accept-Language: en-us,en;q=0.5";
	$header[] = "Pragma: "; // browsers keep this blank.

	curl_setopt($curl, CURLOPT_URL, $url);
	curl_setopt($curl, CURLOPT_USERAGENT, 'Googlebot/2.1 (+http://www.google.com/bot.html)');
	curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
	curl_setopt($curl, CURLOPT_REFERER, 'http://www.google.com');
	curl_setopt($curl, CURLOPT_ENCODING, 'gzip,deflate');
	curl_setopt($curl, CURLOPT_AUTOREFERER, true);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($curl, CURLOPT_TIMEOUT, 10);

	$html = curl_exec($curl); // execute the curl command
	$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
	$curlError = curl_errno($curl);
	curl_close($curl); // close the connection
	if ($curlError !== 0 || $httpCode !== 200)
		return false;
	return $html; // and finally, return $html if not FALSE
}

// Determine IP in case accessing from behind Cloudflare.
if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
	$ip = $_SERVER['HTTP_CLIENT_IP'];
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
	$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
} else {
	$ip = $_SERVER['REMOTE_ADDR'];
}

/**
 * Parse an RFC 9110 Accept-Language header into a sorted array.
 *
 * Returns an array of:
 * [
 *     ['range' => 'en-US', 'q' => 1.0],
 *     ['range' => 'en',    'q' => 0.8],
 *     ...
 * ]
 */
function parse_accept_language(string $header): array{
	// Split on commas (multiple header lines should already be concatenated)
	$parts = array_map('trim', explode(',', $header));
	$parsed = [];

	foreach ($parts as $part) {
		if ($part === '') continue;

		// Split into language-range and optional parameters
		$segments = array_map('trim', explode(';', $part));

		$range = strtolower($segments[0]);
		$q = 1.0; // default per RFC

		// Parse parameters
		for ($i = 1; $i < count($segments); $i++) {
			if (stripos($segments[$i], 'q=') === 0) {
				$value = substr($segments[$i], 2);
				$q = max(0.0, min(1.0, floatval($value)));
			}
		}

		$parsed[] = ['range' => $range, 'q' => $q];
	}

	// Sort by q DESC, then by specificity DESC (more subtags = more specific)
	usort($parsed, function ($a, $b) {
		if ($a['q'] !== $b['q'])
			return ($a['q'] < $b['q']) ? 1 : -1;

		// Specificity = number of hyphens
		$aSpec = substr_count($a['range'], '-');
		$bSpec = substr_count($b['range'], '-');

		return $bSpec <=> $aSpec;
	});

	return $parsed;
}

function boldify(string $sometext, string $font):string { // fix bold problems in Chrome/Firefox with @font-face
	if (strlen($font) > 0)
		$bold = $font . "-bold";
	else
		$bold = "bold";
	return str_replace(array("<b>", "</b>"), array("<span class='" . $bold . "'><b>", "</b></span>"), $sometext);
}




// Selected fiat currency ===============
$fiat = "USD"; // default
if (isset($_COOKIE["fiat"]) && in_array($_COOKIE["fiat"], array_keys($fiatcurrencies))) { // cookie
	$fiat = $_COOKIE["fiat"];
}
if (isset($_GET["fiat"]) and in_array($_GET["fiat"], array_keys($fiatcurrencies))) { // GET, so new cookie
	$fiat = $_GET["fiat"];
	setcookie("fiat", $fiat, [
		"expires"  => time() + 60 * 60 * 24 * 365,
		"path"     => "/",
		"samesite" => "Lax",
	]);
} elseif (!is_private_ip($_SERVER["REMOTE_ADDR"])) { // if public IP is known, let's try to find its country, hence currency
	$IPAPI = disguise_curl("https://ipapi.co/" . $ip . "/country/");
	if ($IPAPI !== false) {
		$country_code = strtoupper(trim($IPAPI));
		if (preg_match('/^[A-Z]{2}$/', $country_code) && isset($country_currency[$country_code])) {
			$candidate = $country_currency[$country_code];
			if (isset($fiatcurrencies[$candidate])) {
				$fiat = $candidate;
			}
		}
	}
}

$fiatselected = array_fill_keys(array_keys($fiatcurrencies), "");
$fiatselected[$fiat] = " selected";
$fiatoptions = array();
foreach ($fiatcurrencies as $fiatcode => $stuff)
	$fiatoptions[] = "<option class=\"menu\" value=\"" . $fiatcode . "\" " . $fiatselected[$fiatcode] . ">" . $stuff["name"] . " (" . $stuff["symbol"] . ")</option>";



// Selected language ===============
$lang = "en"; // default

// Detection through Accept-Language (low priority : smashed by cookie or GET)
if (isset($_SERVER["HTTP_ACCEPT_LANGUAGE"]) and !isset($_COOKIE["lang"]) and !isset($_GET["lang"])) {
	$code = parse_accept_language($_SERVER["HTTP_ACCEPT_LANGUAGE"]);
	$range = $code[0]['range']; // ex: "fr-FR" ou "fr"
	if (array_key_exists($range, $langnames)) {
		$lang = $range;
	} else {
		$base = strtolower(explode("-", $range)[0]);
		if (array_key_exists($base, $langnames)) {
			$lang = $base;
		}
	}
}
// Cookie (medium priority : smashes automatic detection above)
if (isset($_COOKIE["lang"]) and in_array($_COOKIE["lang"], array_keys($langnames)) and !isset($_GET["lang"])) {
	$lang = $_COOKIE["lang"];
}

// GET (high priority : smashes everything above and sets new cookie)
if (isset($_GET["lang"]) and in_array($_GET["lang"], array_keys($langnames))) {
	$lang = $_GET["lang"];
	setcookie("lang", $lang, [
		"expires"  => time() + 60 * 60 * 24 * 365,
		"path"     => "/",
		"samesite" => "Lax",
	]);
}
$langselected = array_fill_keys(array_keys($langnames), "");
$langselected[$lang] = " selected";
$langoptions = array();
foreach ($langnames as $langcode => $langname)
	$langoptions[] = "<option class=\"menu\" value=\"" . $langcode . "\" " . $langselected[$langcode] . ">" . $langname . "</option>";
if (!file_exists("./languages/" . $lang . ".json"))
	$lang = "en"; // default if missing JSON
$fmt = new IntlDateFormatter($lang, IntlDateFormatter::LONG, IntlDateFormatter::SHORT, date_default_timezone_get(), IntlDateFormatter::GREGORIAN, "d MMMM yyyy, HH:mm");

// Dash.org links
if (in_array($lang, $DashOrgTranslations))
	$dashorglang = $DashOrgTranslations[$lang];
else
	$dashorglang = "en";
if (in_array($lang, $DocsDashTranslations))
	$docsdashlang = $DocsDashTranslations[$lang];
else
	$docsdashlang = "en";
		
$rawJS = file_get_contents("./languages/" . $lang . ".json"); // gets the right JSON language file
$UItext = json_decode($rawJS, true);

$data=json_decode(exec('php compute.php'),true);
if (!is_array($data))
	die("Invalid JSON response (1)");

$APY = reset($data["APY"]);
$currentUSDprice[$fiat] = $data["lastPrices"]["conversion_rates"]["now"][$fiat];
$past365USDprice[$fiat] = $data["lastPrices"]["conversion_rates"]["one-year-ago"][$fiat];
$currentprice[$fiat] = round($data["lastPrices"]["USD"]["current"] * $currentUSDprice[$fiat], 2);
$past365dprice[$fiat] = round($data["lastPrices"]["USD"]["365d"] * $past365USDprice[$fiat], 2);
$fmt_date = new IntlDateFormatter($lang, IntlDateFormatter::LONG, IntlDateFormatter::NONE, date_default_timezone_get(), IntlDateFormatter::GREGORIAN, "d MMMM yyyy");
$daysago365 = $fmt_date->format($data["lastPrices"]["USD"]["time"]["timestamp"] - (365 * 24 * 60 * 60));

// Check if CoinGecko sent the price
if ((int) $data["lastPrices"]["USD"]["current"] <= 0)
	$pricealert = "<div class=\"pricealert\">" . $UItext["price-alert"] . "</div>";
else
	$pricealert = "";


// Show current collaterals in green if their current fiat value is higher than one year ago
$collateralvalue["MN"][$fiat]["365d"] = $data["simulationpast365d"]["collateralpricepast365d"]["MN"][$fiat];
$collateralvalue["MN"][$fiat]["current"] = round(1000 * $currentprice[$fiat], 0);
$collateralvalue["Evo"][$fiat]["365d"] = $data["simulationpast365d"]["collateralpricepast365d"]["Evo"][$fiat];
$collateralvalue["Evo"][$fiat]["current"] = round(4000 * $currentprice[$fiat], 0);
foreach ($collateralvalue as $type => $stuff) {
	if ($stuff[$fiat]["current"] >= $stuff[$fiat]["365d"])
		$collateralcolour[$type] = "green";
	else
		$collateralcolour[$type] = "";
}

?>
<!DOCTYPE html>
<html lang="<?php echo $lang;?>">
	<head>
		<title>Dash Yield - masternodes &amp; Evonodes earnings</title>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width,initial-scale=1">
		<meta name="description" content="Dash Yield is a calculator for Dash masternodes and Evonodes earnings. Enter your settings and get yield estimates in real time.">
		<meta name="theme-color" content="#008de4">
		<meta property="og:title" content="Dash Yield - masternodes and Evonodes earnings">
		<meta property="og:url" content="https://<?php echo $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"]; ?>">
		<meta property="og:image" content="https://<?php echo $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"]; ?>images/favicons/favicon-1200.png">
		<meta property="og:type" content="website">
		<meta property="og:site_name" content="Dash Yield - masternodes and Evonodes earnings">
		<meta property="og:description" content="Dash Yield is a calculator for Dash masternodes and Evonodes earnings. Enter your settings and get yield estimates in real time.">
		<meta property="og:locale" content="en_US">
		<meta name="twitter:card" content="summary_large_image">
		<meta name="twitter:title" content="Dash Yield - masternodes and Evonodes earnings">
		<meta name="twitter:description" content="Dash Yield is a calculator for Dash masternodes and Evonodes earnings. Enter your settings and get yield estimates in real time.">
		<meta name="twitter:image" content="https://<?php echo $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"]; ?>images/favicons/favicon-1200.png">
		<link rel="icon" href="favicon.ico">
		<link rel="icon" href="images/favicons/favicon-32.png" sizes="32x32">
		<link rel="icon" href="images/favicons/favicon-128-new.png" sizes="128x128">
		<link rel="icon" href="images/favicons/favicon-192-new.png" sizes="192x192">
		<!-- Android -->
		<link rel="manifest" href="https://<?php echo $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"]; ?>site.webmanifest">
		<!-- iOS -->
		<link rel="apple-touch-icon" href="images/favicons/favicon-128-new.png" sizes="128x128">
		<link rel="apple-touch-icon" href="images/favicons/favicon-192-new.png" sizes="192x192">
		<link rel="stylesheet" href="style.css<?php echo $renewCSS; ?>" type="text/css">
		<link rel="stylesheet" href="style-smartphone.css<?php echo $renewCSS; ?>" type="text/css">
		<script src="JS/scripts.js<?php echo $renewCSS; ?>"></script>
		<script src="JS/tippy/popper2.11.8.js"></script>
		<script src="JS/tippy/tippy6.3.7.js"></script>
		<script> const JSalert = "<?php echo $UItext["JSalert"]; ?>"; </script>
	</head>
	
<body>

<table><tr>
	
<td class="title">

<!-- TITLE box ================================= -->
<div class="box title">

	<a class="refresh" href="./">
		<div class="banner">LIVE</div>
		
		<img src="images/dash_digitalcash.png" alt="<?php echo $UItext["Dash-yield"] . " - " . $UItext["MN-Evo-earnings"]; ?>" title="<?php echo $UItext["Dash-yield"] . " - " . $UItext["MN-Evo-earnings"] ; ?>" class="logo">
		<span class="subbrand">yield</span>
	</a>
	<br><span class="blue"><?php echo $UItext["MN-Evo-earnings-b"]; ?></span>
	<p class="small">
		<?php echo str_replace("###", (string) floor((time() - strtotime("2014-01-18 00:00:00")) / (365 * 24 * 60 * 60)), boldify($UItext["proven-crypto"], "Roboto")); ?>
		<br><?php echo boldify($UItext["servers"], "Roboto"); ?>
		<br><?php echo $UItext["learn-more"]; ?><a href="https://www.dash.org/<?php echo $dashorglang; ?>/" target="_blank"><span class="Roboto-bold"><b>Dash</b></span></a> &amp; <a href="https://docs.dash.org/<?php echo $docsdashlang;?>/stable/docs/user/masternodes/" target="_blank"><span class="Roboto-bold"><b><?php echo $UItext["MN-Evo"]; ?></b></span></a>.
	</p>
	<p class="smaller">
		<br><?php echo str_replace("###", "<span class=\"Roboto-bold\">" . $fmt->format(time()) . "</span>", $UItext["page-refreshed"]) . $timezonemention; ?>.
		<br><?php echo $UItext["approx"]; ?> <a href="#" data-tippy-content="“Do Your Own Research”.<br>(<?php echo $UItext["DYOR"]; ?>)">DYOR.</a> <?php echo str_replace("###", (string) boldify($UItext["disclaimer"], ""), $UItext["disclaimer-link"]); ?>
		<br><span class="Roboto-bold"><b><?php echo $UItext["hover-any"]; ?></b></span>
	</p>
	
	<!-- SETTINGS box ================================= -->
	<div class="box boxborder boxsmall">
		<div class="subtitle subsubtitle"><span class="bold">⚙️</span> <?php echo $UItext["settings"]; ?></div>
		<?php echo $UItext["fiat"]; ?>&nbsp;: <select class="menu" name="fiat" id="fiatselect" onChange="changefiat();"><?php echo implode("", $fiatoptions); ?></select>
		<br>
		<?php echo $UItext["language"]; ?>&nbsp;: <select class="menu" name="lang" id="langselect" onChange="changelang();"><?php echo implode("", $langoptions); ?></select>
	</div>

	<!-- LINKS box ================================= -->
	<div class="box boxborder boxsmall">
		<div class="subtitle subsubtitle"><span class="bold">👋</span> <?php echo $UItext["info-help"]; ?></div>
		<p class="small">
			<a href="https://www.dash.org/<?php echo $dashorglang;?>/" target="_blank"><span class="Roboto-bold"><b>Dash.org</b></span></a> | <a href="https://docs.dash.org/<?php echo $docsdashlang; ?>/stable/docs/user/masternodes/" target="_blank">masternodes &amp; Evonodes</a> | <a href="https://discordapp.com/invite/PXbUxJB" target="_blank"><span class="Roboto-bold"><b>Dash Discord</b></span></a> | <a href="https://twitter.com/Dashpay" target="_blank">Dash X</a> | <a href="https://www.dash.org/forum/" target="_blank">Dash forum</a> | <a href="https://reddit.com/r/dashpay/" target="_blank">Dash Reddit</a>
		</p>
	</div>
	
	<div class="box boxborder boxsmall" style="cursor: pointer;" onClick="sharePage();">
		<p class="small">🔗 <?php echo $UItext["share"]; ?> ⤴️</p>
	</div>

</div> <!-- end of title box -->

</td>

<td class="stuff">

<!-- MARKET PRICE box ================================= -->
<div class="box boxborder boxunfold">
	<div class="subtitle"><span class="bold">📊</span>&nbsp;&nbsp;<?php echo $UItext["market-price"]; ?>
		<div class="bubble" data-tippy-content="<?php echo $UItext["provided-CoinGecko"]; ?>, <?php echo $fmt->format($data["lastPrices"]["USD"]["time"]["timestamp"]); ?>.<br>(<?php echo $UItext["provided-Frankfurter"]; ?>, <?php echo $fmt->format($data["lastPrices"]["conversion_rates"]["now"]["time"]["timestamp"]); ?>.)">
			<?php echo $UItext["today"]; ?>
			<span class="info">ℹ️</span>
		</div>
	</div>
	<span class="subblock">
		<span class="blue bigger bold"><span class="bold"><b><?php echo pretty($currentprice[$fiat], 2) . "</b></span></span> " . $fiatcurrencies[$fiat]["symbol"]; ?> / <img alt="Đ" src="images/black-d-250.png" class="D">
		<?php echo $pricealert; ?>
	</span>
</div>

<!-- YEARLY EARNINGS box ================================= -->
<div class="box boxborder boxyearnings boxunfold" style="animation-duration: 1.7s;">
	<div class="subtitle"><span class="bold">🗓️</span>&nbsp;&nbsp;<?php echo $UItext["yearly-earnings"]; ?></span>
		<div class="bubble" data-tippy-content="<?php echo $UItext["XKCD-functions"]; ?>">
			<?php echo $UItext["today"]; ?>
			<span class="info">ℹ️</span>
		</div>
		<img src="images/Dash-yield.png" class="tree">
	</div>

	<!-- 1 Masternode ============ -->
	<div class="subblock" data-tippy-content="<?php echo $UItext["MN-collateral"]; ?>">
		<span class="bold"><b><span id="MN-number">1</span> Masternode</b></span>, <?php echo $UItext["collateral"]; ?> 
		<img alt="Đ" src="images/black-d-250.png" class="D">
		<input id="coll-MN" type="number" value="1000" min="1" step="any" placeholder="1000" data-last-valid="1000" class="partial" onInput="partial('MN');" data-tippy-content="<?php echo $UItext["MN-collateral-edit"]; ?>" data-tippy-placement="bottom">
		<span class="info">ℹ️</span>
	</div>
	<div class="subblock">
		<span class="arrow newline">→</span> 
		<span class="green"><span class="about">≈</span>&nbsp;<?php echo pretty($APY["MN"], 2); ?> %</span> 
		<span class="arrow">→</span> 
		<span class="about">≈</span>&nbsp;<img alt="Đ" src="images/black-d-250.png" class="D"> 
		<span id="MN-earning" data-placeholder="<?php echo $data["rewards"]["yearly"]["MN"]["DASH"]; ?>"><?php echo pretty($data["rewards"]["yearly"]["MN"]["DASH"], 1) ; ?></span><span class="peryear">&nbsp;/&nbsp;<?php echo $UItext["year"]; ?></span>
		<div class="bubble" data-tippy-content="<?php echo boldify($UItext["MN-varying"], ""); ?>">
				<?php echo $UItext["percent-stable"]; ?>
				<span class="info">ℹ️</span>
		</div>
	</div>
	<div class="subblock right">
		<span class="arrow">↪︎</span> 
		<?php echo $UItext["worth"]; ?> <span class="about">≈</span>&nbsp;<?php echo $fiatcurrencies[$fiat]["symbol"]; ?> <span id="MN-fiat-earning" data-placeholder="<?php echo $data["rewards"]["yearly"]["MN"][$fiat]; ?>"><?php echo pretty(round($data["rewards"]["yearly"]["MN"][$fiat], 0), 0); ?></span><span class="peryear">&nbsp;/&nbsp;<?php echo $UItext["year"]; ?></span>
		<div class="bubble" data-tippy-content="<?php echo str_replace(array("###", "§§§"), array($fiatcurrencies[$fiat]["symbol"], $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . number_format($currentprice[$fiat], 2)), boldify($UItext["MN-1-year-simulation"], "") ); ?>">
				<?php echo $UItext["price-stable"]; ?>
				<span class="info">ℹ️</span>
		</div>
	</div>
	
	<hr>
	
	<!-- 1 Evonode ============ -->
	<div class="subblock" data-tippy-content="<?php echo $UItext["Evo-collateral"]; ?>">
		<span class="bold"><b><span id="Evo-number">1</span> Evonode</b></span>, <?php echo $UItext["collateral"]; ?> 
		<img alt="Đ" src="images/black-d-250.png" class="D">
		<input id="coll-Evo" type="number" value="4000" min="1" step="any" placeholder="4000" data-last-valid="4000" class="partial" onInput="partial('Evo');" data-tippy-content="<?php echo $UItext["Evo-collateral-edit"]; ?>" data-tippy-placement="bottom">
		<span class="info">ℹ️</span>
	</div>
	<div class="subblock">
		<span class="arrow newline">→</span> 
		<span class="green"><span class="about">≈</span>&nbsp;<?php echo pretty($APY["Evo"], 2); ?> %</span> 
		<span class="arrow">→</span> 
		<span class="about">≈</span>&nbsp;<img alt="Đ" src="images/black-d-250.png" class="D"> 
		<span id="Evo-earning" data-placeholder="<?php echo $data["rewards"]["yearly"]["Evo"]["DASH"]; ?>"><?php echo pretty($data["rewards"]["yearly"]["Evo"]["DASH"], 1) ; ?></span><span class="peryear">&nbsp;/&nbsp;<?php echo $UItext["year"]; ?></span>
		<div class="bubble" data-tippy-content="<?php echo boldify($UItext["Evo-varying"], ""); ?>">
				<?php echo $UItext["percent-stable"]; ?>
				<span class="info">ℹ️</span>
		</div>
	</div>
	<div class="subblock right">
		<span class="arrow">↪︎</span> 
		<?php echo $UItext["worth"]; ?> <span class="about">≈</span>&nbsp;<?php echo $fiatcurrencies[$fiat]["symbol"]; ?> <span id="Evo-fiat-earning" data-placeholder="<?php echo $data["rewards"]["yearly"]["Evo"][$fiat]; ?>"><?php echo pretty(round($data["rewards"]["yearly"]["Evo"][$fiat], 0), 0); ?></span><span class="peryear">&nbsp;/&nbsp;<?php echo $UItext["year"]; ?></span>
		<div class="bubble" data-tippy-content="<?php echo str_replace(array("###", "§§§"), array($fiatcurrencies[$fiat]["symbol"], $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . number_format($currentprice[$fiat], 2)), boldify($UItext["Evo-1-year-simulation"], "")); ?>">
				<?php echo $UItext["price-stable"]; ?>
				<span class="info">ℹ️</span>
		</div>
	</div>
</div>


<!-- ONE-YEAR-AGO SIMULATION box ================================= -->
<div class="box boxborder boxunfold" style="animation-duration: 2s;">
	<div class="subtitle"><span class="bold">🧮</span>&nbsp;&nbsp;“<?php echo $UItext["earnings-1-year-ago"]; ?>”
		<div class="bubble" data-tippy-content="<?php echo $UItext["way-to-estimate"]; ?>">
			<span class="info">ℹ️</span>
		</div>
	</div>
	
	<!-- 1 Masternode ============ -->
	<span class="subblock">
		<span class="bold"><b>1 Masternode</b></span>
	</span>
	<div class="subblock">
		<span class="arrow newline">→</span> 
		<?php echo $UItext["I-bought"]; ?> <?php echo str_replace("#DASH#", (string)"<img alt=\"Đ\" src=\"images/black-d-250.png\" class=\"D\">", $UItext["1000-collateral"]); ?>
		<?php echo "<span class=\"about\">≈</span>&nbsp;" . $fiatcurrencies[$fiat]["symbol"] . " " . pretty($collateralvalue["MN"][$fiat]["365d"], 0); ?>
		<div class="bubble" data-tippy-content="<?php echo str_replace(array("###", "§§§", "@@@"), array($fiatcurrencies[$fiat]["symbol"], $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . number_format($past365dprice[$fiat], 2), $daysago365), $UItext["approx-MN-collateral-1-year-ago"]); ?>">
				<?php echo $UItext["1-year-ago"]; ?>
				<span class="info">ℹ️</span>
		</div>
	</div>
	<br class="flat">
	<div class="subblock left">
		<span class="arrow indentright">↪︎</span>
		<?php echo $UItext["then-earned"]; ?> <span class="about">≈</span>&nbsp;<span class="green"><img alt="Đ" src="images/black-d-250.png" class="D"> <?php echo pretty($data["simulationpast365d"]["rewardspast365d"]["MN"]["DASH365d"], 1); ?></span> 
		<div class="bubble" data-tippy-content="<?php echo str_replace("###", (string)$data["simulationpast365d"]["rewardspast365d"]["MN"]["APY365d"], $UItext["MN-approx-APY"]); ?>">
				<?php echo $UItext["during-365-days"]; ?>
				<span class="info">ℹ️</span>
		</div>
	</div>
	<div class="subblock">
		<span class="arrow newline">→</span> 
		<?php echo $UItext["worth"]; ?> <span class="about">≈</span>&nbsp;<span class="green"><?php echo $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . pretty(round($data["simulationpast365d"]["rewardspast365d"]["MN"][$fiat], 0), 0); ?></span>
		<div class="bubble" data-tippy-content="<?php echo str_replace(array("###", "§§§"), array($fiatcurrencies[$fiat]["symbol"], $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . number_format($currentprice[$fiat], 2)), $UItext["MN-approx-earnings-1-year"]); ?>">
				<?php echo $UItext["today"]; ?>
				<span class="info">ℹ️</span>
		</div>
	</div>
	<br class="flat">
	<div class="subblock left">
		<span class="arrow indentright">↪︎</span>
		<i><?php echo $UItext["whereas-my"]; ?> <?php echo str_replace("#DASH#", (string)"<img alt=\"Đ\" src=\"images/black-d-250.png\" class=\"D\">", $UItext["1000-worth"]); ?> <span class="about">≈</span>&nbsp;<?php echo "<span class=\"" . $collateralcolour["MN"] . "\">" . $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . pretty($collateralvalue["MN"][$fiat]["current"], 0); ?></span></i>
		<div class="bubble" data-tippy-content="<?php echo str_replace(array("###", "§§§"), array($fiatcurrencies[$fiat]["symbol"], $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . number_format($currentprice[$fiat], 2)), $UItext["1000-worth-today"]); ?>">
				<?php echo $UItext["today"]; ?>
				<span class="info">ℹ️</span>
		</div>
	</div>
	
	<hr>
	
	<!-- 1 Evonode ============ -->
	<span class="subblock">
		<span class="bold"><b>1 Evonode</b></span>
	</span>
	<div class="subblock">
		<span class="arrow newline">→</span> 
		<?php echo $UItext["I-bought"]; ?> <?php echo str_replace("#DASH#", (string)"<img alt=\"Đ\" src=\"images/black-d-250.png\" class=\"D\">", $UItext["4000-collateral"]); ?> 
		<?php echo "<span class=\"about\">≈</span>&nbsp;" . $fiatcurrencies[$fiat]["symbol"] . " " . pretty($collateralvalue["Evo"][$fiat]["365d"], 0); ?>
		<div class="bubble" data-tippy-content="<?php echo str_replace(array("###", "§§§", "@@@"), array($fiatcurrencies[$fiat]["symbol"], $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . number_format($past365dprice[$fiat], 2), $daysago365), $UItext["approx-Evo-collateral-1-year-ago"]); ?>">
				<?php echo $UItext["1-year-ago"]; ?>
				<span class="info">ℹ️</span>
		</div>
	</div>
	<br class="flat">
	<div class="subblock left">
		<span class="arrow indentright">↪︎</span>
		<?php echo $UItext["then-earned"]; ?> <span class="about">≈</span>&nbsp;<span class="green"><img alt="Đ" src="images/black-d-250.png" class="D"> <?php echo pretty($data["simulationpast365d"]["rewardspast365d"]["Evo"]["DASH365d"], 1); ?></span> 
		<div class="bubble" data-tippy-content="<?php echo str_replace("###", (string)$data["simulationpast365d"]["rewardspast365d"]["Evo"]["APY365d"], $UItext["Evo-approx-APY"]); ?>">
				<?php echo $UItext["during-365-days"]; ?>
				<span class="info">ℹ️</span>
		</div>
	</div>
	<div class="subblock">
		<span class="arrow newline">→</span> 
		<?php echo $UItext["worth"]; ?> <span class="about">≈</span>&nbsp;<span class="green"><?php echo $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . pretty(round($data["simulationpast365d"]["rewardspast365d"]["Evo"][$fiat], 0), 0); ?></span>
		<div class="bubble" data-tippy-content="<?php echo str_replace(array("###", "§§§"), array($fiatcurrencies[$fiat]["symbol"], $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . number_format($currentprice[$fiat], 2)), $UItext["Evo-approx-earnings-1-year"]); ?>">
				<?php echo $UItext["today"]; ?>
				<span class="info">ℹ️</span>
		</div>
	</div>
	<br class="flat">
	<div class="subblock left">
		<span class="arrow indentright">↪︎</span>
		<i><?php echo $UItext["whereas-my"]; ?> <?php echo str_replace("#DASH#", (string)"<img alt=\"Đ\" src=\"images/black-d-250.png\" class=\"D\">", $UItext["4000-worth"]); ?> <span class="about">≈</span>&nbsp;<?php echo "<span class=\"" . $collateralcolour["Evo"] . "\">" . $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . pretty($collateralvalue["Evo"][$fiat]["current"], 0); ?></span></i>
		<div class="bubble" data-tippy-content="<?php echo str_replace(array("###", "§§§"), array($fiatcurrencies[$fiat]["symbol"], $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . number_format($currentprice[$fiat], 2)), $UItext["4000-worth-today"]); ?>">
				<?php echo $UItext["today"]; ?>
				<span class="info">ℹ️</span>
		</div>
	</div>
</div>


</td>
</tr></table>

<script>
	tippy('[data-tippy-content]', { maxWidth: 300, zIndex: 30000, placement: 'top', allowHTML: true });
</script>

</body></html>
