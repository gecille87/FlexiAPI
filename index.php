<?php

// CLI check
if (php_sapi_name() !== 'cli') {
    exit("This script must be run from the command line.\n");
}


// Deny indirect access
if (basename(__FILE__) != basename($_SERVER["SCRIPT_FILENAME"])) {

    exit("Access denied.");
}



while (true) {
    try {
        mainMenu();
    } catch (Exception $e) {
        echo $e->getMessage() . "\n";
        press();
        clear_screen();
    }
}

function mainMenu()
{
    // Mapping choices to functions
    clear_screen();
    $cases = [
        1 => 'option_1',
        2 => 'option_2',
        0 => 'option_0'
    ];
    begin();
    echo "Type your choice (0-2): ";
    $input = trim(fgets(STDIN));

    if (!is_numeric($input)) {
        throw new Exception("Please enter a valid number.");
    }

    $choice = (int)$input;

    if (array_key_exists($choice, $cases)) {
        call_user_func($cases[$choice]);
    } else {
        invalid_option();
    }

    press();
}

function press()
{
    echo "Press any key and Enter to continue...\n";
    fgets(STDIN);
}

function option_0()
{
    echo "Exiting program...\n";
    exit();
}

// createFolder
function option_1()
{
    clear_screen();
    $cases2 = [
        1 => 'Column',
        2 => 'Row',
        0 => 'option_0'
    ];
    createFolderMenu();
    echo "Type your choice (1 or 2): ";
    $input = trim(fgets(STDIN));

    if (!is_numeric($input)) {
        throw new Exception("Please enter a valid number.");
    }

    $choice = (int)$input;

    if (array_key_exists($choice, $cases2)) {
        if ($choice == 0) {
            mainMenu();
        } else {
            createFolders($cases2[$choice]);
        }
    } else {
        invalid_option();
    }
}

function option_2()
{
    clear_screen();
    configureDatabase();
}

function invalid_option()
{
    echo "Invalid option selected.\n";
}


function clear_screen()
{
    echo "\033[2J\033[H"; // ANSI escape codes for clearing the screen
    flush();
}


function begin()
{
    clear_screen();
    echo "Menu:\n";
    echo "[1] Create Folders\n";
    echo "[2] Configure Database\n";
    echo "[0] Exit\n";
}


function createFolderMenu()
{
    clear_screen();
    echo "Create folder endpoint for :\n";
    echo "[1] Column Manager\n";
    echo "[2] Row Manager\n";
    echo "[0] Main Menu\n";
}


function createFolders($method)
{
    clear_screen();

    // Prompt user for input
    echo "Enter folder names (comma-separated) to be created inside /api/: ";
    $handle = fopen("php://stdin", "r");
    $input = fgets($handle);
    fclose($handle);

    // clear_screen();
    // Prepare and sanitize data
    $rawFolders = array_filter(array_map('trim', explode(',', $input)));
    $folders = array_filter($rawFolders, function ($folder) {
        return preg_match('/^[a-zA-Z0-9_\-]+$/', $folder); // Allow only safe characters
    });

    if (empty($folders)) {
        echo "❌ No valid folder names provided. Use only letters, numbers, underscores, and hyphens.\n";
        return;
    }

    $crudOps = ['create', 'get', 'update', 'delete'];
    $basePath = __DIR__ . '/api';

    // Ensure base /api/ directory exists
    if (!is_dir($basePath)) {
        if (!mkdir($basePath, 0755, true)) {
            echo ("❌ Failed to create base /api/ directory.\n");
        }
    }

    $success = [];
    $failures = [];

    foreach ($folders as $folder) {
        $mainPath = $basePath . '/' . $folder;

        // Check if the main folder already exists
        if (is_dir($mainPath)) {
            $failures[] = "❌ Folder already exists: /api/{$folder}";
            continue;
        }

        // Create main folder
        if (!mkdir($mainPath, 0755, true)) {
            $failures[] = "❌ Failed to create folder: /api/{$folder}";
            continue;
        }

        foreach ($crudOps as $crud) {
            $crudPath = $mainPath . '/' . $crud;

            // Create CRUD subfolder
            if (!mkdir($crudPath, 0755, true)) {
                $failures[] = "❌ Failed to create folder: /api/{$folder}/{$crud}";
                continue;
            }

            $lowermethod = strtolower($method);
            // Prepare index.php content
            $indexContent = <<<PHP
<?php

require_once '../../../core/{$lowermethod}Manager.php';
require_once '../../../core/response.php';

header('Content-Type: application/json');

// Handle JSON POST request
if (\$_SERVER["REQUEST_METHOD"] === "POST") {
    \$input = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonResponse(false, "Invalid JSON input.");
    }

    {$crud}Table{$method}(\$input);

} else {
    jsonResponse(false, "Input required.");
}

PHP;

            // Create index.php file
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
        echo "\nIssues encountered:\n";
        foreach ($failures as $msg) {
            echo $msg . "\n";
        }
    }

    echo "\nDone.\n";
}



// Input validation: Basic checks (add more if needed)
function validateInput($label, $input)
{
    if (strlen($input) > 100) {
        echo "Error: $label is too long (max 100 characters).\n";
        return;
    }
    if (preg_match('/[;<>\'"]/u', $input)) {
        echo "Error: $label contains invalid characters.\n";
        return;
    }
}

function configureDatabase()
{
    clear_screen();

    $configFile = __DIR__ . '/core/db_config.php'; // Adjust path if needed

    if (!file_exists($configFile)) {
        echo "Error: Configuration file not found.\n";
        return;
    }

    // Read the config file
    $configContents = file_get_contents($configFile);

    // Safely extract current values using regex
    preg_match('/private static \$servername = "(.*?)";/', $configContents, $servernameMatch);
    preg_match('/private static \$username = "(.*?)";/', $configContents, $usernameMatch);
    preg_match('/private static \$password = "(.*?)";/', $configContents, $passwordMatch);
    preg_match('/private static \$defaultDB = "(.*?)";/', $configContents, $defaultDBMatch);

    // Helper function to safely ask for new input
    $ask = function ($prompt, $hidden = false) {
        echo "$prompt \nEnter new value (press enter key once done): ";

        if ($hidden) {
            if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
                // Windows system
                echo "(Warning: Input will be visible on Windows)\n";
            } else {
                // Linux/Mac: hide input
                shell_exec('stty -echo');
            }
        }

        $input = trim(fgets(STDIN));

        if ($hidden && strncasecmp(PHP_OS, 'WIN', 3) !== 0) {
            shell_exec('stty echo');
            echo "\n"; // Newline after hidden input
        }

        // return $input === "" ? $currentValue : $input;
        return $input;
    };

    // Fetch current values
    // $currentServername = $servernameMatch[1] ?? '';
    // $currentUsername = $usernameMatch[1] ?? '';
    // $currentPassword = $passwordMatch[1] ?? '';
    // $currentDefaultDB = $defaultDBMatch[1] ?? '';

    // $configPath = __DIR__ . '/core/db_config.php';
    // $configContent = file_get_contents($configPath);

    $newServername = $ask("Database servername",);
    $newUsername   = $ask("Database username",);
    $newPassword   = $ask("Database password", true);
    $newDefaultDB  = $ask("Default database name",);


    validateInput('Servername', $newServername);
    validateInput('Username', $newUsername);
    validateInput('Password', $newPassword);
    validateInput('DefaultDB', $newDefaultDB);

    // Safely replace the contents
    $replacements = [
        '/private static \$servername = ".*?";/' => 'private static $servername = "' . addslashes($newServername) . '";',
        '/private static \$username = ".*?";/'    => 'private static $username = "' . addslashes($newUsername) . '";',
        '/private static \$password = ".*?";/'    => 'private static $password = "' . addslashes($newPassword) . '";',
        '/private static \$defaultDB = ".*?";/'   => 'private static $defaultDB = "' . addslashes($newDefaultDB) . '";',
    ];

    $newConfigContents = $configContents;
    foreach ($replacements as $pattern => $replacement) {
        $newConfigContents = preg_replace($pattern, $replacement, $newConfigContents);
    }

    // Backup old config just in case
    $backupFile = $configFile . '.bak_' . date('Ymd_His');
    if (!copy($configFile, $backupFile)) {
        echo "Warning: Failed to create backup file.\n";
    }

    // Write back to the config
    if (file_put_contents($configFile, $newConfigContents)) {
        echo "\n✅ Configuration updated successfully!\n";
        echo "Backup created: $backupFile\n";
    } else {
        echo "\n❌ Failed to write new configuration.\n";
        return;
    }
}
