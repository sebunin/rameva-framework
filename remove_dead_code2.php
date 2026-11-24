<?php
$path = 'D:/OSPanel/home/fw2.loc/public/system/Security/Tripwire.php';
$lines = file($path);

// Find the dead code block: starts with /* after return $result;, ends with */
$start = null;
$end = null;
foreach ($lines as $i => $line) {
    if (trim($line) === 'return $result;' && $start === null) {
        // Look for /* on the next non-empty line
        for ($j = $i + 1; $j < count($lines); $j++) {
            if (trim($lines[$j]) === '/*') {
                $start = $j;
                break;
            }
        }
    }
    if ($start !== null && trim($line) === '*/') {
        $end = $j ?? $i;
        // Actually need to track j properly
        break;
    }
}

echo "start: " . ($start !== null ? $start : 'null') . "\n";
echo "end: " . ($end !== null ? $end : 'null') . "\n";

if ($start !== null && $end !== null) {
    // Remove lines from $start to $end (inclusive)
    array_splice($lines, $start, $end - $start + 1);
    $c = implode('', $lines);
    file_put_contents($path, $c);
    echo "Dead code removed\n";
} else {
    echo "Could not find dead code block\n";
}
