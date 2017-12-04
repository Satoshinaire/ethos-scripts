<?php

function delete_old_api_port(){
        $oldapiport = trim(`/usr/bin/sudo /sbin/iptables -t nat -L | grep DNAT | grep LOCAL |  grep -m 1 -Poi "(?<=to:127.0.0.1:)(.*)"`);
	if ($oldapiport) {
		`/usr/bin/sudo /sbin/iptables -t nat -D POSTROUTING -m addrtype --src-type LOCAL --dst-type UNICAST -j MASQUERADE`;
		`/usr/bin/sudo /sbin/iptables -t nat -D PREROUTING -p tcp -m tcp --dport 42000 -j DNAT --to-destination 127.0.0.1:$oldapiport`;
		`/usr/bin/sudo /sbin/iptables -t nat -D OUTPUT -p tcp -m addrtype --src-type LOCAL --dst-type LOCAL -m tcp --dport 42000 -j DNAT --to-destination 127.0.0.1:$oldapiport`;
	}
}

function select_api_port(){
        $apiport = rand(42001, 42200);
        if (!$socket = @fsockopen("127.0.0.1", $apiport, $errno, $errstr, 3)) {
                echo "Not in use, creating iptables rule..";
		`/usr/bin/sudo /sbin/sysctl -w net.ipv4.conf.all.route_localnet=1`;
		`/usr/bin/sudo /sbin/iptables -t nat -A POSTROUTING -m addrtype --src-type LOCAL --dst-type UNICAST -j MASQUERADE`;
		`/usr/bin/sudo iptables -t nat -A OUTPUT -m addrtype --src-type LOCAL --dst-type LOCAL -p tcp --dport 42000 -j DNAT --to-destination 127.0.0.1:$apiport`;
		$externalapi = trim(`/opt/ethos/sbin/ethos-readconf externalapi`);
		if ($externalapi == "enabled" ){
			`/usr/bin/sudo sysctl -w net.ipv4.conf.eth0.route_localnet=1`;
			`/usr/bin/sudo iptables -t nat -A PREROUTING -p tcp --dport 42000 -j DNAT --to-destination 127.0.0.1:$apiport`;
		}

        } else {
                echo "In use, finding new port.";
                fclose($socket);
                select_api_port();
        }
        return $apiport;
}

function select_gpus(){

	$selectedgpus = trim(`/opt/ethos/sbin/ethos-readconf selectedgpus`);

	if($selectedgpus){
		$devices = explode(" ",$selectedgpus);
	}

	if($selectedgpus == "0"){
		$devices = array($selectedgpus);
	}

	if(!$devices){

		//mining functionality is dependent on gpucount.file always being available
		
			$gpus = trim(file_get_contents("/var/run/ethos/gpucount.file"));

			for($i = 0; $i < $gpus; $i++){
					$devices[] = $i;
			}
	}

	return $devices;
}

// fglrx / amdgpu check igp function
function check_igp()
{
	$checkigp = trim(`/opt/miners/ethminer/ethminer -G --list-devices`);
	preg_match('#\b(Kaveri|Beavercreek|Sumo|Wrestler|Kabini|Mullins|Temash|Trinity|Richland|Carrizo)\b#', $checkigp, $baddevices);

	if ($baddevices) {
		echo "non-mining device found, excluding from mining gpus.\n";
		$validdevices = `grep ']' /var/run/ethos/checkigp.file | grep -v FORMAT | grep -v OPENCL | egrep -iv 'Beavercreek|Sumo|Wrestler|Kabini|Mullins|Temash|Trinity|Richland|Carrizo' | sed 's/\[//g' | sed 's/\]//g' | awk '{print \$1}' | xargs`;
		$extraflags = trim("--opencl-devices $validdevices");
		return $extraflags;
	}
}

function check_status()
{
	$miner = trim(`/opt/ethos/sbin/ethos-readconf miner`);
	$max_boots = trim(`/opt/ethos/sbin/ethos-readconf autoreboot`);
	
	$uptime = trim(`cut -d " " -f1 /proc/uptime | cut -d "." -f 1`);
	$hostname = trim(file_get_contents("/etc/hostname"));
	
	//boot value assignment

	$status['updating']['value'] = intval(trim(file_get_contents("/var/run/ethos/updating.file")));
	$status['adl_error']['value'] = intval(trim(file_get_contents("/var/run/ethos/adl_error.file")));
	$status['nomine']['value'] = intval(trim(file_get_contents("/var/run/ethos/nomine.file")));
	$status['nowatchdog']['value'] = intval(trim(file_get_contents("/var/run/ethos/nowatchdog.file")));

	if(preg_match("/sgminer/",$miner)){
		$status['sgminerconfigerror']['value']  = intval(trim(@shell_exec("/opt/ethos/bin/lintsgconf status")));
	}

	$status['allow']['value'] = intval(trim(file_get_contents("/opt/ethos/etc/allow.file")));
	$status['off']['value'] = intval(trim(`/opt/ethos/sbin/ethos-readconf off`));
	$status['autorebooted']['value'] = intval(trim(file_get_contents("/opt/ethos/etc/autorebooted.file")));
	$status['defunct']['value'] = intval(trim(`ps uax | grep $miner | grep defunct | grep -v grep | wc -l`));
	$status['overheat']['value'] = intval(trim(file_get_contents("/var/run/ethos/overheat.file")));
	$status['starting']['value'] = intval(trim(`ps uax | grep $miner | grep -v defunct | grep -v grep | wc -l`));
	$status['hash']['value'] = trim(`tail -10 /var/run/ethos/miner_hashes.file | sort -V | tail -1 | tr ' ' '\n' | awk '{sum +=$1} END {print sum}'`);

	//boot message assignment

	$status['booting']['message'] = "starting ethos: finishing boot process";
	$status['updating']['updating'] = "do not reboot: system upgrade in progress";
	$status['updating']['updated'] = "reboot required: update complete, reboot system";
	$status['adl_error']['message'] = "hardware error: possible gpu/riser/power failure";
	$status['nomine']['message'] = "hardware error: graphics driver did not load";
	$status['nowatchdog']['message'] = "no overheat protection: overheat protection not running";
	$status['sgminerconfigerror']['message'] = "config error: sgminer configuration is not valid";
	$status['allow']['message'] = "miner disallowed: use 'allow' command";
	$status['off']['message'] = "miner off:  miner set to off in config";
	$status['autorebooted']['message'] = "too many autoreboots: autorebooted ".$status['autorebooted']['value']." times";
	$status['defunct']['message'] = "gpu crashed: reboot required";
	$status['overheat']['message'] = "overheat: one or more gpus overheated";
	$status['starting']['message'] = "miner started: miner commanded to start";
	$status['hash']['message'] = sprintf("%.1f", $status['hash']['value']) . " hash: miner active";

	//boot value/message checks

	if ($status['booting']['value'] > 0) {
		file_put_contents("/var/run/ethos/status.file", $status['booting']['message'] . "\n");
		return false;
	}
	
	if ($status['updating']['value'] == 1) {
		file_put_contents("/var/run/ethos/status.file", $status['updating']['updating'] . "\n");
		return false;
	}
	
	if ($status['updating']['value'] == 2) {
		file_put_contents("/var/run/ethos/status.file", $status['updating']['updated'] . "\n");
		return false;
	}
	
	if ($status['adl_error']['value'] > 0) {
		file_put_contents("/var/run/ethos/status.file", $status['adl_error']['message'] . "\n");
		return false;
	}

	if ($status['nomine']['value'] > 0) {
		file_put_contents("/var/run/ethos/status.file", $status['nomine']['message'] . "\n");
		return false;
	}

	if ($status['nowatchdog']['value'] > 0) {
		file_put_contents("/var/run/ethos/status.file", $status['nowatchdog']['message'] . "\n");
		return false;
	}
	
	if ($status['sgminerconfigerror']['value'] >= 1 && preg_match("/sgminer/",$miner)) {
		file_put_contents("/var/run/ethos/status.file", $status['sgminerconfigerror']['message'] . "\n");
		return false;
	}
	
	if ($status['allow']['value'] == 0) {
		file_put_contents("/var/run/ethos/status.file", $status['allow']['message'] . "\n");
		return false;
	}

	if ($status['off']['value'] == 1) {
		file_put_contents("/var/run/ethos/status.file", $status['off']['message'] . "\n");
		return false;
	}
	
		if ($status['autorebooted']['value'] > $max_boots) {
				file_put_contents("/var/run/ethos/status.file", $status['autorebooted']['message'] . "\n");
				return false;
		}

	if ($status['defunct']['value'] > 0) {
		file_put_contents("/var/run/ethos/status.file", $status['defunct']['message'] . "\n");
		file_put_contents("/var/run/ethos/defunct.file", $status['defunct']['value']);
		return false;
	}

	if ($status['overheat']['value'] > 0) {
		file_put_contents("/var/run/ethos/status.file", $status['overheat']['message'] . "\n");
		return false;
	}

	if ($status['starting']['value'] == 0) {
		file_put_contents("/var/run/ethos/status.file", $status['starting']['message'] . "\n");
		return true;
	}

	if ($status['hash']['value'] > 0) {
		file_put_contents("/var/run/ethos/status.file", $status['hash']['message'] . "\n");
		return false;
	}
	
}

function start_miner()
{

		$miner = trim(`/opt/ethos/sbin/ethos-readconf miner`);

		$status = check_status();
	
	$current_miner = intval(trim(`cat /tmp/minercmd | grep ethos | grep '$miner ' | wc -l`));
	$populated_miner = intval(trim(`cat /tmp/minercmd | grep ethos | grep -v '$miner ' | wc -l`));
	$minercmd_exists = intval(trim(`cat /tmp/minercmd | grep ethos | wc -l`));
		//$current_miner = intval(trim(`grep ethos /tmp/minercmd | grep -Pc "(\$miner\s)"`));
	//$populated_miner = intval(trim(`grep ethos /tmp/minercmd | grep -Poicv "(\$miner\s)"`));
	//$minercmd_exists = intval(trim(`grep -c ethos /tmp/minercmd`));

		if (!$status) {
				return false;
		}

		if(($current_miner != $populated_miner) && $miner && $minercmd_exists > 0){
		`/opt/ethos/bin/minestop`;
		`echo "" > /tmp/minercmd`;
		return false;
	}
	
	
	//global vars
	
	$driver = trim(`/opt/ethos/sbin/ethos-readconf driver`);
	$flags = trim(`/opt/ethos/sbin/ethos-readconf flags`);
	$extraflags = ""; // no extra flags by default
	$hostname = trim(`cat /etc/hostname`);
	$poolpass1 = trim(shell_exec("/opt/ethos/sbin/ethos-readconf poolpass1"));
	$poolpass2 = trim(shell_exec("/opt/ethos/sbin/ethos-readconf poolpass2"));
	$poolpass3 = trim(shell_exec("/opt/ethos/sbin/ethos-readconf poolpass3"));
	$poolpass4 = trim(shell_exec("/opt/ethos/sbin/ethos-readconf poolpass4"));
	$proxywallet = trim(`/opt/ethos/sbin/ethos-readconf proxywallet`);
	$proxypool1 = trim(`/opt/ethos/sbin/ethos-readconf proxypool1`);
	$proxypool2 = trim(`/opt/ethos/sbin/ethos-readconf proxypool2`);
	$proxypool3 = trim(`/opt/ethos/sbin/ethos-readconf proxypool3`);
	$proxypool4 = trim(`/opt/ethos/sbin/ethos-readconf proxypool4`);
	$poolemail = trim(`/opt/ethos/sbin/ethos-readconf poolemail`);
	$gpus = trim(file_get_contents("/var/run/ethos/gpucount.file"));
	$worker = trim(`/opt/ethos/sbin/ethos-readconf worker`);
	$worker = trim(preg_replace("/[^a-zA-Z0-9]+/", "", $worker));
	$stratumtype = trim(`/opt/ethos/sbin/ethos-readconf stratumenabled`);
	
	if (!$poolpass1) {
		$poolpass1 = "x";
	}
	if (!$poolpass2) {
		$poolpass2 = "x";
	}
	
	//manage pool exceptions
	
	if ( preg_match("/dwarfpool.com/",$proxypool1) || preg_match("/dwarfpool.com/",$proxypool2)){
		if($miner == "ccminer" || $miner == "sgminer-gm-xmr" || $miner == "claymore-xmr" ){
			$worker = trim(preg_replace("([a-zA-Z])", "1", $worker));
		}
	}
	
        if ( preg_match("/(pool.ethosdistro.com|nanopool.org)/",$proxypool1) || preg_match("/(pool.ethosdistro.com|nanopool.org)/",$proxypool2)) {
                if ($poolemail){
                $worker .= "/" . $poolemail;
                }
        }
	//begin dstm-zcash configuration generation
	if ($miner == "dstm-zcash") {
		//TODO: need to split proxypool into pool and port for each proxypool$i for this miner.
		$externalapi = trim(`/opt/ethos/sbin/ethos-readconf externalapi`);
		$api = "--telemetry 127.0.0.1:2222";
		if ($externalapi == "enabled" ){
			$api = "--telemetry 0.0.0.0:2222";
		}
		$devices = implode(",",select_gpus());
		if(trim(`/opt/ethos/sbin/ethos-readconf selectedgpus`)){
			$mine_with = "-dev $devices";
		}
		list($dstm_pool1,$dstm_port1) = explode(":", $proxypool1);
		$pools = "--server $dstm_pool1 --port $dstm_port1 --user $proxywallet.$worker --pass $poolpass1 ";
		if($proxypool2){
			list($dstm_pool2,$dstm_port2) = explode(":", $proxypool2);
			$pools .= " --server $dstm_pool2 --port $dstm_port2 --user $proxywallet.$worker --pass $poolpass2 ";
		}
		
	}
	//begin ethminer configuration generation
	
	if ($miner == "ethminer") {

		$gpumode = trim(`/opt/ethos/sbin/ethos-readconf gpumode`);
		$pool = trim(`/opt/ethos/sbin/ethos-readconf fullpool`);
		
		if (!$flags) { $flags = "--farm-recheck 200"; }
		if (!preg_match("/cl-global-work/", $flags) && ($driver == "amdgpu" || $driver == "fglrx" )) {
			$flags .= " --cl-global-work 8192 ";
		}
		
		if (!preg_match("/cuda-parallel-hash/", $flags) && $driver == "nvidia") {
			$flags .= " --cuda-parallel-hash 4 ";
		}
		
		if ($gpumode != "-G" || $gpumode != "-U") {
			if ($driver == "nvidia") {
				$gpumode = "-U";
			}

			if ($driver == "fglrx" || $driver == "amdgpu") {
				$gpumode = "-G";
			}
		}

		if ($driver == "nvidia" && $gpumode == "-U") {
			$selecteddevicetype = "--cuda-devices";
		} else {
			$selecteddevicetype = "--opencl-devices";
			$extraflags = check_igp();
		}

		$minermode = "-F";

		// getwork

		if ($stratumtype != "enabled" && $stratumtype != "miner") {
			$pool = str_replace("WORKER", $worker, $pool);
		}

		// parallel proxy

		if ($stratumtype == "enabled") {
			stratum_phoenix();
			$pool = "http://127.0.0.1:8080/$worker";
		}

		// genoil proxy

		if ($stratumtype == "miner") {
			$minermode = "-S";
				$pool = $proxypool1;
				$extraflags .= " -O $proxywallet.$worker ";
				if ($proxypool2) {
					$extraflags .= " -FS $proxypool2 -FO $proxywallet.$worker ";
				}
		}

		// genoil proxy

		if ($stratumtype == "nicehash") {
			$minermode = "-SP 2 -S";
			$pool = $proxypool1;
			$extraflags .= " -O $proxywallet.$worker ";
			if ($proxypool2) {
				$extraflags .= " -FS $proxypool2 -FO $proxywallet.$worker ";
			}
		}

	}

	
	//begin ccminer config generation
	
	if (preg_match("/ccminer/",$miner)){
		$devices = implode(",",select_gpus());
		if(trim(`/opt/ethos/sbin/ethos-readconf selectedgpus`)){
			$mine_with = "-d $devices";
		}
		if(!preg_match("/-a/",$flags)){
			$flags .= " -a cryptonight ";
		}
		$pools="-o stratum+tcp://$proxypool1 -u $proxywallet.$worker -p $poolpass1 ";
		if($proxypool2){
			$pools .= " -o stratum+tcp://$proxypool2 -u $proxywallet.$worker -p $poolpass2 ";
		}
	}

	
	// begin cgminer-skein/sgminer-gm config generation
	if (preg_match("/(s|c)(gminer)/",$miner)){
		$devices = implode(",",select_gpus());

		if(trim(`/opt/ethos/sbin/ethos-readconf selectedgpus`)){
			$mine_with = "-d $devices";
		}

		$maxtemp = trim(shell_exec("/opt/ethos/sbin/ethos-readconf maxtemp"));
		if (!$maxtemp) {
			$maxtemp = "85";
		}

		if($miner == "sgminer-gm"){
			$config_string = file_get_contents("/home/ethos/sgminer.stub.conf");
		} else {
			$config_string = file_get_contents("/home/ethos/".$miner.".stub.conf");
		}
		if ($driver == "amdgpu") {
			$config_string = preg_replace("/ethash\"/", "ethash-new\"", $config_string);
		}
		$config_string = str_replace("WORKER",$worker,$config_string);
		$config_string = str_replace("POOL1",$proxypool1,$config_string);
		$config_string = str_replace("POOL2",$proxypool2,$config_string);
		$config_string = str_replace("WALLET",$proxywallet,$config_string);
		$config_string = str_replace("PASSWORD1",$poolpass1,$config_string);
		$config_string = str_replace("PASSWORD2",$poolpass2,$config_string);
		$config_string = str_replace("MAXTEMP",$maxtemp,$config_string);
		file_put_contents("/var/run/ethos/sgminer.conf",$config_string);
	}


	//begin claymore / claymore-zcash config generation
	
		if ($miner == "claymore" || $miner == "claymore-zcash") {
		$dualminer_status = (trim(`/opt/ethos/sbin/ethos-readconf dualminer`));
		$devices = implode("",select_gpus());
		if(trim(`/opt/ethos/sbin/ethos-readconf selectedgpus`)){
			$mine_with = "-di $devices";
		}
		$maxtemp = trim(shell_exec("/opt/ethos/sbin/ethos-readconf maxtemp"));
		if (!$maxtemp) {
			$maxtemp = "85";
		}
		if ( $miner == "claymore-zcash"){
			$config_string = file_get_contents("/home/ethos/claymore-zcash.stub.conf");
			$out_file = "/opt/miners/claymore-zcash/config.txt";
		} else {
			$config_string = file_get_contents("/home/ethos/claymore.stub.conf");
			$out_file = "/opt/miners/claymore/config.txt";
		}
		if ($stratumtype == "nicehash") {
			$config_string = str_replace("STRATUMTYPE","3",$config_string);
		} elseif ($stratumtype == "coinotron" ) {
			$config_string = str_replace("STRATUMTYPE","2",$config_string);
		} else {
			$config_string = str_replace("STRATUMTYPE","0",$config_string);
		}
		if ($flags) {
			$flags_list = explode(" ", $flags);
			for ($i = 0; $i < count($flags_list); $i = $i + 2) {
				$next = $i + 1;
				$config_string = "$config_string \n$flags_list[$i] $flags_list[$next]";
			}
		}
		$config_string = str_replace("STRATUMTYPE",$stratumtype,$config_string);
		$config_string = str_replace("WORKER",$worker,$config_string);
		$config_string = str_replace("POOL1",$proxypool1,$config_string);
		$config_string = str_replace("POOL2",$proxypool2,$config_string);
		$config_string = str_replace("WALLET",$proxywallet,$config_string);
		$config_string = str_replace("PASSWORD1",$poolpass1,$config_string);
		$config_string = str_replace("PASSWORD2",$poolpass2,$config_string);
		$config_string = str_replace("MAXTEMP",$maxtemp,$config_string);
		if ($dualminer_status == "enabled" ){
			$dualminerpool = (trim(`/opt/ethos/sbin/ethos-readconf dualminer-pool`));
			$dualminercoin = (trim(`/opt/ethos/sbin/ethos-readconf dualminer-coin`));
			$dualminerwallet =(trim(`/opt/ethos/sbin/ethos-readconf dualminer-wallet`));
			$config_string = "$config_string \n-dcoin $dualminercoin\n-dwal $dualminerwallet.$worker\n-dpool $dualminerpool";
		}
		$config_string = "$config_string \n$mine_with";
		file_put_contents($out_file,$config_string);
	}


	//begin claymore-xmr configuration
	if ($miner == "claymore-xmr"){
		$devices = implode("",select_gpus());
		if(trim(`/opt/ethos/sbin/ethos-readconf selectedgpus`)){
			$mine_with = "-di $devices";
		}
		$flags .= " -dbg -1 -wd 0 ";
		$pools=" -xpool stratum+tcp://$proxypool1 -xwal $proxywallet.$worker -xpsw $poolpass1 ";
		if($proxypool2){
			$pools .= " -xpool stratum+tcp://$proxypool2 -xwal $proxywallet.$worker -xpsw $poolpass2 ";
		}
		if($proxypool3){
			$pools .= " -xpool stratum+tcp://$proxypool3 -xwal $proxywallet.$worker -xpsw $poolpass3 ";
		}
		if($proxypool4){
			$pools .= " -xpool stratum+tcp://$proxypool4 -xwal $proxywallet.$worker -xpsw $poolpass4 ";
		}
	}
	

	//begin ewbf-zcash configuration
	
	if ($miner == "ewbf-zcash") {
		delete_old_api_port();
		$apiport = select_api_port();
		$devices = implode(" ",select_gpus());
		$config_string = file_get_contents("/opt/ethos/etc/ewbf-zcash.conf");
		$config_string = str_replace("DEVICES",$devices,$config_string);
		$config_string= str_replace("APIPORT",$apiport,$config_string);
				$maxtemp = trim(shell_exec("/opt/ethos/sbin/ethos-readconf maxtemp"));
		if (!$maxtemp) {
						$maxtemp = "85";
				}
		for ($i = 1; $i <= 4; $i++){
			if(${'proxypool'.$i}) {
				preg_match("/(.*):(\d+)/", ${'proxypool'.$i}, $pool_split);
				$config_string = $config_string . "\n[server]\nserver " . $pool_split[1] . "\nport " . $pool_split[2] . "\nuser " . $proxywallet . "." . $worker . "\npass " . ${'poolpass'.$i} . "\n";
			}
		}
		$config_string = str_replace("MAXTEMP",$maxtemp,$config_string);
				file_put_contents("/var/run/ethos/ewbf-zcash.conf",$config_string);

	}

			
	//begin optiminer-zcash configuration
	
	if ($miner == "optiminer-zcash") {

				$devices = implode(" -d ",select_gpus());
				$extraflags = trim(`/opt/ethos/sbin/ethos-readconf flags`);
				$mine_with = "-d $devices";
	}


	//begin silentarmy configuration
	
	if ($miner == "silentarmy"){

				$devices = implode(",",select_gpus());
				$mine_with = "--use $devices";
	}
	
			
	//begin wolf-xmr-cpu configuration
	
	if ($miner == "wolf-xmr-cpu"){
		$threads = trim(`/opt/ethos/sbin/ethos-readconf flags`);
		if (!$threads){
			$threads = trim(`nproc`);
		}
	}

			
	//begin miner commandline buildup
	$miner_path['dstm-zcash'] = "/usr/bin/screen -c /opt/ethos/etc/screenrc.dstm-zcash -l -L -dmS dstm-zcash /opt/miners/dstm-zcash/dstm-zcash";
	$miner_path['ccminer'] = "/usr/bin/screen -c /opt/ethos/etc/screenrc.ccminer -l -L -dmS ccminer /opt/miners/ccminer/ccminer";
	$miner_path['cgminer-skein'] = "/usr/bin/screen -c /opt/ethos/etc/screenrc.cgminer-skein -dmS cgminer-skein /opt/miners/cgminer-skein/cgminer-skein";		
	$miner_path['claymore'] = "/usr/bin/screen -c /opt/ethos/etc/screenrc.claymore -l -L -dmS claymore /opt/miners/claymore/claymore";
	$miner_path['claymore-xmr'] = "/usr/bin/screen -c /opt/ethos/etc/screenrc.claymore-xmr -l -L -dmS claymore-xmr /opt/miners/claymore-xmr/claymore-xmr";
	$miner_path['claymore-zcash'] = "/usr/bin/screen -c /opt/ethos/etc/screenrc.claymore-zcash -l -L -dmS claymore-zcash /opt/miners/claymore-zcash/claymore-zcash";
	$miner_path['ethminer'] = "/opt/miners/ethminer/ethminer";
	if ($driver == "fglrx") {
		$miner_path['ethminer'] = "LD_LIBRARY_PATH=/opt/miners/ethminer/lib /opt/miners/ethminer/ethminer-amd";
	
	} elseif ($driver == "amdgpu") {
		$miner_path['ethminer'] = "LD_LIBRARY_PATH=/opt/miners/ethminer/lib:/opt/amdgpu-pro/16.30opencl /opt/miners/ethminer/ethminer-amd";  	
	}
	$miner_path['ewbf-zcash'] = "/usr/bin/screen -c /opt/ethos/etc/screenrc.ewbf-zcash -l -L -dmS ewbf-zcash /opt/miners/ewbf-zcash/ewbf-zcash";
	$miner_path['optiminer-zcash'] = "/bin/bash -c \" cd /opt/miners/optiminer-zcash && /usr/bin/screen -c /opt/ethos/etc/screenrc -dmS optiminer /opt/miners/optiminer-zcash/optiminer-zcash";
	$miner_path['sgminer-gm'] = "/usr/bin/screen -c /opt/ethos/etc/screenrc.sgminer-gm -dmS sgminer /opt/miners/sgminer-gm/sgminer-gm";
	$miner_path['sgminer-gm-xmr'] = "/usr/bin/screen -c /opt/ethos/etc/screenrc.sgminer-gm-xmr -dmS sgminer /opt/miners/sgminer-gm/sgminer-gm-xmr";
	$miner_path['silentarmy'] = "/usr/bin/screen -c /opt/ethos/etc/screenrc.claymore -l -L -dmS silentarmy /opt/miners/silentarmy/silentarmy";
	$miner_path['wolf-xmr-cpu'] = "/opt/miners/wolf-xmr-cpu/wolf-xmr-cpu";

			

			
	$start_miners = select_gpus();

	foreach($start_miners as $start_miner) {
		$miner_params['dstm-zcash'] = $api ." ". $flags ." ". $pools;
		$miner_params['ccminer'] = $flags ." ". $pools;
		$miner_params['cgminer-skein'] = "-c /var/run/ethos/sgminer.conf";
		$miner_params['claymore'] = "";
		$miner_params['claymore-xmr'] = "-allpools 1 " . $flags . " " . $pools;
		$miner_params['claymore-zcash'] = "";
		$miner_params['ethminer'] = $minermode . " " . $pool . " " . $gpumode . " --dag-load-mode sequential " . $flags . " " . $extraflags . " " . $selecteddevicetype . " " . $start_miner;
		$miner_params['ewbf-zcash'] = "--config /var/run/ethos/ewbf-zcash.conf";
		$miner_params['sgminer-gm'] = "-c /var/run/ethos/sgminer.conf";
		$miner_params['sgminer-gm-xmr'] = "-c /var/run/ethos/sgminer.conf";
		$miner_params['silentarmy'] = "--instances=2 " . $extraflags . " -c stratum+tcp://" . $proxypool1. " -u " . $proxywallet . "." . $worker . " -p " . $poolpass1;
		$miner_params['optiminer-zcash'] = "-s $proxypool1 -u $proxywallet.$worker -p $poolpass1 --log-file /var/run/miner.output";
		$miner_params['wolf-xmr-cpu'] = "-o stratum+tcp://$proxypool1 -p $poolpass1 -u $proxywallet.$worker -t $threads";
		
		$miner_suffix['dstm-zcash'] = " " . $mine_with . " " . $extraflags;
		$miner_suffix['ccminer'] = " " . $mine_with . " " . $extraflags;
		$miner_suffix['cgminer-skein'] = " " . $mine_with . " " . $extraflags;
		$miner_suffix['claymore'] = " " . $extraflags;
		$miner_suffix['claymore-xmr'] = " " . $mine_with . " " . $extraflags;
		$miner_suffix['claymore-zcash'] = " " . $extraflags;
		$miner_suffix['ethminer'] = " 2>&1 | /usr/bin/tee -a /var/run/miner.output >> /var/run/miner.$start_miner.output &";
		$miner_suffix['ewbf-zcash'] = "";
		$miner_suffix['sgminer-gm'] = " " . $mine_with . " " . $extraflags;
		$miner_suffix['sgminer-gm-xmr'] = " " . $mine_with ." ". $extraflags;
		$miner_suffix['silentarmy'] = " " . $mine_with . " ";
		$miner_suffix['optiminer-zcash'] = " " . $mine_with ." " . $extraflags ." \\\"";
		$miner_suffix['wolf-xmr-cpu'] = " 2>&1 | /usr/bin/tee -a /var/run/miner.output &";
		
		$command = "su - ethos -c \"" . escapeshellcmd($miner_path[$miner] . " " . $miner_params[$miner]) . " $miner_suffix[$miner]\"";
		$command = str_replace('\#',"#",$command);
		$command = str_replace('\&',"&",$command);
		if ($miner == "optiminer-zcash") {
			file_put_contents("/tmp/minercmd", "#!/bin/bash \n");
			file_put_contents("/tmp/minercmd", $command . "\n", FILE_APPEND);
		} else {
			file_put_contents("/tmp/minercmd", $command . "\n");
		}
		chmod("/tmp/minercmd", 0755);
		`/tmp/minercmd`;

		// if($debug){ file_put_contents("/home/ethos/debug.log",$date $command); 

		if($miner != "ethminer"){
			break;
		}

		sleep(10);
	}

	return true;
}

?>
