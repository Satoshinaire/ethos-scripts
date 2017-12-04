#!/bin/bash


for i in `ls -t1 /home/ethos/.ethash`; do echo $i; echo -n "" > /home/ethos/.ethash/$i; sleep 2; rm -f /home/ethos/.ethash/$i; done

tail -n 200 /var/run/miner.output > /var/run/output.temp
cat /var/run/output.temp > /var/run/miner.output

tail -n 200 /var/run/ethos-log.file > /var/run/log.temp
cat /var/run/log.temp > /var/run/ethos-log.file

tail -n 200 /var/run/proxy.output > /var/run/proxy.temp  
cat /var/run/proxy.temp > /var/run/proxy.output
exec /opt/ethos/sbin/ethos-getcputemp
UPTIME=`cut -d " " -f1 /proc/uptime | cut -d "." -f 1`

if [ "$UPTIME" -gt 3600 ]; then
HANGHAPPENED=`dmesg | grep -c "ASIC hang happened"` 
 if [ "$HANGHAPPENED" -ge "1" ]; then
  sleep 300
  echo "o" > /proc/sysrq-trigger
 fi
fi
