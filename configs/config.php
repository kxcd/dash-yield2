<?php

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


// Dash Yield GUI languages
$langnames = array("en" => "English", "fr" => "français", "es" => "español", "it" => "italiano");


date_default_timezone_set("Europe/Paris"); // how to manage that ?


// Development mode (FALSE or TRUE)
error_reporting('0');
if ($development_mode) { 
	error_reporting(E_ALL);
	ini_set('display_errors', '1');
	ini_set('display_startup_errors', '1');
	$renewCSS = "?" . substr(bin2hex(random_bytes(6)), 0, 12);
} else {
	$renewCSS = "";
}


?>