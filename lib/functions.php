<?php

require_once ('/opt/ethos/lib/minerprocess.php');

function get_http_response_code($url)
{
	$headers = get_headers($url);
	return substr($headers[0], 9, 3);
}

function sample_kbps(){

	$rx_now = `tail -1 /var/log/rx.log`; 
	$rx_before = `tail -2 /var/log/rx.log | head -1`;

        $tx_now = `tail -1 /var/log/tx.log`; 
        $tx_before = `tail -2 /var/log/tx.log | head -1`;

	$rx_kbps = sprintf("%.2f",($rx_now-$rx_before)/600/1024);
	$tx_kbps = sprintf("%.2f",($tx_now-$tx_before)/600/1024);

	return array($rx_kbps,$tx_kbps);
}

function putconf($interactive = "0")
{
	`sudo /opt/ethos/sbin/ethos-motd-generator`;
	`/usr/bin/dos2unix -q /home/ethos/remote.conf`;


	if ($interactive != "1") {
                `/sbin/ifconfig | grep bytes | sed 's/bytes/*/g' | sed 's/://g' | sed 's/ (/*/g' | cut -d"*" -f2,4 | head -1 | cut -d"*" -f1 >> /var/log/rx.log`;
                `/sbin/ifconfig | grep bytes | sed 's/bytes/*/g' | sed 's/://g' | sed 's/ (/*/g' | cut -d"*" -f2,4 | head -1 | cut -d"*" -f2 >> /var/log/tx.log`;
		sleep(mt_rand(0, 5)); //do not saturate webserver children with requests if run automatically
	}

	$remote = trim(`cat /home/ethos/remote.conf | grep -v '^#' | grep '\.' | head -1`);
	$send_remote = substr($remote, 0, 150);
	file_put_contents("/var/run/ethos/send_remote.file", $send_remote);
	if (strlen($remote) == 0 || !eregi("http://|https://", $remote) || substr($remote, 0, 1) == "#") {
		$message = "REMOTE CONFIG DOES NOT EXIST OR IS FORMATTED INCORRECTLY. USING LOCAL CONFIG.";
		echo $message . "\n";
		if (!$remote) {
			file_put_contents("/var/run/ethos/config_mode.file", "singlerig");
		}
		else {
			file_put_contents("/var/run/ethos/config_mode.file", "badformat");
		}

		return;
	}
	else {
		ini_set('default_socket_timeout', 3);
		$header = get_http_response_code($remote);
		if ($interactive == "1") {
			echo " ...";
		}
	}
        $header_array = array(200, 301, 302);
	if (!in_array($header, $header_array)) {
		$message = "REMOTELY DEFINED CONFIG SERVER IS UNREACHABLE. USING LOCAL CONFIG.";
		echo $message . "\n";
		file_put_contents("/var/run/ethos/config_mode.file", "unreachable");
		return;
	}
	else {
		$remote = trim($remote);
		$remote_contents = trim(file_get_contents($remote, FILE_IGNORE_NEW_LINES));
		if ($interactive == "1") {
			echo "...";
		}
	}

	if (preg_match("/<[^<]+>/", $remote_contents, $m) != 0) {
		$message = "REMOTE CONFIG CONTAINS HTML OR XML TAGS. USING LOCAL CONFIG.";
		echo $message . "\n";
		file_put_contents("/var/run/ethos/config_mode.file", "invalid");
		return;
	}
	else {
		if ($interactive == "1") {
			echo "...";
		}
	}

	if (strlen($remote_contents) < 15) {
		$message = "REMOTE CONFIG APPEARS TO BE TOO SHORT. USING LOCAL CONFIG.";
		echo $message . "\n";
		file_put_contents("/var/run/ethos/config_mode.file", "tooshort");
		return;
	}
	else {
		if ($interactive == "1") {
			echo "...";
		}
	}

	if (md5($remote_contents) != md5(file_get_contents("/home/ethos/local.conf"))) {
		$message = "IMPORTED REMOTE CONFIG INTO LOCAL CONFIG.";
		echo $message . "\n";
		file_put_contents("/home/ethos/local.conf", $remote_contents . "\n");
	}
	else {
		if ($interactive == "1") {
			echo "...";
		}
	}

	`sudo /usr/bin/dos2unix -q /home/ethos/local.conf`;
}

function check_proxy()
{
	$miner = trim(`/opt/ethos/sbin/ethos-readconf miner`);
	$stratumtype = trim(`/opt/ethos/sbin/ethos-readconf stratumenabled`);

		file_put_contents("/var/run/ethos/proxy_error.file","working");
		$proxy_error = 0;

	if ($miner == "ethminer" && $stratumtype == "enabled") {
		$requested_restart = trim(`tail -100 /var/run/ethos/proxy.output | grep 'Please restart proxy' | wc -l`);
		if ($requested_restart > 0) {
			file_put_contents("/var/run/ethos/proxy_error.file","restart");
			$proxy_error = 2;
		}

		$primary_pool_offline = trim(`tail -100 /var/run/ethos/proxy.output | grep 'must be online' | wc -l`);
		if ($primary_pool_offline > 0) {
			file_put_contents("/var/run/ethos/proxy_error.file","primary_down");
			$proxy_error = 3;
		}

		$proxy_getting_job = trim(`tail -5 /var/run/proxy.output | grep 'NEW_JOB MAIN_POOL' | wc -l`);
		$rpc_problems = trim(`tail -240 /var/run/miner.0.output | grep 'JSON-RPC problem' | wc -l`);
		if ($rpc_problems >= 30 && $proxy_getting_job > 2) {
			file_put_contents("/var/run/ethos/proxy_error.file","failure");
			$proxy_error = 4;
		}

		$rejected_shares = trim(`tail -100 /var/run/ethos/proxy.output | grep -c "REJECTED"`);
		if ($rejected_shares > 2) {
			file_put_contents("/var/run/ethos/proxy_error.file","rejected");
			$proxy_error = 5;
		}

		if ($proxy_error > 0) {
			`echo -n "" > /var/run/ethos/proxy.output`;
			`killall -9 python`;
			`su - ethos -c '/opt/eth-proxy/eth-proxy.py >> /var/run/ethos/proxy.output 2>&1 &'`;
		}
	}
}

function stratum_phoenix()
{
	`ps uax| grep "python /opt/eth-proxy/eth-proxy.py" | grep -v grep | awk '{print $2}' | xargs kill -9 2> /dev/null`;
	`su - ethos -c '/opt/eth-proxy/eth-proxy.py >> /var/run/ethos/proxy.output 2>&1 &'`;
}

function get_stats()
{
	$gpus = trim(file_get_contents("/var/run/ethos/gpucount.file"));
	$driver = trim(`/opt/ethos/sbin/ethos-readconf driver`);
	$miner = trim(`/opt/ethos/sbin/ethos-readconf miner`);
	list($rx_kbps,$tx_kbps) = sample_kbps();

	// miner check info
	$send['defunct'] = intval(trim(file_get_contents("/var/run/ethos/defunct.file")));
	$send['off'] = trim(`/opt/ethos/sbin/ethos-readconf off`);
	$send['allowed'] = intval(trim(file_get_contents("/opt/ethos/etc/allow.file")));
	$send['overheat'] = intval(trim(file_get_contents("/var/run/ethos/overheat.file")));
	$send['pool_info'] = trim(`cat /home/ethos/local.conf | grep -v '^#' | egrep -i 'pool|wallet|proxy'`);
	$send['pool'] = trim(`/opt/ethos/sbin/ethos-readconf proxypool1`);
	$send['miner_version'] = trim(`cat /var/run/ethos/miner.versions | grep '$miner ' | cut -d" " -f2 | head -1`);

	// system related info

	$send['rx_kbps'] = $rx_kbps;
	$send['tx_kbps'] = $tx_kbps;
	$send['kernel'] = trim(`/bin/uname -r`);
	$send['boot_mode'] = trim(`/opt/ethos/sbin/ethos-readdata bootmode`);
	$send['uptime'] = trim(`cat /proc/uptime | cut -d"." -f1`);
	$send['mac'] = trim(`/sbin/ifconfig | grep HW | awk '{print \$NF}' | sed 's/://g'`);
	$send['hostname'] = trim(`/sbin/ifconfig | grep -e HW -e eth0 | head -1 | awk '{print \$NF}' | sed 's/://g' | tail -c 7`);
	$send['rack_loc'] = trim(`/opt/ethos/sbin/ethos-readconf loc`);
	$send['ip'] = trim(`/sbin/ifconfig | grep 'Bcast' | head -1 |  cut -d":" -f2 | cut -d" " -f1`);
	$send['manu'] = trim(file_get_contents("/var/run/ethos/manu.file"));
	$send['mobo'] = trim(file_get_contents("/var/run/ethos/motherboard.file"));
	$send['lan_chip'] = trim(`/usr/bin/lspci -v | grep -Poi "(?<=Ethernet\scontroller\:\s)(.*)"`);
	$send['load'] = trim(`cat /proc/loadavg | cut -d" " -f3`);
	$send['ram'] = trim(`/usr/bin/free | head -2 | tail -1 | awk '{print \$2/1024/1024}' OFMT="%3.0f" | awk '{print \$1}'`);
	$send['cpu_temp'] = trim(file_get_contents("/var/run/ethos/cputemp.file"));
	$send['cpu_name'] = trim(`cat /var/run/ethos/cpuinfo.file`);
	$send['rofs'] = time() - trim(file_get_contents("/opt/ethos/etc/check-ro.file"));
	$send['drive_name'] = trim(`/opt/ethos/sbin/ethos-readdata driveinfo`);
	$send['freespace'] = round(trim(`/bin/df | grep '/dev/' | head -1 | awk '{print $4}'`) / 1024 / 1024, 1);
	$send['temp'] = trim(`/opt/ethos/sbin/ethos-readdata temps`);
	$send['version'] = trim(file_get_contents("/opt/ethos/etc/version"));
	$send['miner_secs'] = 0 + trim(`ps -eo pid,etime,command | grep $miner | grep -v grep | head -1 | awk '{print \$2}' |  /opt/ethos/bin/convert_time.awk`);
	$send['adl_error'] = trim(file_get_contents("/var/run/ethos/adl_error.file"));
	$send['proxy_problem'] = trim(file_get_contents("/var/run/ethos/proxy_error.file"));
	$send['updating'] = trim(file_get_contents("/var/run/ethos/updating.file"));
	$send['connected_displays'] = trim(`/opt/ethos/sbin/ethos-readdata connecteddisplays`);
	$send['resolution'] = trim(`/opt/ethos/sbin/ethos-readdata resolution`);
	$send['gethelp'] = trim(`tail -1 /var/log/gethelp.log`);
	$send['config_status'] = trim(`cat /var/run/ethos/config_mode.file`);
	$send['send_remote'] = trim(`cat /var/run/ethos/send_remote.file`);
	$send['autorebooted'] = trim(`cat /opt/ethos/etc/autorebooted.file`);
	$send['status'] = trim(`cat /var/run/ethos/status.file`);

	// gpu related info

	$send['driver'] = trim(`/opt/ethos/sbin/ethos-readconf driver`);
	$send['selected_gpus'] = trim(`/opt/ethos/sbin/ethos-readconf selectedgpus`);
	$send['gpus'] = $gpus;
	$send['fanrpm'] = trim(`/opt/ethos/sbin/ethos-readdata fanrpm | xargs | tr -s ' '`);
	$send['fanpercent'] = trim(`/opt/ethos/sbin/ethos-readdata fan | xargs | tr -s ' '`);
	$send['hash'] = trim(`tail -10 /var/run/ethos/miner_hashes.file | sort -V | tail -1 | tr ' ' '\n' | awk '{sum += \$1} END {print sum}'`);
	$send['miner'] = $miner;
	$send['miner_hashes'] = trim(`tail -10 /var/run/ethos/miner_hashes.file | sort -V | tail -1`);
	if($miner == "claymore"){
		$send['dualminer_status'] = trim (`/opt/ethos/sbin/ethos-readconf dualminer`);
		$send['dualminer_coin'] = trim (`/opt/ethos/sbin/ethos-readconf dualminer-coin`);
		$send['dualminer_hashes'] = trim(`tail -10 /var/run/ethos/dualminer_hashes.file | sort -V | tail -1`);
	}
	if(preg_match("/sgminer/",$miner)){
		$send['hwerrors'] = trim(`cat /var/run/ethos/hwerror.file`);
	}
	$send['models'] = trim(file_get_contents("/var/run/ethos/gpulist.file"));
	$send['bioses'] = trim(trim(`/opt/ethos/sbin/ethos-readdata bios | xargs | tr -s ' '`));
	$send['default_core'] = trim(file_get_contents("/var/run/ethos/defaultcore.file"));
	$send['default_mem'] = trim(file_get_contents("/var/run/ethos/defaultmem.file"));
	$send['vramsize'] = trim(file_get_contents("/var/run/ethos/vrams.file"));
	$send['core'] = trim(`/opt/ethos/sbin/ethos-readdata core | xargs | tr -s ' '`);
	$send['mem'] = trim(`/opt/ethos/sbin/ethos-readdata mem | xargs | tr -s ' '`);
        $send['memstates'] = trim(`/opt/ethos/sbin/ethos-readdata memstate | xargs | tr -s ' '`);
	$send['meminfo'] = trim(file_get_contents("/var/run/ethos/meminfo.file"));
	$send['voltage'] = trim(`/opt/ethos/sbin/ethos-readdata voltage | xargs | tr -s ' '`);
	
	if($driver == "nvidia"){
		$send['watts'] = trim(`/opt/ethos/sbin/ethos-readdata watts | xargs | tr -s ' '`);
		$send['watt_min'] = trim(file_get_contents("/var/run/ethos/watt_min.file"));
		$send['watt_max'] = trim(file_get_contents("/var/run/ethos/watt_max.file"));
	}
	$send['overheatedgpu'] = trim(file_get_contents("/var/run/ethos/overheatedgpu.file"));
	$send['throttled'] = trim(file_get_contents("/var/run/ethos/throttled.file"));
	$send['powertune'] = trim(`/opt/ethos/sbin/ethos-readdata powertune | xargs | tr -s ' '`);

	return $send;
}

function send_data()
{
	$send = get_stats();
	$private_hash = trim(file_get_contents("/var/run/ethos/private_hash.file"));
	$public_hash = trim(file_get_contents("/var/run/ethos/panel.file"));
	$hook = "http://ethosdistro.com/get.php";
	$url = "http://" . $public_hash . ".ethosdistro.com/";
	$json = json_encode($send);
	$log = "";
	foreach($send as $key => $data) {
		$log.= "$key:$data\n";
	}

	$url_style = urlencode($json);
	$hostname = $send['hostname'];
	$message = file_get_contents("$hook?hostname=$hostname&url_style=$url_style&hash=$private_hash");
	file_put_contents("/opt/ethos/etc/message", trim($message));
	return $log;
}

function prevent_overheat()
{
	$max_temp = trim(`/opt/ethos/sbin/ethos-readconf maxtemp`);
	if (!$max_temp) {
		$max_temp = "85";
	}
	$throttle_temp = ($max_temp - 5);
	$miner = trim(`/opt/ethos/sbin/ethos-readconf miner`);
	if(preg_match("/sgminer/",$miner)){
		$max_temp = ($max_temp + 3);	
	}
	$temps = trim(`/opt/ethos/sbin/ethos-readdata temps`);
	$temp_array = explode(" ", $temps);
	$c = 0;
	$bad_values = "108 115 128 135";
	$bad_array = explode(" ", $bad_values);
	foreach($temp_array as $temp) {
		$throttled[$c] = trim(file_get_contents("/var/run/ethos/throttled.gpu" . $c));
		if ($temp > $throttle_temp && $temp < 500 && !in_array($temp, $bad_array) && !$throttled[$c]) {
			`/opt/ethos/sbin/ethos-throttle $c`;
			file_put_contents("/var/run/ethos/throttled.file", "1");
			file_put_contents("/var/run/ethos/throttled.gpu" . $c, "1");
		}

		if ($temp > $max_temp && $temp < 500 && !in_array($temp, $bad_array)) {
			$pid = trim(`/opt/ethos/sbin/ethos-readconf pid $c`);
			`kill -9 $pid 2> /dev/null`;
			file_put_contents("/var/run/ethos/overheat.file", "1");
			file_put_contents("/var/run/ethos/overheatedgpu.file", "$c");
			break;
		}

		$c++;
	}
}

?>

