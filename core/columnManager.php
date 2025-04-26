<?php
require_once 'db_config.php';
require_once 'response.php';


// Deny direct access
if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) {
    http_response_code(403);
    exit("Access denied.");
}


function isValidIdentifier($name)
{
    return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) === 1;
}

function isValidColumnType($type)
{
    // Accepts types like VARCHAR(50), INT(11), TEXT, etc.
    return preg_match('/^[a-zA-Z]+(\(\d+\))?$/i', $type);
}


function isColumnExist($conn, $column, $table)
{
    $escapedColumn = $conn->real_escape_string($column);
    $escapedTable = $conn->real_escape_string($table);

    $query = "SHOW COLUMNS FROM `$escapedTable` LIKE '$escapedColumn'";
    $result = $conn->query($query);

    if (!$result) {
        jsonResponse(false, "Column existence check failed.", ['error' => $conn->error]);
    }

    if ($result->num_rows > 0) {
        jsonResponse(false, "Column `$column` already exists in table `$table`.");
    }
}


function isSafeTypeConversion($oldType, $newType)
{
    // Normalize types
    $oldType = strtolower($oldType);
    $newType = strtolower($newType);

    // Basic compatibility map
    $safeConversions = [
        'int' => ['bigint', 'varchar', 'text'],
        'bigint' => ['varchar', 'text'],
        'float' => ['double', 'decimal', 'varchar'],
        'varchar' => ['text'],
        'char' => ['varchar', 'text'],
        'text' => ['longtext'],
        'date' => ['datetime', 'timestamp'],
    ];

    // Extract base types (e.g., varchar(255) => varchar)
    $baseOld = preg_replace('/\(.*/', '', $oldType);
    $baseNew = preg_replace('/\(.*/', '', $newType);

    if ($baseOld === $baseNew) return true;

    if (isset($safeConversions[$baseOld])) {
        return in_array($baseNew, $safeConversions[$baseOld]);
    }

    return false;
}

function createColumnHistoryTable(mysqli $conn)
{
    $conn->query("
    CREATE TABLE IF NOT EXISTS `column_change_history` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        db_name VARCHAR(255),
        table_name VARCHAR(255),
        column_name VARCHAR(255),
        old_type VARCHAR(255),
        new_type VARCHAR(255),
        action ENUM('MODIFY', 'DROP', 'ADD', 'RENAME') ,
        old_nullability VARCHAR(10),
        new_nullability VARCHAR(10),
        old_default TEXT,
        new_default TEXT,
        old_comment TEXT,
        new_comment TEXT,
        renamed_to VARCHAR(255),
        changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");
}

function logColumnChange(mysqli $conn, array $changeDetails)
{
    // Ensure history table exists
    createColumnHistoryTable($conn);

    // Extract and sanitize data
    $table       = $conn->real_escape_string($changeDetails['table'] ?? '');
    $column      = $conn->real_escape_string($changeDetails['column'] ?? '');
    $oldType     = isset($changeDetails['old_type']) ? $conn->real_escape_string($changeDetails['old_type']) : null;
    $newType     = isset($changeDetails['new_type']) ? $conn->real_escape_string($changeDetails['new_type']) : null;
    $action      = strtoupper($changeDetails['action'] ?? 'MODIFY');
    $oldComment  = isset($changeDetails['old_comment']) ? $conn->real_escape_string($changeDetails['old_comment']) : null;
    $newComment  = isset($changeDetails['new_comment']) ? $conn->real_escape_string($changeDetails['new_comment']) : null;
    $nullable    = isset($changeDetails['is_nullable']) ? (bool)$changeDetails['is_nullable'] : true;
    $oldNull     = isset($changeDetails['old_null']) ? (bool)$changeDetails['old_null'] : true;
    $newName     = isset($changeDetails['new_name']) ? $changeDetails['new_name'] : $column;
    $oldDefault  = isset($changeDetails['oldDefault']) ? $changeDetails['oldDefault'] : '';
    $default     = isset($changeDetails['default']) ? $changeDetails['default'] : '';
    $database    = isset($changeDetails['db_name']) ? $changeDetails['db_name'] : null;

    $nullStr     = $nullable ? 'YES' : 'NO';
    $oldNullStr  = $oldNull ? 'YES' : 'NO';
    $finalNewName = ($newName !== $column) ? $newName : null;

    // Prepare insert
    $stmt = $conn->prepare("
        INSERT INTO column_change_history (
            db_name, table_name, column_name, old_type, new_type, action,
            old_nullability, new_nullability, old_default, new_default,
            old_comment, new_comment, renamed_to
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        jsonResponse(false, "Failed to prepare history logging statement.", ['error' => $conn->error]);
    }

    $stmt->bind_param(
        "sssssssssssss",
        $database,
        $table,
        $column,
        $oldType,
        $newType,
        $action,
        $oldNullStr,
        $nullStr,
        $oldDefault,
        $default,
        $oldComment,
        $newComment,
        $finalNewName
    );

    if (!$stmt->execute()) {
        jsonResponse(false, "Failed to log column change.", ['error' => $stmt->error]);
    }

    $stmt->close();
}


function createTableColumn($input)
{
    $requiredFields = ['table', 'column_name', 'column_type'];
    foreach ($requiredFields as $field) {
        if (empty($input[$field])) {
            jsonResponse(false, "Missing required field: $field");
        }
    }

    $database   = isset($input['database']) ? $input['database'] : '';
    $table      = $input['table'];
    $column     = $input['column_name'];
    $type       = strtoupper($input['column_type']);
    $nullable   = isset($input['is_nullable']) ? (bool)$input['is_nullable'] : true;
    $default    = array_key_exists('default', $input) ? $input['default'] : null;
    $autoInc    = isset($input['auto_increment']) ? (bool)$input['auto_increment'] : false;
    $unique     = isset($input['unique']) ? (bool)$input['unique'] : false;
    $index      = isset($input['index']) ? (bool)$input['index'] : false;
    $comment    = isset($input['comment']) ? $input['comment'] : '';

    if (!isValidIdentifier($column)) {
        jsonResponse(false, "Invalid column name.");
    }

    if (!isValidColumnType($type)) {
        jsonResponse(false, "Invalid column type.");
    }

    try {
        if ($database) {
            DBConnection::setDatabase($database); // overrides default
        }
        $conn = DBConnection::getConnection();
    } catch (Exception $e) {
        jsonResponse(false, "Database connection failed.", ['error' => $e->getMessage()]);
    }

    // === Table existence check ===
    isTableExist($conn, $table, $database);

    // === Column existence check ===

    isColumnExist($conn, $column, $table);

    // Build SQL
    $parts = [];
    $parts[] = "`$column` $type";
    $parts[] = $nullable ? "NULL" : "NOT NULL";
    if ($default !== null) {
        $escapedDefault = $conn->real_escape_string($default);
        $parts[] = "DEFAULT '$escapedDefault'";
    }
    if ($autoInc) {
        $parts[] = "AUTO_INCREMENT";
    }
    if ($comment) {
        $escapedComment = $conn->real_escape_string($comment);
        $parts[] = "COMMENT '$escapedComment'";
    }

    $alterSQL = "ALTER TABLE `$table` ADD COLUMN " . implode(" ", $parts);
    if ($unique) {
        $alterSQL .= ", ADD UNIQUE (`$column`)";
    } elseif ($index) {
        $alterSQL .= ", ADD INDEX (`$column`)";
    }

    // Create column_change_history if it doesn't exist
    createColumnHistoryTable($conn);

    // Log changess
    logColumnChange($conn, [
        'db_name'      => $database,
        'table'        => $table,
        'column'       => $column,
        'old_type'     => $type,
        'action'       => 'ADD',
        'old_comment'  => $comment,
        'is_nullable'  => $nullable,
    ]);


    // Execute
    if ($conn->query($alterSQL) === TRUE) {
        jsonResponse(true, "Column `$column` added successfully to `$table`.");
    } else {
        jsonResponse(false, "Failed to add column.", ['error' => $conn->error]);
    }
}



function getTableColumn($input)
{
    if (empty($input['table'])) {
        jsonResponse(false, "Missing required field: table");
    }

    $table = $input['table'];
    $database = isset($input['database']) ? $input['database'] : '';

    // === Validate table name ===
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
        jsonResponse(false, "Invalid table name.");
    }

    // === Connect to database ===
    try {
        if ($database) {
            DBConnection::setDatabase($database);
        }
        $conn = DBConnection::getConnection();
    } catch (Exception $e) {
        jsonResponse(false, "Database connection failed.", ['error' => $e->getMessage()]);
    }

    // === Table existence check ===
    isTableExist($conn, $table, $database);
    $escapedTable = $conn->real_escape_string($table);

    // === Fetch column details from INFORMATION_SCHEMA ===
    $escapedDB = $conn->real_escape_string(DBConnection::getCurrentDatabase());
    $columnQuery = "
        SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA, COLUMN_COMMENT
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = '$escapedDB' AND TABLE_NAME = '$escapedTable'
        ORDER BY ORDINAL_POSITION
    ";

    $result = $conn->query($columnQuery);
    if (!$result) {
        jsonResponse(false, "Failed to retrieve columns.", ['error' => $conn->error]);
    }

    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row;
    }

    jsonResponse(true, "Columns retrieved successfully.", ['columns' => $columns]);
}




function updateTableColumn($input)
{
    $requiredFields = ['table', 'column_name', 'new_type'];
    foreach ($requiredFields as $field) {
        if (empty($input[$field])) {
            jsonResponse(false, "Missing required field: $field");
        }
    }

    $table       = $input['table'];
    $column      = $input['column_name'];
    $newType     = strtoupper($input['new_type']);
    $nullable    = isset($input['is_nullable']) ? (bool)$input['is_nullable'] : true;
    $default     = array_key_exists('default', $input) ? $input['default'] : null;
    $comment     = isset($input['comment']) ? $input['comment'] : '';
    $database    = isset($input['database']) ? $input['database'] : '';
    $newName     = isset($input['new_name']) ? $input['new_name'] : $column;
    $unique      = isset($input['unique']) ? (bool)$input['unique'] : false;

    if (!isValidIdentifier($column) || !isValidIdentifier($newName)) {
        jsonResponse(false, "Invalid column name.");
    }

    if (!isValidColumnType($newType)) {
        jsonResponse(false, "Invalid new column type.");
    }

    try {
        if ($database) {
            DBConnection::setDatabase($database);
        }
        $conn = DBConnection::getConnection();
    } catch (Exception $e) {
        jsonResponse(false, "Database connection failed.", ['error' => $e->getMessage()]);
    }

    // === Table existence check ===
    isTableExist($conn, $table, $database);

    // === Column metadata retrieval ===
    $escapedColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW FULL COLUMNS FROM `$table` LIKE '$escapedColumn'");
    if (!$result) {
        jsonResponse(false, "Failed to fetch column metadata.", ['error' => $conn->error]);
    }
    if ($result->num_rows === 0) {
        jsonResponse(false, "Column `$column` does not exist in `$table`.");
    }
    $columnMeta = $result->fetch_assoc();

    $oldType    = strtoupper($columnMeta['Type']);
    $oldNull    = $columnMeta['Null'] === 'YES';
    $oldDefault = $columnMeta['Default'];
    $oldComment = $columnMeta['Comment'];

    // === Safe conversion check ===
    if (!isSafeTypeConversion($oldType, $newType)) {
        jsonResponse(false, "Unsafe type conversion from `$oldType` to `$newType`.");
    }

    // === Warn if string truncation might happen ===
    if (preg_match('/char|text/i', $oldType) && preg_match('/\((\d+)\)/', $newType, $newMatch) && preg_match('/\((\d+)\)/', $oldType, $oldMatch)) {
        if ((int)$newMatch[1] < (int)$oldMatch[1]) {
            jsonResponse(false, "New type length may truncate existing data. Reduce size cautiously.");
        }
    }

    // === Create history table if not exists ===
    createColumnHistoryTable($conn);

    // === Backup old metadata into history table ===
    $nullStr     = $nullable ? 'YES' : 'NO';
    $oldNullStr  = $oldNull ? 'YES' : 'NO';
    $finalNewName = ($newName !== $column) ? $newName : null;

    logColumnChange($conn, [
        'db_name'      => $database,
        'table'        => $table,
        'column'       => $column,
        'old_type'     => $oldType,
        'new_type'     => $newType,
        'action'       => 'MODIFY',
        'old_comment'  => $oldComment,
        'new_comment'  => $comment,
        'is_nullable'  => $nullStr,
        'old_null'     => $oldNullStr,
        'oldDefault'   => $oldDefault,
        'default'      => $default,
        'new_name'     => $finalNewName
    ]);

    // === Build ALTER SQL ===
    $parts = [];
    $parts[] = "`$newName` $newType";
    $parts[] = $nullable ? "NULL" : "NOT NULL";

    if ($default !== null) {
        $escapedDefault = $conn->real_escape_string($default);
        $parts[] = "DEFAULT '$escapedDefault'";
    }

    if ($comment) {
        $escapedComment = $conn->real_escape_string($comment);
        $parts[] = "COMMENT '$escapedComment'";
    }

    $alterType = ($column === $newName) ? "MODIFY COLUMN" : "CHANGE COLUMN `$column`";
    $sql = "ALTER TABLE `$table` $alterType " . implode(" ", $parts);

    // === Execute ===
    if (!$conn->query($sql)) {
        jsonResponse(false, "Failed to update column structure.", ['error' => $conn->error]);
    }

    // === Toggle UNIQUE constraint ===
    $uniqueIndex = "uniq_{$table}_{$column}";

    $indexCheck = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '$uniqueIndex'");
    $indexExists = $indexCheck && $indexCheck->num_rows > 0;

    if ($unique && !$indexExists) {
        $conn->query("ALTER TABLE `$table` ADD CONSTRAINT `$uniqueIndex` UNIQUE (`$newName`)");
    } elseif (!$unique && $indexExists) {
        $conn->query("ALTER TABLE `$table` DROP INDEX `$uniqueIndex`");
    }

    jsonResponse(true, "Column structure and uniqueness updated successfully.");
}



function deleteTableColumn($input)
{
    $requiredFields = ['table', 'column_name'];
    foreach ($requiredFields as $field) {
        if (empty($input[$field])) {
            jsonResponse(false, "Missing required field: $field");
        }
    }

    $table     = $input['table'];
    $column    = $input['column_name'];
    $database  = isset($input['database']) ? $input['database'] : '';

    if (!isValidIdentifier($column)) {
        jsonResponse(false, "Invalid column name.");
    }

    try {
        if ($database) {
            DBConnection::setDatabase($database);
        }
        $conn = DBConnection::getConnection();
    } catch (Exception $e) {
        jsonResponse(false, "Database connection failed.", ['error' => $e->getMessage()]);
    }

    isTableExist($conn, $table, $database);

    // Check if column exists
    $columnCheck = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if (!$columnCheck || $columnCheck->num_rows === 0) {
        jsonResponse(false, "Column `$column` does not exist in table `$table`.");
    }

    $columnMeta = $columnCheck->fetch_assoc();
    $columnType = $columnMeta['Type'];
    $oldComment = isset($columnMeta['Comment']) ? $columnMeta['Comment'] : '';

    // Check if column is in a foreign key constraint
    $fkQuery = "
        SELECT CONSTRAINT_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = '$table'
          AND COLUMN_NAME = '$column'
          AND REFERENCED_TABLE_NAME IS NOT NULL
    ";
    $fkResult = $conn->query($fkQuery);
    if ($fkResult && $fkResult->num_rows > 0) {
        jsonResponse(false, "Cannot delete column `$column`: it's part of a foreign key constraint.");
    }

    // Backup table structure
    $backupFile = __DIR__ . "/../backups/{$table}_structure_backup_" . date("Ymd_His") . ".sql";
    if (!is_dir(dirname($backupFile))) {
        mkdir(dirname($backupFile), 0777, true);
    }
    $createStmt = $conn->query("SHOW CREATE TABLE `$table`");
    if ($createStmt && $row = $createStmt->fetch_assoc()) {
        file_put_contents($backupFile, $row['Create Table']);
    }

    // Create column_change_history if it doesn't exist
    createColumnHistoryTable($conn);

    // Log deletion
    logColumnChange($conn, [
        'db_name'      => $database,
        'table'        => $table,
        'column'       => $column,
        'old_type'     => $columnType,
        'action'       => 'DROP',
        'old_comment'  => $oldComment

    ]);

    // Delete the column
    $sql = "ALTER TABLE `$table` DROP COLUMN `$column`";
    if ($conn->query($sql)) {
        jsonResponse(true, "Column `$column` deleted successfully.");
    } else {
        jsonResponse(false, "Failed to delete column.", ['error' => $conn->error]);
    }
}
