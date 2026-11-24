<?php
$path = 'D:/OSPanel/home/fw2.loc/public/system/Security/Tripwire.php';
$c = file_get_contents($path);

// Fix 1: Add missing closing brace for check() method
// Pattern: "return $result;\n    }\n    /**\n     * Хэширует все файлы"
$old1 = "return \$result;\n    }\n    /**\n     * Хэширует все файлы каталога system/.";
$new1 = "return \$result;\n        }\n    }\n    /**\n     * Хэширует все файлы каталога system/.";
if (strpos($c, $old1) !== false) {
    $c = str_replace($old1, $new1, $c);
    echo "Fix 1 applied: check() closing brace added\n";
} else {
    echo "Fix 1 pattern NOT found\n";
    // Debug: show what's around return $result;
    $pos = strpos($c, 'return $result;');
    if ($pos !== false) {
        echo "Found return $result; at pos $pos\n";
        echo substr($c, $pos, 80) . "\n";
    }
}

// Fix 2: Add missing closing brace for reportStray() method
// Pattern: "$body, true);\n\n    /**\n     * Фиксирует находку чувствительных файлов"
$old2 = "$body, true);\n\n    /**\n     * Фиксирует находку чувствительных файлов (бэкапы/конфиги): журнал и письмо без «КРИТИЧНО».\n     *\n     * @param array<int, string> \$found\n     */\n     private static function reportStraySensitive";
$new2 = "$body, true);\n    }\n\n    /**\n     * Фиксирует находку чувствительных файлов (бэкапы/конфиги): журнал и письмо без «КРИТИЧНО».\n     *\n     * @param array<int, string> \$found\n     */\n     private static function reportStraySensitive";
if (strpos($c, $old2) !== false) {
    $c = str_replace($old2, $new2, $c);
    echo "Fix 2 applied: reportStray() closing brace added\n";
} else {
    echo "Fix 2 pattern NOT found\n";
}

// Fix 3: Add newline between */ and private for writeStrayState
$old3 = "*/private static function writeStrayState";
$new3 = "*/\n    private static function writeStrayState";
if (strpos($c, $old3) !== false) {
    $c = str_replace($old3, $new3, $c);
    echo "Fix 3 applied: newline added before writeStrayState\n";
} else {
    echo "Fix 3 pattern NOT found\n";
}

file_put_contents($path, $c);
echo "Done\n";
