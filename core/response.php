<?php

function jsonResponse($status, $message, $extra = [])
{
    echo json_encode(array_merge([
        'status' => $status,
        'message' => $message
    ], $extra));
    exit;
}

function isTableExist($conn, $table, $database)
{
    $escapedTable = $conn->real_escape_string($table);
    $query = "SHOW TABLES LIKE '$escapedTable'";
    $result = $conn->query($query);

    if (!$result) {
        jsonResponse(false, "Table existence check failed.", ['error' => $conn->error]);
    }

    if ($result->num_rows === 0) {
        jsonResponse(false, "Table `$table` does not exist in database `$database`.");
    }
    // return $result;
}
