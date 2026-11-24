<?php
$path = 'D:/OSPanel/home/fw2.loc/public/system/Security/Tripwire.php';
$c = file_get_contents($path);

// Add missing closing brace for reportStray()
// File uses CRLF line endings
$old = "send(\$to, 'RAMEVA: КРИТИЧНО — посторонний PHP-файл', \$body, true);\r\n\r\n    /**\r\n     * Фиксирует находку чувствительных файлов";
$new = "send(\$to, 'RAMEVA: КРИТИЧНО — посторонний PHP-файл', \$body, true);\r\n    }\r\n\r\n    /**\r\n     * Фиксирует находку чувствительных файлов";
if (strpos($c, $old) !== false) {
    $c = str_replace($old, $new, $c);
    echo "reportStray() closing brace added\n";
} else {
    echo "Pattern NOT found\n";
}

file_put_contents($path, $c);
echo "Done\n";
