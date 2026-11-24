<?php
$path = 'D:/OSPanel/home/fw2.loc/public/system/Security/Tripwire.php';
$lines = file($path);

$start = null;
$end = null;
$foundReturn = false;

foreach ($lines as $i => $line) {
    if (!$foundReturn && trim($line) === 'return $result;') {
        $foundReturn = true;
        continue;
    }
    if ($foundReturn && $start === null && trim($line) === '/*') {
        $start = $i;
        continue;
    }
    if ($start !== null && trim($line) === '*/') {
        $end = $i;
        break;
    }
}

echo "start: " . ($start !== null ? $start : 'null') . "\n";
echo "end: " . ($end !== null ? $end : 'null') . "\n";

if ($start !== null && $end !== null) {
    array_splice($lines, $start, $end - $start + 1);
    $c = implode('', $lines);
    file_put_contents($path, $c);
    echo "Dead code removed\n";
} else {
    echo "Could not find dead code block\n";
}
