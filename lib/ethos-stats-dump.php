<?php

require_once('/opt/ethos/lib/functions.php');
$stats = @get_stats();
$space_string = "                                          ";

foreach($stats as $key => $value){
	$chars = -strlen($key.":");
	$spaces = substr($space_string,0,$chars);
	$value = str_replace("\n","\n$space_string",$value);
	echo $key.":".$spaces."".$value."\n";
}

echo "\n";
?>
