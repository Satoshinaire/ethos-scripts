<?php

$version = trim(`cat /opt/ethos/etc/version`);
$motherboard = trim(`cat /var/run/ethos/motherboard.file | cut -d":" -f2`);
$hostname = trim(`cat /etc/hostname`);
$uptime = trim(`/opt/ethos/bin/human_uptime`);
$ip = trim(`/sbin/ifconfig | grep 'inet addr'| awk -F":" '{print $2}' | grep -v 127.0.0.1 | cut -d" " -f1 | head -1`);
$panel = trim(`cat /var/run/ethos/url.file`);
$temps = explode(" ",trim(`/opt/ethos/sbin/ethos-readconf temps`));
$hashes = explode(" ",file_get_contents("/var/run/ethos/miner_hashes.file"));
$fans = explode(" ",trim(`/opt/ethos/sbin/ethos-readconf fanrpm | xargs`));
$status = trim(`cat /var/run/ethos/status.file`);
$gpus = explode("\n",trim(file_get_contents("/var/run/ethos/gpulistconky.file")));
$message = trim(`cat /opt/ethos/etc/message`);

$gpu_count = count($gpus);

$render  = "ethOS $version on $motherboard\n";
$render .= "rig $hostname up for $uptime\n\n";
$render .= "admin: http://$ip/\n";
$render .= "panel: $panel\n\n";

for($i = 0; $i < $gpu_count; $i++){
	
	if(!trim($hashes[$i])){ $hashes[$i] = "00.00"; }
	if(!trim($temps[$i])){ $temps[$i] = "00.00"; }
	$fans[$i] = sprintf('%.1f', $fans[$i]/1000);
	
	$render .= round($temps[$i])."°C  Fan: ".$fans[$i]."  Hash: ".$hashes[$i]." | ".$gpus[$i]."\n";
}

$render .= "\n$status\n\n";

$render .= "$message\n\n";

$render .= "run 'helpme' to get started, root/ethos password is 'live'\ntoggle fullscreen terminal with ctrl+alt+left/right arrow\n";

echo $render;

?>


