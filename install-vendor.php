k<?php
    /**
     * Install Composer Dependencies via PHP (no shell required)
     * Downloads vendor packages from GitHub and sets up autoload
     * SECURITY: token required
     */

    $validToken = 'adf-deploy-2025-secure';
    if (($_GET['token'] ?? '') !== $validToken) {
        http_response_code(403);
        die('Forbidden');
    }

    header('Content-Type: text/plain');
    echo "=== Composer Install via PHP ===\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

    $baseDir = dirname(__FILE__);
    $vendorDir = $baseDir . '/vendor';

    // Check if composer.lock exists
    if (!file_exists($baseDir . '/composer.lock')) {
        die("ERROR: composer.lock not found\n");
    }

    // Parse composer.lock
    $lock = json_decode(file_get_contents($baseDir . '/composer.lock'), true);
    if (!$lock || empty($lock['packages'])) {
        die("ERROR: Invalid composer.lock\n");
    }

    // Create vendor directory
    if (!is_dir($vendorDir)) {
        mkdir($vendorDir, 0755, true);
    }

    $installed = [];
    $failed = [];

    foreach ($lock['packages'] as $pkg) {
        $name = $pkg['name'];
        $version = $pkg['version'];
        $source = $pkg['source'] ?? null;
        $dist = $pkg['dist'] ?? null;

        $pkgDir = $vendorDir . '/' . $name;

        // Skip if already installed
        if (is_dir($pkgDir) && file_exists($pkgDir . '/composer.json')) {
            echo "[SKIP] {$name} ({$version}) - already installed\n";
            $installed[] = $name;
            continue;
        }

        echo "[INSTALL] {$name} ({$version})... ";

        // Try dist URL (zip) first
        $zipUrl = $dist['url'] ?? null;
        if (!$zipUrl && $source && $source['type'] === 'git') {
            // Build GitHub zip URL from source
            $ref = $source['reference'] ?? $version;
            $ghRepo = str_replace(['https://github.com/', '.git'], '', $source['url']);
            $zipUrl = "https://api.github.com/repos/{$ghRepo}/zipball/{$ref}";
        }

        if (!$zipUrl) {
            echo "FAIL (no download URL)\n";
            $failed[] = $name;
            continue;
        }

        // Download zip
        $tmpZip = tempnam(sys_get_temp_dir(), 'composer_');
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 60,
                'follow_location' => true,
                'user_agent' => 'ADF-Composer/1.0',
                'header' => "Accept: application/vnd.github.v3+json\r\n",
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $zipContent = @file_get_contents($zipUrl, false, $ctx);
        if ($zipContent === false) {
            // Try alternate URL format
            if ($dist && !empty($dist['url'])) {
                $zipContent = @file_get_contents($dist['url'], false, $ctx);
            }
        }

        if ($zipContent === false) {
            echo "FAIL (download)\n";
            $failed[] = $name;
            @unlink($tmpZip);
            continue;
        }

        file_put_contents($tmpZip, $zipContent);

        // Extract zip
        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            echo "FAIL (invalid zip)\n";
            $failed[] = $name;
            @unlink($tmpZip);
            continue;
        }

        // Find the root directory in the zip (GitHub zips have a prefix dir)
        $rootDir = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (substr_count($entry, '/') === 1 && substr($entry, -1) === '/') {
                $rootDir = $entry;
                break;
            }
        }

        // Extract to temp, then move
        $tmpExtract = $tmpZip . '_extract';
        @mkdir($tmpExtract, 0755, true);
        $zip->extractTo($tmpExtract);
        $zip->close();
        @unlink($tmpZip);

        // Create package directory
        $parentDir = dirname($pkgDir);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }

        // Move extracted directory
        $sourceDir = $tmpExtract . '/' . rtrim($rootDir, '/');
        if (is_dir($sourceDir)) {
            rename($sourceDir, $pkgDir);
            echo "OK\n";
            $installed[] = $name;
        } else {
            // Sometimes zip has no subdirectory
            rename($tmpExtract, $pkgDir);
            echo "OK (flat)\n";
            $installed[] = $name;
        }

        // Clean up temp
        @rmdir($tmpExtract);
    }

    // Generate autoload files
    echo "\n--- Generating autoload ---\n";

    $autoloadPsr4 = [];
    $autoloadClassmap = [];
    $autoloadFiles = [];

    foreach ($lock['packages'] as $pkg) {
        $name = $pkg['name'];
        $pkgDir = $vendorDir . '/' . $name;
        $pkgComposer = $pkgDir . '/composer.json';

        if (!file_exists($pkgComposer)) continue;

        $pkgConfig = json_decode(file_get_contents($pkgComposer), true);
        $autoload = $pkgConfig['autoload'] ?? [];

        if (!empty($autoload['psr-4'])) {
            foreach ($autoload['psr-4'] as $ns => $paths) {
                $paths = is_array($paths) ? $paths : [$paths];
                foreach ($paths as $path) {
                    $autoloadPsr4[$ns][] = $vendorDir . '/' . $name . '/' . $path;
                }
            }
        }

        if (!empty($autoload['files'])) {
            foreach ($autoload['files'] as $file) {
                $autoloadFiles[] = $vendorDir . '/' . $name . '/' . $file;
            }
        }
    }

    // Write autoload.php
    $autoloadContent = "<?php\n// Auto-generated by install-vendor.php\n";
    $autoloadContent .= "// Time: " . date('Y-m-d H:i:s') . "\n\n";

    // Include files autoload
    foreach ($autoloadFiles as $file) {
        $relPath = str_replace($baseDir . '/', '', $file);
        $autoloadContent .= "require_once __DIR__ . '/../{$relPath}';\n";
    }

    // PSR-4 autoloader
    $autoloadContent .= "\nspl_autoload_register(function (\$class) {\n";
    $autoloadContent .= "    \$psr4 = " . var_export($autoloadPsr4, true) . ";\n";
    $autoloadContent .= "    foreach (\$psr4 as \$prefix => \$dirs) {\n";
    $autoloadContent .= "        \$len = strlen(\$prefix);\n";
    $autoloadContent .= "        if (strncmp(\$prefix, \$class, \$len) !== 0) continue;\n";
    $autoloadContent .= "        \$relative = str_replace('\\\\', '/', substr(\$class, \$len));\n";
    $autoloadContent .= "        foreach (\$dirs as \$dir) {\n";
    $autoloadContent .= "            \$file = \$dir . \$relative . '.php';\n";
    $autoloadContent .= "            if (file_exists(\$file)) { require_once \$file; return; }\n";
    $autoloadContent .= "        }\n";
    $autoloadContent .= "    }\n";
    $autoloadContent .= "});\n";

    file_put_contents($vendorDir . '/autoload.php', $autoloadContent);
    echo "autoload.php generated\n";

    echo "\n=== Result: " . count($installed) . " installed, " . count($failed) . " failed ===\n";
    if (!empty($failed)) {
        echo "Failed packages:\n";
        foreach ($failed as $f) echo "  - {$f}\n";
    }
