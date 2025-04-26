<?php
// CLI check
if (php_sapi_name() !== 'cli') {
    exit("This script must be run from the command line.\n");
}

// Prompt user for input
echo "Enter folder names (comma-separated) to be created inside /api/: ";
$handle = fopen("php://stdin", "r");
$input = fgets($handle);
fclose($handle);

// Prepare and sanitize data
$rawFolders = array_filter(array_map('trim', explode(',', $input)));
$folders = array_filter($rawFolders, function ($folder) {
    return preg_match('/^[a-zA-Z0-9_\-]+$/', $folder); // Allow only safe characters
});

if (empty($folders)) {
    exit("❌ No valid folder names provided. Use only letters, numbers, underscores, and hyphens.\n");
}

$crudOps = ['create', 'read', 'update', 'delete'];
$indexContent = "<?php\nrequire_once '../../core/config.php';\n";
$basePath = __DIR__ . '/api';

// Ensure base /api/ directory exists
if (!is_dir($basePath)) {
    if (!mkdir($basePath, 0755, true)) {
        exit("❌ Failed to create base /api/ directory.\n");
    }
}

$success = [];
$failures = [];

foreach ($folders as $folder) {
    $mainPath = $basePath . '/' . $folder;

    // Create main folder
    if (!is_dir($mainPath) && !mkdir($mainPath, 0755, true)) {
        $failures[] = "❌ Failed to create folder: /api/{$folder}";
        continue;
    }

    foreach ($crudOps as $crud) {
        $crudPath = $mainPath . '/' . $crud;

        // Create CRUD folder
        if (!is_dir($crudPath) && !mkdir($crudPath, 0755, true)) {
            $failures[] = "❌ Failed to create folder: /api/{$folder}/{$crud}";
            continue;
        }

        // Create index.php
        $indexPath = $crudPath . '/index.php';
        if (file_put_contents($indexPath, $indexContent) === false) {
            $failures[] = "❌ Failed to create file: /api/{$folder}/{$crud}/index.php";
        } else {
            $success[] = "✅ Created: /api/{$folder}/{$crud}/index.php";
        }
    }
}

// Output result
echo "\n=== Summary ===\n";
if (!empty($success)) {
    echo "\nSuccessful creations:\n";
    foreach ($success as $msg) {
        echo $msg . "\n";
    }
}
if (!empty($failures)) {
    echo "\nFailed creations:\n";
    foreach ($failures as $msg) {
        echo $msg . "\n";
    }
}

echo "\nDone.\n";
