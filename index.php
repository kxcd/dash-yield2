<?php declare(strict_types=1);
// $development_mode = true;
// ================================== PARAMETERS ==================================
require "configs/config.php";

// Functions ===============
function version_asset(string $relative_path): string {
	$absolute_path = __DIR__ . "/" . $relative_path;
	$version = file_exists($absolute_path) ? filemtime($absolute_path) : time();
	return $relative_path . "?x=" . $version;
}
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


// Selected time scale (yearly/monthly) for earnings ===============
$timescale = "yearly"; // default
if (isset($_COOKIE["timescale"]) && in_array($_COOKIE["timescale"], array("yearly", "monthly"))) { // cookie
	$timescale = $_COOKIE["timescale"];
}
if (isset($_GET["timescale"]) and in_array($_GET["timescale"], array("yearly", "monthly"))) { // GET, so new cookie
	$timescale = $_GET["timescale"];
	setcookie("timescale", $timescale, [
		"expires"  => time() + 60 * 60 * 24 * 365,
		"path"     => "/",
		"samesite" => "Lax",
	]);
}
$timescaleselected[$timescale] = " selected";
if ($timescale == "monthly")
	$timescalemention = $UItext["month"];
else
	$timescalemention = $UItext["year"];


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
		<script>
		// localStorage.removeItem('dash-yield-theme');
		(function () {
			const key = 'dash-yield-theme';
			const saved = localStorage.getItem(key);
			const theme = saved || (window.matchMedia &&
				window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
			// alert(theme);
			document.documentElement.dataset.theme = theme;
		})();
		</script>
		<link rel="stylesheet" href="<?php echo version_asset("style.css"); ?>" type="text/css">
		<link rel="stylesheet" href="<?php echo version_asset("style-dark.css"); ?>" type="text/css">
		<link rel="stylesheet" href="<?php echo version_asset("style-interactions.css"); ?>" type="text/css">
		<link rel="stylesheet" href="<?php echo version_asset("style-smartphone.css"); ?>" type="text/css">
		<script src="<?php echo version_asset("JS/scripts.js"); ?>"></script>
		<script src="JS/tippy/popper2.11.8.js"></script>
		<script src="JS/tippy/tippy6.3.7.js"></script>
		<script> const JSalert = "<?php echo $UItext["JSalert"]; ?>"; </script>
	</head>
	
<body>

<div id="visitAnimation" class="visit-animation" hidden aria-hidden="true">
	<img src="images/animation.svg" alt="" class="visit-animation-svg">
</div>


<img class="corner-art" src="images/Dash-yield-corner.png" alt="" aria-hidden="true">

<main class="page-shell">

	<header class="page-header">
	
		<section class="brand-panel">
			<a class="refresh brand-link" href="./">
				<span class="banner">LIVE</span>
				<img src="images/dash_digitalcash.png"
					 alt="<?php echo $UItext["Dash-yield"] . " - " . $UItext["MN-Evo-earnings"]; ?>"
					 title="<?php echo $UItext["Dash-yield"] . " - " . $UItext["MN-Evo-earnings"] ; ?>"
					 class="logo">
				<span class="subbrand">yield</span>
			</a>
			<div class="brand-tagline"><?php echo $UItext["MN-Evo-earnings-b"]; ?></div>
		</section>

		<section class="intro-panel">
			<p class="small intro-text">
				<?php echo str_replace("###", (string) floor((time() - strtotime("2014-01-18 00:00:00")) / (365 * 24 * 60 * 60)), boldify($UItext["proven-crypto"], "Roboto")); ?>
				<?php echo boldify($UItext["servers"], "Roboto"); ?>
			</p>
			<p class="small intro-links">
			<?php echo $UItext["learn-more"]; ?><a href="https://www.dash.org/<?php echo $dashorglang; ?>/" target="_blank"><span class="Roboto-bold"><b>Dash</b></span></a> &amp; <a href="https://docs.dash.org/<?php echo $docsdashlang;?>/stable/docs/user/masternodes/" target="_blank"><span class="Roboto-bold"><b><?php echo $UItext["MN-Evo"]; ?></b></span></a>.
			</p>
		</section>

		<section class="meta-panel">
			<p class="smaller">
				<?php echo str_replace("###", "<span class=\"Roboto-bold\">" . $fmt->format(time()) . "</span>", $UItext["page-refreshed"]) . $timezonemention; ?>.
			</p>
			<p class="smaller">
				<?php echo $UItext["approx"]; ?> <a href="#" data-tippy-content="“Do Your Own Research”.<br>(<?php echo $UItext["DYOR"]; ?>)"><b>DYOR</b>.</a> <?php echo str_replace("###", (string) boldify($UItext["disclaimer"], ""), $UItext["disclaimer-link"]); ?>
			</p>
			<p class="smaller hint"><?php echo $UItext["hover-any"]; ?></p>
			<div class="theme-toggle">
				<label for="themeToggle"><?php echo $UItext["mode"]; ?>&nbsp;:</label>
				<button type="button" id="themeToggle" class="theme-toggle-button"
					aria-pressed="false" aria-label="<?php echo $UItext["switchdarkmode"]; ?>">
					<span class="theme-icon" aria-hidden="true">☾</span>
					<span class="theme-toggle-text"><?php echo $UItext["nightmode"]; ?></span>
				</button>
			</div>
		</section>

	</header>

	<section class="utility-row">

		<!-- SETTINGS box ================================= -->
		<div class="box utility-box">
			<div class="subtitle subsubtitle"><span class="bold">⚙️</span> <?php echo $UItext["settings"]; ?></div>
			<div class="control-row">
				<label for="fiatselect"><?php echo $UItext["fiat"]; ?>&nbsp;:</label>
				<select class="menu" name="fiat" id="fiatselect" onChange="changefiat();"><?php echo implode("", $fiatoptions); ?></select>
			</div>
			<div class="control-row">
				<label for="langselect"><?php echo $UItext["language"]; ?>&nbsp;:</label>
				<select class="menu" name="lang" id="langselect" onChange="changelang();"><?php echo implode("", $langoptions); ?></select>
			</div>
			<div class="control-row">
				<label for="timescale"><?php echo $UItext["timescale"]; ?>&nbsp;:</label>
				<select class="menu" name="timescale" id="timescaleselect" onChange="changetimescale();"><option class="menu" value="yearly"<?php if (array_key_exists("yearly", $timescaleselected)) echo $timescaleselected["yearly"]; ?>><?php echo $UItext["yearly"]; ?></option><option class="menu" value="monthly"<?php if (array_key_exists("monthly", $timescaleselected)) echo $timescaleselected["monthly"]; ?>><?php echo $UItext["monthly"]; ?></option></select>
			</div>
		</div>

		<!-- LINKS box ================================= -->
		<div class="box utility-box">
			<div class="subtitle subsubtitle"><span class="bold">👋</span> <?php echo $UItext["info-help"]; ?></div>
			<p class="small utility-links">
				<a href="https://www.dash.org/<?php echo $dashorglang;?>/" target="_blank" rel="noopener"><span class="Roboto-bold"><b>Dash.org</b></span></a>
				<span class="separator">·</span>
				<a href="https://docs.dash.org/<?php echo $docsdashlang; ?>/stable/docs/user/masternodes/" target="_blank" rel="noopener">masternodes &amp; Evonodes</a>
				<span class="separator">·</span>
				<a href="https://discordapp.com/invite/PXbUxJB" target="_blank" rel="noopener"><span class="Roboto-bold"><b>Dash Discord</b></span></a>
				<span class="separator">·</span>
				<a href="https://twitter.com/Dashpay" target="_blank" rel="noopener">Dash X</a>
				<span class="separator">·</span>
				<a href="https://www.dash.org/forum/" target="_blank" rel="noopener">Dash forum</a>
				<span class="separator">·</span>
				<a href="https://reddit.com/r/dashpay/" target="_blank" rel="noopener">Dash Reddit</a>
			</p>
			<div class="new">👉 <?php echo str_replace("§§§", (string)"javascript:sharedMN('" . $timescale . "');", $UItext["sharedMNs"]); ?></div>
		</div>

		<button class="box utility-box share-box" type="button" onClick="sharePage();">
			<span>🔗 <?php echo $UItext["share"]; ?> ⤴️</span>
		</button>

	</section>

	<section class="dashboard">

	<!-- MARKET PRICE box ================================= -->
		<article class="box metric-card market-card boxborder boxunfold">
			<div class="subtitle">
				<span class="bold">📊</span>&nbsp;&nbsp;<?php echo $UItext["market-price"]; ?>
				<div class="bubble" data-tippy-content="<?php echo $UItext["provided-CoinGecko"]; ?>, <?php echo $fmt->format($data["lastPrices"]["USD"]["time"]["timestamp"]); ?>.<br>(<?php echo $UItext["provided-Frankfurter"]; ?>, <?php echo $fmt->format($data["lastPrices"]["conversion_rates"]["now"]["time"]["timestamp"]); ?>.)">
					<?php echo $UItext["today"]; ?> <span class="info">ℹ️</span>
				</div>
			</div>
			<div class="metric-value">
				<span class="blue bigger bold"><b><?php echo pretty($currentprice[$fiat], 2) . "</b></span> " . $fiatcurrencies[$fiat]["symbol"]; ?> / <img alt="Đ" src="images/black-d-250.png" class="D dash-logo">
				<?php echo $pricealert; ?>
			</div>
		</article>

		<!-- YEARLY / MONTHLY EARNINGS box ================================= -->
		<article class="box metric-card yearly-card boxborder boxunfold">
			<div class="subtitle">
				<span class="bold">🗓️</span>&nbsp;&nbsp;<?php echo $UItext[$timescale . "-earnings"]; ?></span>
				<div class="bubble" data-tippy-content="<?php echo $UItext["XKCD-functions"]; ?>">
					<?php echo $UItext["today"]; ?> <span class="info">ℹ️</span>
				</div>
			</div>

			<!-- 1 Masternode ============ -->
			<div class="node-grid">

				<section class="node-card" data-tippy-content="<?php echo $UItext["MN-collateral"]; ?>" data-tippy-placement="top-start">
					<div class="node-title">
						<span class="bold"><b><span id="MN-number">1</span> Masternode</b></span>
						<span class="node-setting" data-tippy-content="<?php echo $UItext["MN-collateral-edit"]; ?>" data-tippy-placement="bottom"><?php echo $UItext["collateral"]; ?> <img alt="Đ" src="images/black-d-250.png" class="D dash-logo">
							<input id="coll-MN" type="number" value="1000" min="1" step="any" placeholder="1000" data-last-valid="1000" class="partial" onInput="partial('MN', '<?php echo $timescale; ?>');">
							<span class="info">ℹ️</span>
						</span>
					</div>
					<div class="result-line">
						<span class="arrow">→</span>
						<span class="green"><span class="about">≈</span>&nbsp;<?php echo pretty($APY["MN"], 2); ?> %</span>
						<span class="arrow">→</span>
						<span class="about">≈</span>&nbsp;<img alt="Đ" src="images/black-d-250.png" class="D dash-logo">
						<span id="MN-earning" class="quitebold" data-placeholder="<?php echo $data["rewards"][$timescale]["MN"]["DASH"]; ?>"><?php echo pretty($data["rewards"][$timescale]["MN"]["DASH"], 1) ; ?></span>
						<span class="peryear">&nbsp;/&nbsp;<?php echo $timescalemention; ?></span>
						<div class="bubble" data-tippy-content="<?php echo boldify($UItext["MN-varying"], ""); ?>" data-tippy-placement="bottom">
							<?php echo $UItext["percent-stable"]; ?> <span class="info">ℹ️</span>
						</div>
					</div>
					<div class="result-note">
						<span class="arrow">↪︎</span>
						<?php echo $UItext["worth"]; ?> <span class="about">≈</span>&nbsp;<span class="quitebold"><?php echo $fiatcurrencies[$fiat]["symbol"]; ?> <span id="MN-fiat-earning" data-placeholder="<?php echo $data["rewards"][$timescale]["MN"][$fiat]; ?>"><?php echo pretty(round($data["rewards"][$timescale]["MN"][$fiat], 0), 0); ?></span></span>
						<span class="peryear">&nbsp;/&nbsp;<?php echo $timescalemention; ?></span>
						<div class="bubble" data-tippy-content="<?php echo str_replace(array("###", "§§§"), array($fiatcurrencies[$fiat]["symbol"], $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . number_format($currentprice[$fiat], 2)), boldify($UItext["MN-1-year-simulation"], "") ); ?>" data-tippy-placement="bottom">
							<?php echo $UItext["price-stable"]; ?> <span class="info">ℹ️</span>
						</div>
					</div>
				</section>

				<!-- 1 Evonode ============ -->
				<section class="node-card" data-tippy-content="<?php echo $UItext["Evo-collateral"]; ?>" data-tippy-placement="top-start">
					<div class="node-title">
						<span class="bold"><b><span id="Evo-number">1</span> Evonode</b></span>
						<span class="node-setting" data-tippy-content="<?php echo $UItext["Evo-collateral-edit"]; ?>" data-tippy-placement="bottom"><?php echo $UItext["collateral"]; ?> <img alt="Đ" src="images/black-d-250.png" class="D dash-logo">
							<input id="coll-Evo" type="number" value="4000" min="1" step="any" placeholder="4000" data-last-valid="4000" class="partial" onInput="partial('Evo', '<?php echo $timescale; ?>');">
							<span class="info">ℹ️</span>
						</span>
					</div>
					<div class="result-line">
						<span class="arrow">→</span>
						<span class="green"><span class="about">≈</span>&nbsp;<?php echo pretty($APY["Evo"], 2); ?> %</span>
						<span class="arrow">→</span>
						<span class="about">≈</span>&nbsp;<img alt="Đ" src="images/black-d-250.png" class="D dash-logo">
						<span id="Evo-earning" class="quitebold" data-placeholder="<?php echo $data["rewards"][$timescale]["Evo"]["DASH"]; ?>"><?php echo pretty($data["rewards"][$timescale]["Evo"]["DASH"], 1) ; ?></span>
						<span class="peryear">&nbsp;/&nbsp;<?php echo $timescalemention; ?></span>
						<div class="bubble" data-tippy-content="<?php echo boldify($UItext["Evo-varying"], ""); ?>" data-tippy-placement="bottom">
								<?php echo $UItext["percent-stable"]; ?> <span class="info">ℹ️</span>
						</div>
					</div>
					<div class="result-note">
						<span class="arrow">↪︎</span>
						<?php echo $UItext["worth"]; ?> <span class="about">≈</span>&nbsp;<span class="quitebold"><?php echo $fiatcurrencies[$fiat]["symbol"]; ?> <span id="Evo-fiat-earning" data-placeholder="<?php echo $data["rewards"][$timescale]["Evo"][$fiat]; ?>"><?php echo pretty(round($data["rewards"][$timescale]["Evo"][$fiat], 0), 0); ?></span></span>
						<span class="peryear">&nbsp;/&nbsp;<?php echo $timescalemention; ?></span>
						<div class="bubble" data-tippy-content="<?php echo str_replace(array("###", "§§§"), array($fiatcurrencies[$fiat]["symbol"], $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . number_format($currentprice[$fiat], 2)), boldify($UItext["Evo-1-year-simulation"], "")); ?>" data-tippy-placement="bottom">
							<?php echo $UItext["price-stable"]; ?> <span class="info">ℹ️</span>
						</div>
					</div>
				</section>
				

			</div>
			
		</article>

		
		<!-- ONE-YEAR-AGO SIMULATION box ================================= -->
		<article class="box historical-card boxborder boxunfold">
			<div class="subtitle">
				<span class="bold">🧮</span>&nbsp;&nbsp;“<?php echo str_replace("<br>", "", $UItext["earnings-1-year-ago"]); ?>”
				<div class="bubble" data-tippy-content="<?php echo $UItext["way-to-estimate"]; ?>">
					<span class="info">ℹ️</span>
				</div>
			</div>

			<!-- 1 Masternode ============ -->
			<div class="history-grid">

				<section class="history-node">
					<h3><span class="bold">1 Masternode</span></h3>

					<div class="history-line">
						<span class="arrow">→</span>
						<?php echo $UItext["I-bought"]; ?> <?php echo str_replace("#DASH#", (string)"<img alt=\"Đ\" src=\"images/black-d-250.png\" class=\"D dash-logo\">", $UItext["1000-collateral"]); ?>
						<?php echo "<span class=\"about\">≈</span>&nbsp;" . $fiatcurrencies[$fiat]["symbol"] . " " . pretty($collateralvalue["MN"][$fiat]["365d"], 0); ?>
						<div class="bubble" data-tippy-content="<?php echo str_replace(array("###", "§§§", "@@@"), array($fiatcurrencies[$fiat]["symbol"], $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . number_format($past365dprice[$fiat], 2), $daysago365), $UItext["approx-MN-collateral-1-year-ago"]); ?>">
							<?php echo $UItext["1-year-ago"]; ?> <span class="info">ℹ️</span>
						</div>
					</div>

					<div class="history-line indented">
						<span class="arrow">↪︎</span>
						<?php echo $UItext["then-earned"]; ?> <span class="about">≈</span>&nbsp;
						<span class="green"><img alt="Đ" src="images/black-d-250.png" class="D dash-logo"> <?php echo pretty($data["simulationpast365d"]["rewardspast365d"]["MN"]["DASH365d"], 1); ?></span>
						<div class="bubble" data-tippy-content="<?php echo str_replace("###", (string)$data["simulationpast365d"]["rewardspast365d"]["MN"]["APY365d"], $UItext["MN-approx-APY"]); ?>">
							<?php echo $UItext["during-365-days"]; ?> <span class="info">ℹ️</span>
						</div>
					</div>

					<div class="history-line indented">
						<span class="arrow">→</span>
						<?php echo $UItext["worth"]; ?> <span class="about">≈</span>&nbsp;<span class="green"><?php echo $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . pretty(round($data["simulationpast365d"]["rewardspast365d"]["MN"][$fiat], 0), 0); ?></span>
						<div class="bubble" data-tippy-content="<?php echo str_replace(array("###", "§§§"), array($fiatcurrencies[$fiat]["symbol"], $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . number_format($currentprice[$fiat], 2)), $UItext["MN-approx-earnings-1-year"]); ?>" data-tippy-placement="bottom">
							<?php echo $UItext["today"]; ?> <span class="info">ℹ️</span>
						</div>
					</div>

					<div class="history-line">
						<span class="arrow">↪︎</span>
						<i><?php echo $UItext["whereas-my"]; ?> <?php echo str_replace("#DASH#", (string)"<img alt=\"Đ\" src=\"images/black-d-250.png\" class=\"D dash-logo\">", $UItext["1000-worth"]); ?>
							<span class="about">≈</span>&nbsp;<?php echo "<span class=\"" . $collateralcolour["MN"] . "\">" . $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . pretty($collateralvalue["MN"][$fiat]["current"], 0); ?></span></i>
						<div class="bubble" data-tippy-content="<?php echo str_replace(array("###", "§§§"), array($fiatcurrencies[$fiat]["symbol"], $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . number_format($currentprice[$fiat], 2)), $UItext["1000-worth-today"]); ?>" data-tippy-placement="bottom">
							<?php echo $UItext["today"]; ?> <span class="info">ℹ️</span>
						</div>
					</div>
				</section>

				<!-- 1 Evonode ============ -->
				<section class="history-node">
					<h3><span class="bold">1 Evonode</span></h3>

					<div class="history-line">
						<span class="arrow">→</span>
						<?php echo $UItext["I-bought"]; ?> <?php echo str_replace("#DASH#", (string)"<img alt=\"Đ\" src=\"images/black-d-250.png\" class=\"D dash-logo\">", $UItext["4000-collateral"]); ?>
						<?php echo "<span class=\"about\">≈</span>&nbsp;" . $fiatcurrencies[$fiat]["symbol"] . " " . pretty($collateralvalue["Evo"][$fiat]["365d"], 0); ?>
						<div class="bubble" data-tippy-content="<?php echo str_replace(array("###", "§§§", "@@@"), array($fiatcurrencies[$fiat]["symbol"], $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . number_format($past365dprice[$fiat], 2), $daysago365), $UItext["approx-Evo-collateral-1-year-ago"]); ?>">
							<?php echo $UItext["1-year-ago"]; ?> <span class="info">ℹ️</span>
						</div>
					</div>

					<div class="history-line indented">
						<span class="arrow">↪︎</span>
						<?php echo $UItext["then-earned"]; ?> <span class="about">≈</span>&nbsp;
						<span class="green"><img alt="Đ" src="images/black-d-250.png" class="D dash-logo"> <?php echo pretty($data["simulationpast365d"]["rewardspast365d"]["Evo"]["DASH365d"], 1); ?></span>
						<div class="bubble" data-tippy-content="<?php echo str_replace("###", (string)$data["simulationpast365d"]["rewardspast365d"]["Evo"]["APY365d"], $UItext["Evo-approx-APY"]); ?>">
							<?php echo $UItext["during-365-days"]; ?> <span class="info">ℹ️</span>
						</div>
					</div>

					<div class="history-line indented">
						<span class="arrow">→</span>
						<?php echo $UItext["worth"]; ?> <span class="about">≈</span>&nbsp;<span class="green"><?php echo $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . pretty(round($data["simulationpast365d"]["rewardspast365d"]["Evo"][$fiat], 0), 0); ?></span>
						<div class="bubble" data-tippy-content="<?php echo str_replace(array("###", "§§§"), array($fiatcurrencies[$fiat]["symbol"], $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . number_format($currentprice[$fiat], 2)), $UItext["Evo-approx-earnings-1-year"]); ?>" data-tippy-placement="bottom">
							<?php echo $UItext["today"]; ?> <span class="info">ℹ️</span>
						</div>
					</div>

					<div class="history-line">
						<span class="arrow">↪︎</span>
						<i><?php echo $UItext["whereas-my"]; ?> <?php echo str_replace("#DASH#", (string)"<img alt=\"Đ\" src=\"images/black-d-250.png\" class=\"D dash-logo\">", $UItext["4000-worth"]); ?>
							<span class="about">≈</span>&nbsp;<?php echo "<span class=\"" . $collateralcolour["Evo"] . "\">" . $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . pretty($collateralvalue["Evo"][$fiat]["current"], 0); ?></span></i>
						<div class="bubble" data-tippy-content="<?php echo str_replace(array("###", "§§§"), array($fiatcurrencies[$fiat]["symbol"], $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . number_format($currentprice[$fiat], 2)), $UItext["4000-worth-today"]); ?>" data-tippy-placement="bottom">
							<?php echo $UItext["today"]; ?> <span class="info">ℹ️</span>
						</div>
					</div>
				</section>

			</div>
		</article>

	</section>

</main>

<script>
(function () {
	'use strict';

	const THEME_KEY = 'dash-yield-theme';
	const VISIT_KEY = 'dash-yield-last-visit';
	const FORTY_EIGHT_HOURS = 48 * 60 * 60 * 1000;

	const root = document.documentElement;
	const toggle = document.getElementById('themeToggle');
	const toggleIcon = toggle ? toggle.querySelector('.theme-icon') : null;
	const toggleText = toggle ? toggle.querySelector('.theme-toggle-text') : null;

	function setTheme(theme, persist) {
		const isDark = theme === 'dark';
		root.dataset.theme = isDark ? 'dark' : 'light';

		if (persist) {
			localStorage.setItem(THEME_KEY, isDark ? 'dark' : 'light');
		}

		if (toggle) {
			toggle.setAttribute('aria-pressed', String(isDark));
			toggle.setAttribute(
				'aria-label',
				isDark ? '<?php echo $UItext["switchlightmode"]; ?>' : '<?php echo $UItext["switchdarkmode"]; ?>'
			);
		}
		if (toggleIcon) toggleIcon.textContent = isDark ? '☀' : '☾';
		if (toggleText) toggleText.textContent = isDark ? '<?php echo $UItext["daymode"]; ?>' : '<?php echo $UItext["nightmode"]; ?>';
	}

	/* The inline script in <head> already selected the initial theme:
	   explicit visitor choice > OS preference > light. */
	setTheme(root.dataset.theme || 'light', false);

	if (toggle) {
		toggle.addEventListener('click', function () {
			setTheme(root.dataset.theme === 'dark' ? 'light' : 'dark', true);
		});
	}

	// Show the intro animation only once each other day
	const now = Date.now();
	const lastAnimation = Number(localStorage.getItem(VISIT_KEY) || 0);
	const shouldShow =
		!lastAnimation ||
		(now - lastAnimation > FORTY_EIGHT_HOURS);
	if (shouldShow) {
		localStorage.setItem(VISIT_KEY, String(now));
	}

	const animation = document.getElementById('visitAnimation');
	if (shouldShow && animation) {
		animation.hidden = false;

		/* Keep the SVG visible long enough to qualify as a brief intro,
		   then let CSS perform the opacity fade. */
		animation.addEventListener('animationend', function () {
			animation.hidden = true;
		}, { once: true });
	}
})();
</script>

<script>
	tippy('[data-tippy-content]', { maxWidth: 300, zIndex: 30000, placement: 'top', allowHTML: true });
</script>

</body></html>
