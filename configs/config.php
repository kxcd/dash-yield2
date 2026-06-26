<?php declare(strict_types=1);

require ".config-compute.php";

// Fiat currencies (ordered by world volume)
$fiatcurrencies = array(
	"USD" => array("symbol" => "$",    "name" => "US dollar"),
	"EUR" => array("symbol" => "€",    "name" => "euro"),
	"JPY" => array("symbol" => "¥",    "name" => "yen"),
	"GBP" => array("symbol" => "£",    "name" => "sterling"),
	"CNY" => array("symbol" => "元",   "name" => "yuan"),
	"AUD" => array("symbol" => "AU$",  "name" => "Australian dollar"),
	"CAD" => array("symbol" => "CA$",  "name" => "Canadian dollar"),
	"CHF" => array("symbol" => "Fr.",  "name" => "Swiss franc"),
	"HKD" => array("symbol" => "港元", "name" => "Hong Kong dollar"),
	"SGD" => array("symbol" => "S$", "name" => "Singapour dollar"),
	"KRW" => array("symbol" => "₩", "name" => "won"),
	"SEK" => array("symbol" => "kr", "name" => "Swedish krona"),
	"NOK" => array("symbol" => "NKr", "name" => "Norwegian krone"),
	"NZD" => array("symbol" => "NZ$",  "name" => "New Zealand dollar"),
	"MXN" => array("symbol" => "Mex$",  "name" => "Mexican peso"),
	"INR" => array("symbol" => "₹",  "name" => "Indian rupee"),
	"TWD" => array("symbol" => "圓",  "name" => "New Taiwan dollar"),
	"ZAR" => array("symbol" => "R",  "name" => "rand"),
	"BRL" => array("symbol" => "R$",  "name" => "Brazilian real"),
	"DKK" => array("symbol" => "kr.",  "name" => "Danish krone"),
	"PLN" => array("symbol" => "zł",  "name" => "złoty"),
	"CZK" => array("symbol" => "Kč",  "name" => "koruna"),
	"RUB" => array("symbol" => "₽",  "name" => "ruble") // Russian ruble is incredibly low !
);

// Mapping country code (found via IP address) to currency code
$country_currency = array(
	// Euro zone
	"AD" => "EUR", "AT" => "EUR", "BE" => "EUR", "CY" => "EUR",
	"DE" => "EUR", "EE" => "EUR", "ES" => "EUR", "FI" => "EUR",
	"FR" => "EUR", "GR" => "EUR", "HR" => "EUR", "IE" => "EUR",
	"IT" => "EUR", "LT" => "EUR", "LU" => "EUR", "LV" => "EUR",
	"MC" => "EUR", "ME" => "EUR", "MT" => "EUR", "NL" => "EUR",
	"PT" => "EUR", "SI" => "EUR", "SK" => "EUR", "SM" => "EUR",
	"VA" => "EUR",
	// Other currencies listed in $fiatcurrencies
	"AU" => "AUD",
	"BR" => "BRL",
	"CA" => "CAD",
	"CH" => "CHF",
	"CN" => "CNY",
	"CZ" => "CZK",
	"DK" => "DKK",
	"FO" => "DKK",
	"GB" => "GBP",
	"GI" => "GBP",
	"HK" => "HKD",
	"IN" => "INR",
	"JP" => "JPY",
	"KR" => "KRW",
	"LI" => "CHF",
	"MX" => "MXN",
	"NO" => "NOK",
	"NZ" => "NZD",
	"PL" => "PLN",
	"RU" => "RUB",
	"SE" => "SEK",
	"SG" => "SGD",
	"TW" => "TWD",
	"US" => "USD",
	"ZA" => "ZAR",
);


// Dash Yield GUI languages
$langnames = array("en" => "English", "fr" => "français", "es" => "español", "it" => "italiano", "pl" => "polski");


date_default_timezone_set("Europe/Paris"); // how to manage that ?


// Development mode (FALSE or TRUE)
if ($development_mode) { 
	error_reporting(E_ALL);
	ini_set('display_errors', '1');
	ini_set('display_startup_errors', '1');
	$renewCSS = "?" . substr(bin2hex(random_bytes(6)), 0, 12);
} else {
	$renewCSS = "";
}


?>
