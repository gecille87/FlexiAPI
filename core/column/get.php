<?php

require_once '../../../core/columnManager.php';
require_once '../../../core/response.php';

header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $validated_data = [];

    $validated_data['table'] = isset($_GET['table']) ? htmlspecialchars($_GET['table'], ENT_QUOTES, 'UTF-8') : null;
    $validated_data['database'] = isset($_GET['database']) ? htmlspecialchars($_GET['database'], ENT_QUOTES, 'UTF-8') : null;


    // $input = json_encode($validated_data);
    getTableColumn($validated_data);
} else {
    jsonResponse(false, "GET request required.");
}
