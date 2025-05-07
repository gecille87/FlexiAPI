<?php
require_once 'db_config.php';
require_once 'response.php';


// Deny direct access
if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) {
    http_response_code(403);
    exit("Access denied.");
}


function createTableRow($input)
{
    $requiredFields = ['table', 'data'];
    foreach ($requiredFields as $field) {
        if (empty($input[$field])) {
            jsonResponse(false, "Missing required field: $field");
        }
    }

    $table = $input['table'];
    $data  = $input['data'];
    $database = $input['database'] ?? '';

    if (!is_array($data) || empty($data)) {
        jsonResponse(false, "Data must be a non-empty array.");
    }

    // Normalize single row insert
    if (!is_array($data[0])) {
        $data = [$data]; // wrap in array
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

    // Fetch column info
    $columnsMeta = [];
    $result = $conn->query("SHOW COLUMNS FROM `$table`");
    while ($col = $result->fetch_assoc()) {
        $columnsMeta[$col['Field']] = $col;
    }

    $firstRow = $data[0];
    $fields = array_keys($firstRow);
    $placeholders = array_fill(0, count($fields), '?');
    $columnsSql = implode(',', array_map(fn($f) => "`$f`", $fields));
    $placeholdersSql = implode(',', $placeholders);
    $sql = "INSERT INTO `$table` ($columnsSql) VALUES ($placeholdersSql)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        jsonResponse(false, "Failed to prepare statement.", ['error' => $conn->error]);
    }

    foreach ($data as $row) {
        $values = [];
        $types = '';

        foreach ($fields as $field) {
            if (!array_key_exists($field, $columnsMeta)) {
                jsonResponse(false, "Invalid column: `$field`");
            }

            $meta = $columnsMeta[$field];
            $value = $row[$field] ?? null;

            // Nullability check
            if ($meta['Null'] === 'NO' && $meta['Default'] === null && $value === null) {
                jsonResponse(false, "Field `$field` cannot be null.");
            }

            // Type validation
            if (stripos($meta['Type'], 'int') !== false && !is_numeric($value)) {
                jsonResponse(false, "Field `$field` must be numeric.");
            }

            if (preg_match('/varchar\((\d+)\)/', $meta['Type'], $matches)) {
                $maxLength = (int)$matches[1];
                if (strlen($value) > $maxLength) {
                    jsonResponse(false, "Field `$field` exceeds maximum length of $maxLength.");
                }
            }

            $values[] = $value;

            if (is_int($value)) {
                $types .= 'i';
            } elseif (is_float($value)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }

        $stmt->bind_param($types, ...$values);

        if (!$stmt->execute()) {
            jsonResponse(false, "Failed to insert data.", ['error' => $stmt->error]);
        }
    }

    $stmt->close();

    jsonResponse(true, "Row(s) inserted successfully.");
}


function getTableRow($input)
{
    $requiredFields = ['table'];
    foreach ($requiredFields as $field) {
        if (empty($input[$field])) {
            jsonResponse(false, "Missing required field: $field");
        }
    }

    $table      = $input['table'];
    $database   = $input['database'] ?? '';
    $column     = $input['column'] ?? '*'; // fetch all columns by default
    $condition  = $input['condition'] ?? null; // e.g., ['id' => 5]
    $page       = isset($input['page']) ? (int)$input['page'] : 1;
    $limit      = isset($input['limit']) ? (int)$input['limit'] : 20;
    $offset     = ($page - 1) * $limit;

    if ($page < 1 || $limit <= 0 || $limit > 100) {
        jsonResponse(false, "Invalid pagination parameters.");
    }

    try {
        if ($database) DBConnection::setDatabase($database);
        $conn = DBConnection::getConnection();
    } catch (Exception $e) {
        jsonResponse(false, "Database connection failed.", ['error' => $e->getMessage()]);
    }

    // Validate table
    isTableExist($conn, $table, $database);

    // Validate column(s)
    $validColumns = [];
    $colResult = $conn->query("SHOW COLUMNS FROM `$table`");
    while ($col = $colResult->fetch_assoc()) {
        $validColumns[] = $col['Field'];
    }

    if ($column !== '*' && !in_array($column, $validColumns)) {
        jsonResponse(false, "Invalid column: `$column`");
    }

    $sql = "SELECT ";
    $sql .= $column === '*' ? '*' : "`$column`";
    $sql .= " FROM `$table`";

    $params = [];
    $types = '';
    $whereClause = '';

    if (is_array($condition) && count($condition) > 0) {
        $conditions = [];
        foreach ($condition as $key => $val) {
            if (!in_array($key, $validColumns)) {
                jsonResponse(false, "Invalid condition column: `$key`");
            }
            $conditions[] = "`$key` = ?";
            $params[] = $val;
            $types .= is_int($val) ? 'i' : (is_float($val) ? 'd' : 's');
        }
        $whereClause = " WHERE " . implode(" AND ", $conditions);
    }

    $sql .= $whereClause . " LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        jsonResponse(false, "Failed to prepare query.", ['error' => $conn->error]);
    }

    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        jsonResponse(false, "Failed to execute query.", ['error' => $stmt->error]);
    }

    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get total count for pagination info
    $countQuery = "SELECT COUNT(*) as total FROM `$table`" . $whereClause;
    $countStmt = $conn->prepare($countQuery);
    if ($whereClause) {
        $countTypes = substr($types, 0, -2);
        $countParams = array_slice($params, 0, -2);
        $countStmt->bind_param($countTypes, ...$countParams);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalRows = $countResult->fetch_assoc()['total'];
    $countStmt->close();

    jsonResponse(true, "Data retrieved successfully.", [
        'data' => $rows,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_rows' => $totalRows,
            'total_pages' => ceil($totalRows / $limit)
        ]
    ]);
}


function updateTableRow($input)
{
    $requiredFields = ['table', 'where', 'data'];
    foreach ($requiredFields as $field) {
        if (empty($input[$field])) {
            jsonResponse(false, "Missing required field: $field");
        }
    }

    $table = $input['table'];
    $where = $input['where'];
    $data  = $input['data'];
    $database = isset($input['database']) ? $input['database'] : '';

    if (empty($data)) {
        jsonResponse(false, "Data to update cannot be empty.");
    }

    try {
        if ($database) {
            DBConnection::setDatabase($database);
        }
        $conn = DBConnection::getConnection();
    } catch (Exception $e) {
        jsonResponse(false, "Database connection failed.", ['error' => $e->getMessage()]);
    }

    // Table existence check
    isTableExist($conn, $table, $database);

    // Fetch table columns
    $columnsMeta = [];
    $result = $conn->query("SHOW COLUMNS FROM `$table`");
    while ($col = $result->fetch_assoc()) {
        $columnsMeta[$col['Field']] = $col;
    }

    $setClause = [];
    $whereClause = [];
    $values = [];
    $types = '';

    // Validate and prepare SET clause
    foreach ($data as $col => $val) {
        if (!array_key_exists($col, $columnsMeta)) {
            jsonResponse(false, "Invalid column in data: `$col`");
        }

        $meta = $columnsMeta[$col];

        if ($meta['Null'] === 'NO' && $meta['Default'] === null && $val === null) {
            jsonResponse(false, "Field `$col` cannot be null.");
        }

        if (stripos($meta['Type'], 'int') !== false && !is_numeric($val)) {
            jsonResponse(false, "Field `$col` must be numeric.");
        }

        if (preg_match('/varchar\((\d+)\)/', $meta['Type'], $matches)) {
            $maxLength = (int)$matches[1];
            if (strlen($val) > $maxLength) {
                jsonResponse(false, "Field `$col` exceeds max length of $maxLength.");
            }
        }

        $setClause[] = "`$col` = ?";
        $values[] = $val;
        $types .= is_int($val) ? 'i' : (is_float($val) ? 'd' : 's');
    }

    // Validate and prepare WHERE clause
    foreach ($where as $col => $val) {
        if (!array_key_exists($col, $columnsMeta)) {
            jsonResponse(false, "Invalid column in WHERE: `$col`");
        }

        $whereClause[] = "`$col` = ?";
        $values[] = $val;
        $types .= is_int($val) ? 'i' : (is_float($val) ? 'd' : 's');
    }

    // Ensure the record exists first
    $selectSQL = "SELECT COUNT(*) as count FROM `$table` WHERE " . implode(" AND ", $whereClause);
    $selectStmt = $conn->prepare($selectSQL);
    // $selectTypes = substr($types, strlen($data));
    $selectTypes = substr($types, count($data));
    $selectParams = array_slice($values, count($data));
    $selectStmt->bind_param($selectTypes, ...$selectParams);
    $selectStmt->execute();
    $result = $selectStmt->get_result();
    $rowExists = $result->fetch_assoc()['count'] > 0;
    $selectStmt->close();

    if (!$rowExists) {
        jsonResponse(false, "Record to update not found.");
    }

    // Final update query
    $sql = "UPDATE `$table` SET " . implode(", ", $setClause) . " WHERE " . implode(" AND ", $whereClause);
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        jsonResponse(false, "Failed to prepare update statement.", ['error' => $conn->error]);
    }

    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) {
        jsonResponse(false, "Failed to update record.", ['error' => $stmt->error]);
    }

    $stmt->close();

    jsonResponse(true, "Record updated successfully.");
}



function deleteTableRow($input)
{
    // Required Input Validation
    $requiredFields = ['table', 'column', 'values'];
    foreach ($requiredFields as $field) {
        if (empty($input[$field])) {
            jsonResponse(false, "Missing required field: $field");
        }
    }

    $table     = $input['table'];
    $column    = $input['column'];
    $values    = $input['values'];
    $database  = isset($input['database']) ? $input['database'] : '';
    $maxDelete = 100;
    $batchLimit = isset($input['limit']) && is_numeric($input['limit']) ? min((int)$input['limit'], $maxDelete) : $maxDelete;

    if (!is_array($values) || empty($values)) {
        jsonResponse(false, "Values must be a non-empty array.");
    }

    if (count($values) > $batchLimit) {
        jsonResponse(false, "Too many rows requested for deletion. Max allowed per request is $batchLimit.");
    }

    try {
        if ($database) DBConnection::setDatabase($database);
        $conn = DBConnection::getConnection();
    } catch (Exception $e) {
        jsonResponse(false, "Database connection failed.", ['error' => $e->getMessage()]);
    }

    isTableExist($conn, $table, $database);

    $result = $conn->query("SHOW COLUMNS FROM `$table`");
    $columnExists = false;
    while ($col = $result->fetch_assoc()) {
        if ($col['Field'] === $column) {
            $columnExists = true;
            break;
        }
    }

    if (!$columnExists) {
        jsonResponse(false, "Column `$column` does not exist in table `$table`.");
    }

    try {
        // Step 1: Prepare the placeholders
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $types = str_repeat('s', count($values));
        $bindParams = [];
        foreach ($values as &$val) $bindParams[] = &$val;

        // Step 2: First, Count the matching rows
        $countSql = "SELECT COUNT(*) as cnt FROM `$table` WHERE `$column` IN ($placeholders)";
        $countStmt = $conn->prepare($countSql);
        $countStmt->bind_param($types, ...$bindParams);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $row = $countResult->fetch_assoc();
        $countStmt->close();

        $expectedDeleteCount = (int)$row['cnt'];

        if ($expectedDeleteCount === 0) {
            jsonResponse(false, "No matching records found to delete.");
        }

        if ($expectedDeleteCount > $batchLimit) {
            jsonResponse(false, "Aborted: Trying to delete $expectedDeleteCount rows, limit is $batchLimit.");
        }

        // Step 3: Now Start Transaction
        $conn->begin_transaction();

        // Step 4: Fetch data to log
        $selectSql = "SELECT * FROM `$table` WHERE `$column` IN ($placeholders)";
        $selectStmt = $conn->prepare($selectSql);
        $selectStmt->bind_param($types, ...$bindParams);
        $selectStmt->execute();
        $result = $selectStmt->get_result();
        $rowsToLog = $result->fetch_all(MYSQLI_ASSOC);
        $selectStmt->close();

        // Step 5: Create log table if not exist
        $logTable = "deleted_{$table}";
        $createLogSQL = "CREATE TABLE IF NOT EXISTS `$logTable` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            deleted_data JSON NOT NULL,
            deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        if (!$conn->query($createLogSQL)) {
            $conn->rollback();
            jsonResponse(false, "Failed to create/access log table.", ['error' => $conn->error]);
        }

        // Step 6: Insert logs
        $logStmt = $conn->prepare("INSERT INTO `$logTable` (deleted_data) VALUES (?)");
        foreach ($rowsToLog as $row) {
            $jsonData = json_encode($row);
            $logStmt->bind_param('s', $jsonData);
            if (!$logStmt->execute()) {
                $conn->rollback();
                jsonResponse(false, "Failed to log deleted data.", ['error' => $logStmt->error]);
            }
        }
        $logStmt->close();

        // Step 7: Finally, delete the rows
        $deleteSql = "DELETE FROM `$table` WHERE `$column` IN ($placeholders)";
        $deleteStmt = $conn->prepare($deleteSql);
        $deleteStmt->bind_param($types, ...$bindParams);
        if (!$deleteStmt->execute()) {
            $conn->rollback();
            jsonResponse(false, "Failed to delete rows.", ['error' => $deleteStmt->error]);
        }
        $deletedCount = $deleteStmt->affected_rows;
        $deleteStmt->close();

        // Step 8: Double check again
        if ($deletedCount !== $expectedDeleteCount) {
            $conn->rollback();
            jsonResponse(false, "Mismatch after deletion. Aborting to prevent data loss.");
        }

        $conn->commit();

        jsonResponse(true, "Successfully deleted and logged $deletedCount row(s).", [
            'table' => $table,
            'log_table' => $logTable,
            'deleted_count' => $deletedCount
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(false, "Transaction failed.", ['error' => $e->getMessage()]);
    }
}
