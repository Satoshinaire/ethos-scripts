<?php
/**
 * High resolution GPU monitor
 *
 * This is useful if/when you are trying to diagnose GPU freezing,
 * to see the status of all GPUs in a more-or-less real-time update.
 *
 * Do not run this on a normally functioning rig, it will just eat
 * a bunch of CPU for no reason.  However if you are trying to find
 * out which GPU keeps freezing up, this can be helpful.
 *
 * Usage: sudo php hires-monitor.php
 *
 * @author xist
 */

require_once __DIR__ . '/../lib/GPUMonitor.php';


function writeAdapterField($monitor, $format, $field, $callback=null)
{
    $adapters = $monitor->getAdapters();
    $numAdapters = count($adapters);

    for ($i=0; $i<$numAdapters; $i++) {
        $value = $adapters[$i][$field];
        if ($callback) {
            call_user_func($callback, $format, $value);
        } else {
            printf($format, $value);
        }
    }
}

function writeFanPercent($format, $value)
{
    if ($value == 100) {
        echo " **";
    } else {
        printf($format, $value);
    }
}

$monitor = new GPUMonitor();

while (true) {

    $result = $monitor->probe();
    $adapters =& $result['adapters'];

    $t = gettimeofday();
    printf("%s.%03d", gmdate('His', $t['sec']), $t['usec']/1000);

    // Display real-time MH/s
    printf(" | %6.2f", $result['MHps']);

    echo " |";
    writeAdapterField($monitor, ' %2d', 'tempC'); // GPU temp (C)

    echo " |";
    writeAdapterField($monitor, ' %2d', 'fanPercent', 'writeFanPercent'); // Fan % speed

    echo " |";
    writeAdapterField($monitor, ' %4d', 'clock'); // GPU clock speed (MHz)

    echo " |";
    writeAdapterField($monitor, ' %4d', 'memClock'); // GPU Memory clock speed (MHz)

    echo "\n";

    usleep(450000);
}
