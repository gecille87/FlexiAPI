# FlexiDB — Flex It. Build It. Rule It.

Tired of manually setting up database ? Me too 🤪 

FlexiDB is a lightweight API that lets you create, modify, and manage your MySQL databases dynamically with built-in security, history tracking, and flexible controls.

## Features

* ✅ Dynamic column creation with safe escape.
* ✅ Expects application/json input.
* ✅ Escapes identifiers to prevent SQL injection
* ✅ Validates all fields strictly.
* ✅ Get, Update, or Delete a Column in database
* ✅ Auto creation history for any Column Change
* ✅ Create multiple Rows in table
* ✅ Get or Update Rows in table. 
* ✅ Deletion of multiple rows with dynamic limit.
* ✅ Pagination added when getting row data
* ✅ Dynamic Endpoint (multiple folder creation) `beta`


## How to setup

### Step 1 - Clone repository

### Step 2 - Configure Database

Head over to `core\db_config.php` and edit database credentials:

```
    private static $instance = null;
    private static $servername = "localhost";
    private static $username = "admin";
    private static $password = "";
    private static $defaultDB = "database_name";
    private static $currentDB = null;

```


