<?php
$path = 'D:/OSPanel/home/fw2.loc/public/system/Security/Tripwire.php';
$c = file_get_contents($path);

// Remove the dead code block between return $result; and the closing } of checkStrayPhpFiles
// Pattern: return $result;\n/* ... dead code ... */\n    }
$old = "return \$result;\n/*\n            \$base = rtrim(ROOTPATH, '/\\\\\\\\') . \n                new \\\\RecursiveDirectoryIterator(\$base, \\\\FilesystemIterator::SKIP_DOTS)\n            );\n            foreach (\$iterator as \$file) {\n                if (!\$file instanceof \\\\SplFileInfo || !\$file->isFile()) {\n                    continue;\n                }\n('\\\\\\\\', '/', substr((string)\$file->getPathname())); // stub\n*/\n    }";
$new = "return \$result;\n    }";
if (strpos($c, $old) !== false) {
    $c = str_replace($old, $new, $c);
    echo "Dead code removed\n";
} else {
    echo "Dead code pattern NOT found\n";
    // Debug: find return $result; near the end of checkStrayPhpFiles
    $pos = strpos($c, "return \$result;");
    if ($pos !== false) {
        echo "Found return \$result; at $pos\n";
        echo substr($c, $pos, 200) . "\n";
    }
}

file_put_contents($path, $c);
echo "Done\n";
