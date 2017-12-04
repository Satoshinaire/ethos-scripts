<?php
$miner = `/opt/ethos/sbin/ethos-readconf miner`;
if ($miner == "sgminer-gm"){
  `/opt/ethos/bin/show miner`;
} else {
  echo("This command only works if the miner is \"sgminer-gm\"");
}
?>
