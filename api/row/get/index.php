<?php

require_once '../../../core/rowManager.php';
require_once '../../../core/response.php';
header('Content-Type: application/json');

// Handle JSON POST request
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $input = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonResponse(false, "Invalid JSON input.");
    }

    getTableRow($input);
    // jsonResponse(false, "success", [$input]);
} else {
    jsonResponse(false, "Input required.");
}
