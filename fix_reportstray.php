<?php
$path = 'D:/OSPanel/home/fw2.loc/public/system/Security/Tripwire.php';
$c = file_get_contents($path);

// Add missing closing brace for reportStray()
// Pattern: send($to, 'RAMEVA: КРИТИЧНО — посторонний PHP-файl', $body, true);\n\n    /**
$old = "send(\$to, 'RAMEVA: КРИТИЧНО — посторонний PHP-файл', \$body, true);\n\n    /**\n     * Фиксирует находку чувствительных файлов";
$new = "send(\$to, 'RAMEVA: КРИТИЧНО — посторонний PHP-файл', \$body, true);\n    }\n\n    /**\n     * Фиксирует находку чувствительных файлов";
if (strpos($c, $old) !== false) {
    $c = str_replace($old, $new, $c);
    echo "reportStray() closing brace added\n";
} else {
    echo "Pattern NOT found\n";
    // Debug
    $pos = strpos($c, "КРИТИЧНО — посторонний PHP-файл");
    if ($pos !== false) {
        echo "Found at pos $pos\n";
        echo substr($c, $pos, 100) . "\n";
    }
}

file_put_contents($path, $c);
echo "Done\n";
