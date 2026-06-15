<?php
// ================================== PARAMETERS ==================================
$development_mode = FALSE;
//$computeURL = "http://localhost".$_SERVER["REQUEST_URI"]."compute.php"; 
$computeURL = "https://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/compute.php"; // compute.php via HTTPS, in the case it is hosted elsewhere
include "./configs/config.php";



// Functions ===============
function pretty($number, $decimals) {
	$number = number_format($number, $decimals, ".", "&nbsp;");
	if (strpos($number, '.') === false)
		return $number;
	list($int, $dec) = explode('.', $number, 2);
	return $int . ".<span class=\"decimals\">" . $dec . "</span>";
}
function is_private_ip(string $ip): bool {
	return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}



// Selected fiat currency ===============
$fiat = "USD"; // default, except if cookie
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
} elseif (!is_private_ip($_SERVER["REMOTE_ADDR"])) { // if public IP is known (that block should be tested, not essential though)
	// detection of an European country (hence EUR)
	$Europe = array("AL","AD","AM","AT","AZ","BY","BE","BA","BG","CH","CY","CZ","DE","DK","EE","ES","FR","FI","FO","GB","GE","GI","GR","HR","HU","IE","IS","IT","KZ","LI","LT","LU","LV","MC","MD","ME","MK","MT","NL","NO","PL","PT","RO","RS","RU","SE","SI","SK","SM","TR","UA","VA");
	$IPAPI = file_get_contents("https://ipapi.co/" . $_SERVER["REMOTE_ADDR"] . "/country/");
	if (in_array(strtoupper(trim($IPAPI)), $Europe))
		$fiat = "EUR";
} // we should also detect Japan to auto-set JPY, etc.

$fiatselected = array_fill_keys(array_keys($fiatcurrencies), "");
$fiatselected[$fiat] = " selected";
$fiatoptions = array();
foreach ($fiatcurrencies as $fiatcode => $stuff)
	$fiatoptions[] = "<option class=\"menu\" value=\"" . $fiatcode . "\" " . $fiatselected[$fiatcode] . ">" . $stuff["name"] . " (" . $stuff["symbol"] . ")</option>";



// Selected language ===============
$lang = "en"; // default, except if cookie

// Detection through Accept-Language (low priority : smashed by cookie or GET)
if (isset($_SERVER["HTTP_ACCEPT_LANGUAGE"]) and !isset($_COOKIE["lang"]) and !isset($_GET["lang"])) {
	$accepted = $_SERVER["HTTP_ACCEPT_LANGUAGE"];
	preg_match_all('/([a-z]{2})(?:[_-][A-Z]{2})?(?:;q=([\d.]+))?/i', $accepted, $matches, PREG_SET_ORDER);
	usort($matches, fn($a, $b) => (float)($b[2] ?: 1) <=> (float)($a[2] ?: 1));
	foreach ($matches as $match) {
		$code = strtolower(substr($match[1], 0, 2));
		if (array_key_exists($code, $langnames)) {
			$lang = $code;
			break;
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
$rawJS = file_get_contents("./languages/" . $lang . ".json"); // gets the right JSON language file
$UItext = json_decode($rawJS, true);
// we could also modify the link to Dash Docs in the GUI in order to link to the right language



// Fetch calculated values by compute.php ===============
$ch = curl_init($computeURL);
curl_setopt_array($ch, [
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_TIMEOUT => 5,
	CURLOPT_USERPWD => "username:password", // only for local dev
]);
$return = curl_exec($ch);
if ($return === false)
	die("cURL error (3) : " . curl_error($ch));
curl_close($ch);
$data = json_decode($return, true);
if (!is_array($data))
	die("Invalid JSON response (1)");

$APY = reset($data["APY"]);
$USDprice[$fiat] = $data["lastPrices"]["conversion_rates"][$fiat];
$currentprice[$fiat] = round($data["lastPrices"]["USD"]["current"] * $USDprice[$fiat], 2);
$past365dprice[$fiat] = round($data["lastPrices"]["USD"]["365d"] * $USDprice[$fiat], 2); // it would be smarter to get *past* USD/fiat price
$daysago365 = date("jS F Y", $data["lastPrices"]["USD"]["time"]["timestamp"] - (365 * 24 * 60 * 60));


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
<html>
	<head>
		<title>Dash yield - masternodes &amp; Evonodes earnings</title>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width,initial-scale=1">
		<meta name="description" content="Dash yield, masternodes and Evonodes earnings calculator.">
		<meta property="og:title" content="Dash yield - masternodes and Evonodes earnings">
		<meta property="og:url" content="https://<?php echo $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"]; ?>">
		<meta property="og:image" content="images/favicons/favicon-192.png">
		<meta property="og:type" content="website">
		<meta property="og:description" content="Dash yield, masternodes and Evonodes earnings calculator.">
		<meta property="og:locale" content="en_US">
		<link rel="icon" href="images/favicons/favicon-32.png" sizes="32x32">
		<link rel="icon" href="images/favicons/favicon-128.png" sizes="128x128">
		<link rel="icon" href="images/favicons/favicon-192.png" sizes="192x192">
		<!-- Android -->
		<link rel="shortcut icon" sizes="192x192" href="images/favicons/favicon-192.png">
		<!-- iOS -->
		<link rel="apple-touch-icon" href="images/favicons/favicon-128.png" sizes="128x128">
		<link rel="apple-touch-icon" href="images/favicons/favicon-192.png" sizes="192x192">
		<link rel="stylesheet" href="style.css<?php echo $renewCSS; ?>" type="text/css">
		<script src="JS/scripts.js"></script>
		<script src="JS/tippy/popper2.11.8.js"></script>
		<script src="JS/tippy/tippy6.3.7.js"></script>
	</head>
	
<body onLoad="showLocalDate('.localdate');">

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
		<?php echo str_replace("###", (string)floor((time() - strtotime("2014-01-18 00:00:00")) / (365 * 24 * 60 * 60)), $UItext["proven-crypto"]); ?>
		<br><?php echo $UItext["servers"]; ?>
		<br><?php echo $UItext["learn-more"]; ?><a href="https://www.dash.org/" target="_blank"><b>Dash</b></a> &amp; <a href="https://docs.dash.org/en/stable/docs/user/masternodes/" target="_blank"><b><?php echo $UItext["MN-Evo"]; ?></b></a>.
	</p>
	<p class="smaller">
		<br><?php echo str_replace("###", date("d M. Y, H:i"), $UItext["page-refreshed"]); ?>
		<br><?php echo $UItext["approx"]; ?> <a href="#" data-tippy-content="“Do Your Own Research”.<br>(<?php echo $UItext["DYOR"]; ?>)">DYOR.</a>
		<br><b><?php echo $UItext["hover-any"]; ?></b>
	</p>
	
	<!-- SETTINGS box ================================= -->
	<div class="box boxborder boxsmall">
		<div class="subtitle subsubtitle">⚙️ <?php echo $UItext["settings"]; ?></div>
		<?php echo $UItext["fiat"]; ?>&nbsp;: <select class="menu" name="fiat" id="fiatselect" onChange="changefiat();"><?php echo implode("", $fiatoptions); ?></select>
		<br>
		<?php echo $UItext["language"]; ?>&nbsp;: <select class="menu" name="lang" id="langselect" onChange="changelang();"><?php echo implode("", $langoptions); ?></select>
	</div>

	<!-- LINKS box ================================= -->
	<div class="box boxborder boxsmall">
		<div class="subtitle subsubtitle">👋 <?php echo $UItext["info-help"]; ?></div>
		<p class="small">
			<b><a href="https://www.dash.org/" target="_blank">Dash.org</a></b> | <a href="https://docs.dash.org/en/stable/docs/user/masternodes/" target="_blank">masternodes &amp; Evonodes</a> | <b><a href="https://discordapp.com/invite/PXbUxJB" target="_blank">Dash Discord</a></b> | <a href="https://twitter.com/Dashpay" target="_blank">Dash X</a> | <a href="https://www.dash.org/forum/" target="_blank">Dash forum</a> | <a href="https://reddit.com/r/dashpay/" target="_blank">Dash Reddit</a>
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
	<div class="subtitle"><?php echo $UItext["market-price"]; ?>
		<div class="bubble" data-tippy-content="<?php echo $UItext["provided-CoinGecko"]; ?>,<br><?php echo date("jS F Y, H:i", $data["lastPrices"]["USD"]["time"]["timestamp"]); ?>.<br>(<?php echo $UItext["provided-ExchangeRate"]; ?>)">
			<?php echo $UItext["today"]; ?>
			<span class="info">ℹ️</span>
		</div>
	</div>
	<span class="subblock">
		<span class="blue bigger"><b><?php echo pretty($currentprice[$fiat], 2) . "</b></span> " . $fiatcurrencies[$fiat]["symbol"]; ?> / <img alt="Đ" src="images/black-d-250.png" class="D">
		<?php echo $pricealert; ?>
	</span>
</div>

<!-- YEARLY EARNINGS box ================================= -->
<div class="box boxborder boxyearnings boxunfold" style="animation-duration: 1.7s;">
	<div class="subtitle">🗓️&nbsp;&nbsp;<?php echo $UItext["yearly-earnings"]; ?></span>
		<div class="bubble" data-tippy-content="<?php echo $UItext["XKCD-functions"]; ?>">
			<?php echo $UItext["today"]; ?>
			<span class="info">ℹ️</span>
		</div>
	</div>
	<span class="subblock">
		<b><?php echo $UItext["1-masternode"]; ?></b>
		<div class="bubble" data-tippy-content="<?php echo $UItext["MN-collateral"]; ?>">
				<img alt="Đ" src="images/black-d-250.png" class="D"> 1000
				<span class="info">ℹ️</span>
		</div>
	</span>
	<span class="subblock">
		<span class="arrow">→</span> 
		<span class="green"><span class="about">≈</span>&nbsp;<?php echo pretty($APY["MN"], 2); ?> %</span> 
		<span class="arrow">→</span> 
		<span class="about">≈</span>&nbsp;<img alt="Đ" src="images/black-d-250.png" class="D"> 
		<?php echo pretty($data["rewards"]["yearly"]["MN"]["DASH"], 1) ; ?>
		<div class="bubble" data-tippy-content="<?php echo $UItext["MN-varying"]; ?>">
				<?php echo $UItext["percent-stable"]; ?>
				<span class="info">ℹ️</span>
		</div>
	</span>
	<br>
	<span class="subblock">
		
		<span class="arrow">↪︎</span> 
		<?php echo $UItext["worth"]; ?> <span class="about">≈</span>&nbsp;<?php echo $fiatcurrencies[$fiat]["symbol"] . " " . pretty(round($data["rewards"]["yearly"]["MN"][$fiat], 0), 0) ; ?>
		<div class="bubble" data-tippy-content="<?php echo str_replace(array("###", "§§§"), array($fiatcurrencies[$fiat]["symbol"], $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . number_format($currentprice[$fiat], 2)), $UItext["MN-1-year-simulation"]); ?>">
				<?php echo $UItext["price-stable"]; ?>
				<span class="info">ℹ️</span>
		</div>
	</span>
	<hr>
	<span class="subblock">
		<b><?php echo $UItext["1-Evonode"]; ?></b>
		<div class="bubble" data-tippy-content="<?php echo $UItext["Evo-collateral"]; ?>">
				<img alt="Đ" src="images/black-d-250.png" class="D"> 4000
				<span class="info">ℹ️</span>
		</div>
	</span>
	<span class="subblock">
		<span class="arrow">→</span> 
		<span class="green"><span class="about">≈</span>&nbsp;<?php echo pretty($APY["Evo"], 2); ?> %</span> 
		<span class="arrow">→</span> 
		<span class="about">≈</span>&nbsp;<img alt="Đ" src="images/black-d-250.png" class="D"> 
		<?php echo pretty($data["rewards"]["yearly"]["Evo"]["DASH"], 1) ; ?> 
		<div class="bubble" data-tippy-content="<?php echo $UItext["Evo-varying"]; ?>">
				<?php echo $UItext["percent-stable"]; ?>
				<span class="info">ℹ️</span>
		</div>
	</span>
	<br>
	<span class="subblock">
		<span class="arrow">↪︎</span> 
		<?php echo $UItext["worth"]; ?> <span class="about">≈</span>&nbsp;<?php echo $fiatcurrencies[$fiat]["symbol"] . " " . pretty(round($data["rewards"]["yearly"]["Evo"][$fiat], 0), 0) ; ?>
		<div class="bubble" data-tippy-content="<?php echo str_replace(array("###", "§§§"), array($fiatcurrencies[$fiat]["symbol"], $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . number_format($currentprice[$fiat], 2)), $UItext["Evo-1-year-simulation"]); ?>">
				<?php echo $UItext["price-stable"]; ?>
				<span class="info">ℹ️</span>
		</div>
	</span>
</div>


<!-- ONE-YEAR-AGO SIMULATION box ================================= -->
<div class="box boxborder boxunfold" style="animation-duration: 2s;">
	<div class="subtitle">🧮&nbsp;&nbsp;“<?php echo $UItext["earnings-1-year-ago"]; ?>”
		<div class="bubble" data-tippy-content="<?php echo $UItext["way-to-estimate"]; ?>">
			<span class="info">ℹ️</span>
		</div>
	</div>
	<span class="subblock">
		<b><?php echo $UItext["1-masternode"]; ?></b>
	</span>
	<span class="subblock">
		<span class="arrow">→</span> 
		<?php echo $UItext["I-bought"]; ?> <img alt="Đ" src="images/black-d-250.png" class="D"> <?php echo $UItext["1000-collateral"]; ?>
		<span class="arrow">→</span> 
		<?php echo "<span class=\"about\">≈</span>&nbsp;" . $fiatcurrencies[$fiat]["symbol"] . " " . pretty($collateralvalue["MN"][$fiat]["365d"], 0); ?>
		<div class="bubble" data-tippy-content="<?php echo str_replace(array("###", "§§§", "@@@"), array($fiatcurrencies[$fiat]["symbol"], $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . number_format($past365dprice[$fiat], 2), $daysago365), $UItext["approx-MN-collateral-1-year-ago"]); ?>">
				<?php echo $UItext["1-year-ago"]; ?>
				<span class="info">ℹ️</span>
		</div>
	</span>
	<br>
	<span class="subblock indentright">
		<span class="arrow">↪︎</span>
		<?php echo $UItext["then-earned"]; ?> <span class="about">≈</span>&nbsp;<span class="green"><img alt="Đ" src="images/black-d-250.png" class="D"> <?php echo pretty($data["simulationpast365d"]["rewardspast365d"]["MN"]["DASH365d"], 1); ?></span> 
		<div class="bubble" data-tippy-content="<?php echo str_replace("###", $data["simulationpast365d"]["rewardspast365d"]["MN"]["APY365d"], $UItext["MN-approx-APY"]); ?>">
				<?php echo $UItext["during-365-days"]; ?>
				<span class="info">ℹ️</span>
		</div>
	</span>
	<span class="subblock">
		<span class="arrow">→</span> 
		<?php echo $UItext["worth"]; ?> <span class="about">≈</span>&nbsp;<span class="green"><?php echo $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . pretty(round($data["simulationpast365d"]["rewardspast365d"]["MN"][$fiat], 0), 0); ?></span>
		<div class="bubble" data-tippy-content="<?php echo str_replace(array("###", "§§§"), array($fiatcurrencies[$fiat]["symbol"], $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . number_format($currentprice[$fiat], 2)), $UItext["MN-approx-earnings-1-year"]); ?>">
				<?php echo $UItext["today"]; ?>
				<span class="info">ℹ️</span>
		</div>
	</span>
	<br>
	<span class="subblock indentright">
		<span class="arrow">↪︎</span>
		<i><?php echo $UItext["whereas-my"]; ?> <img alt="Đ" src="images/black-d-250.png" class="D"> <?php echo $UItext["1000-worth"]; ?> <span class="about">≈</span>&nbsp;<?php echo "<span class=\"" . $collateralcolour["MN"] . "\">" . $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . pretty($collateralvalue["MN"][$fiat]["current"], 0); ?></span></i>
		<div class="bubble" data-tippy-content="<?php echo str_replace(array("###", "§§§"), array($fiatcurrencies[$fiat]["symbol"], $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . number_format($currentprice[$fiat], 2)), $UItext["1000-worth-today"]); ?>">
				<?php echo $UItext["today"]; ?>
				<span class="info">ℹ️</span>
		</div>
	</span>
	
	<hr>
	
	<span class="subblock">
		<b><?php echo $UItext["1-Evonode"]; ?></b>
	</span>
	<span class="subblock">
		<span class="arrow">→</span> 
		<?php echo $UItext["I-bought"]; ?> <img alt="Đ" src="images/black-d-250.png" class="D"> <?php echo $UItext["4000-collateral"]; ?> 
		<span class="arrow">→</span> 
		<?php echo "<span class=\"about\">≈</span>&nbsp;" . $fiatcurrencies[$fiat]["symbol"] . " " . pretty($collateralvalue["Evo"][$fiat]["365d"], 0); ?>
		<div class="bubble" data-tippy-content="<?php echo str_replace(array("###", "§§§", "@@@"), array($fiatcurrencies[$fiat]["symbol"], $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . number_format($past365dprice[$fiat], 2), $daysago365), $UItext["approx-Evo-collateral-1-year-ago"]); ?>">
				<?php echo $UItext["1-year-ago"]; ?>
				<span class="info">ℹ️</span>
		</div>
	</span>
	<br>
	<span class="subblock indentright">
		<span class="arrow">↪︎</span>
		<?php echo $UItext["then-earned"]; ?> <span class="about">≈</span>&nbsp;<span class="green"><img alt="Đ" src="images/black-d-250.png" class="D"> <?php echo pretty($data["simulationpast365d"]["rewardspast365d"]["Evo"]["DASH365d"], 1); ?></span> 
		<div class="bubble" data-tippy-content="<?php echo str_replace("###", $data["simulationpast365d"]["rewardspast365d"]["Evo"]["APY365d"], $UItext["Evo-approx-APY"]); ?>">
				<?php echo $UItext["during-365-days"]; ?>
				<span class="info">ℹ️</span>
		</div>
	</span>
	<span class="subblock">
		<span class="arrow">→</span> 
		<?php echo $UItext["worth"]; ?> <span class="about">≈</span>&nbsp;<span class="green"><?php echo $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . pretty(round($data["simulationpast365d"]["rewardspast365d"]["Evo"][$fiat], 0), 0); ?></span>
		<div class="bubble" data-tippy-content="<?php echo str_replace(array("###", "§§§"), array($fiatcurrencies[$fiat]["symbol"], $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . number_format($currentprice[$fiat], 2)), $UItext["Evo-approx-earnings-1-year"]); ?>">
				<?php echo $UItext["today"]; ?>
				<span class="info">ℹ️</span>
		</div>
	</span>
	<br>
	<span class="subblock indentright">
		<span class="arrow">↪︎</span>
		<i><?php echo $UItext["whereas-my"]; ?> <img alt="Đ" src="images/black-d-250.png" class="D"> <?php echo $UItext["4000-worth"]; ?> <span class="about">≈</span>&nbsp;<?php echo "<span class=\"" . $collateralcolour["Evo"] . "\">" . $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . pretty($collateralvalue["Evo"][$fiat]["current"], 0); ?></span></i>
		<div class="bubble" data-tippy-content="<?php echo str_replace(array("###", "§§§"), array($fiatcurrencies[$fiat]["symbol"], $fiatcurrencies[$fiat]["symbol"] . "&nbsp;" . number_format($currentprice[$fiat], 2)), $UItext["4000-worth-today"]); ?>">
				<?php echo $UItext["today"]; ?>
				<span class="info">ℹ️</span>
		</div>
	</span>
</div>


</td>
</tr></table>

<script>
	tippy('[data-tippy-content]', { maxWidth: 300, zIndex: 30000, placement: 'top', allowHTML: true });
</script>

</body></html>