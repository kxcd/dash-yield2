<?php declare(strict_types=1);


// GET MN REWARD =================================

// Give us a height and we will give you the regular MN (L1) payment.
function getMnReward(int $height):float{
	// This is what the miner and masternode get in the starting epoch.
	$full_block_reward=2.05304206;  // Masternodes get 75% of this.
	$FIRST_REWARD_BLOCK=1892161;
	$HALVING_INTERVAL = 210240;
	$HALVING_REDUCTION_AMOUNT = 1/14;
	$blocks_since_halving = $height - $FIRST_REWARD_BLOCK;
	$halving_period = intval($blocks_since_halving/$HALVING_INTERVAL);
	$reward = $full_block_reward * 0.75 * pow((1 - $HALVING_REDUCTION_AMOUNT),$halving_period);
	return $reward; // In Dash for this height.
}

// Returns array tuple.
// Does not take into account the new reward post halving so dramatically overestimates APY just prior to halving event.
function getMnApy(int $enabled_4k_nodes, int $enabled_1k_nodes, float $standard_mn_reward):array{
	$blocks_per_year=200288;
	// Factor 0.625 accounts for amount of Dash paid to all MNs, the balance 37.5% is held in asset lock/pool.
	// Factor of 10 gets the number down to a %.
	$reg_mn_apy = $blocks_per_year / ($enabled_4k_nodes + $enabled_1k_nodes) * 0.625 * $standard_mn_reward / 10;
	// Factor of 0.375 is the amount of Dash held in the asset pool for the eMNs.
	// Factor of 10 scales up the reward from a % to a the number of Dash.
	// Factor of 40, reduces the number to a percentage.
	$evo_mn_apy = ((0.375 * $standard_mn_reward) / ($enabled_4k_nodes / $blocks_per_year) + $reg_mn_apy*10)/40;
	return array('MN'=>round($reg_mn_apy,2), 'Evo'=>round($evo_mn_apy,2));
}

// $row = array("enabled_4k_masternodes" => 328, "enabled_1k_masternodes" => 2186, "height" => 2371444);
// $apy_arr = getMnApy($row['enabled_4k_masternodes'], $row['enabled_1k_masternodes'], getMnReward($row['height']));




// CALCULATE INTEREST AND APY =================================

// calculates simple interest + real annualized APY for a list of daily rates
function calculateInterestAndAPY(array $data, int $period_start, int $period_end, string $type, int $amount):array {
	
	if ($period_end < $period_start)
		return ["error" => "The end date is earlier than the start date."];
	usort($data, function($a, $b){
		return $a[0] <=> $b[0];
	});
	// Construct the validity intervals
	$intervals = [];
	$casetype = array("MN" => 1, "Evo" => 2);
	for ($i = 0; $i < count($data); $i++) {
		$ts_start = $data[$i][0];
		$rate = floatval($data[$i][$casetype[$type]]);

		if (!isset($data[$i+1]))
			$ts_end = $period_end;
		else
			$ts_end = $data[$i+1][0] - 1;
		$intervals[] = [
			"start" => $ts_start,
			"end"   => $ts_end,
			"rate"  => $rate
		];
	}
	// Interest calculation
	$total_interest = 0;
	foreach ($intervals as $iv) {
		$effective_start = max($iv["start"], $period_start);
		$effective_end   = min($iv["end"],   $period_end);
		if ($effective_end < $effective_start) {
			continue;
		}
		$days = ($effective_end - $effective_start + 1) / 86400;
		$total_interest += $amount * ($iv["rate"] / 100) * ($days / 365);
	}
	// Total duration of the period
	$total_days = ($period_end - $period_start + 1) / 86400;
	// APY annualized proportional
	$apy = ($total_interest / $amount) * (365 / $total_days) * 100;
	return [
		"interest" => $total_interest,
		"apy"      => $apy,
		"days"     => $total_days
	];
}

?>
