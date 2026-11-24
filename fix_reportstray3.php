<?php
$path = 'D:/OSPanel/home/fw2.loc/public/system/Security/Tripwire.php';
$c = file_get_contents($path);

// Find the position after the send() call in reportStray()
$search = "send(\$to, 'RAMEVA: КРИТИЧНО — посторонний PHP-файл', \$body, true)";
$pos = strpos($c, $search);
if ($pos === false) {
    echo "Search string NOT found\n";
    // Try with just part of it
    $pos2 = strpos($c, "КРИТИЧНО — посторонний PHP-файл");
    echo "Partial found at: " . ($pos2 !== false ? $pos2 : 'false') . "\n";
    exit;
}
echo "Found at pos: $pos\n";

// Show what follows
$after = substr($c, $pos + strlen($search), 30);
echo "After: " . json_encode($after) . "\n";

// Insert } after the send() call
$c = substr($c, 0, $pos + strlen($search)) . "\r\n    }" . substr($c, $pos + strlen($search));

file_put_contents($path, $c);
echo "Done\n";
