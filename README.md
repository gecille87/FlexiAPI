# FlexiAPI— Flex It. Build It. Rule It.

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
* ✅ Auto creation deleted history for row change
* ✅ Pagination added when getting row data
* ✅ Dynamic Endpoint (multiple folder creation) 
* ✅ Setup via CLI
* ✅ Auto backup for db config changes


## How to setup

### Step 1 - Clone repository

### Step 2 - Setup your Database (if no database/table yet)

### Step 3 - run `index.php` via terminal

if using XAMPP phpmyadmin: `C:\xampp\php\php.exe index.php`

### Step 4 - Follow Setup prompts
Configure your database first to modify your  `servername` , `username`, `password` and `default database name`.

Once done, you may now create your folders for your API endpoint.

Sample:
```
Menu:
[1] Create Folders
[2] Configure Database
[0] Exit

```


## How to use API

Create folders for your endpoint of your database. All endpoints require **raw JSON POST** for `CREATE`, `UPDATE`, `DELETE` while the `GET` method will require get request. 

`"database"` key is **always** optional. You can add this one just incase you are referring to a new database instead of the  `default database name`. 


Here are the list of sample inputs:

### Create Column Sample Input

```
{
  "database": "main", // optional
  "table": "users", // required
  "column_name": "status", // required
  "column_type": "VARCHAR(50)",  // required
  "is_nullable": false,  // optional
  "default": "active",  // optional
  "auto_increment": false,  // optional
  "unique": true,  // optional
  "index": false,  // optional
  "comment": "User status field"  // optional
}

```
### Get Column Sample Input

Method: `GET`
Params:
```
{
    "database": "your_database_name", // optional
    "table": "users" // required
}

```

### Update Column Sample Input

```
{
  "database": "my_db", // optional
  "table": "users", // required
  "column_name": "username", // required
  "new_type": "VARCHAR(100)", // required for changing datatype
  "is_nullable": false, // optional
  "default": "guest", // optional
  "comment": "Updated column length and default", // optional adding comment
  "unique": true, // optional for unique values
  "new_name" : "userProfile" // optional changing column name

}

```


### Delete Column Sample Input


```
{
  "database": "my_db", // optional
  "table": "users", // required
  "column_name": "username" // required
}

```


### Create Row Sample Input

On here, you may still add "database" key just incase you are referring to a new database instead of the  `default database name`. 

```
{
  "table": "users",
  "data": [
    {
      "name": "Jane Doe",
      "email": "jane@example.com",
      "age": 25
    },
    {
      "name": "Paul",
      "email": "paul@example.com",
      "age": 25
    }
  ]
}


```

### Get Row Sample Input

Method: `GET`

Getting / fetching table rows supports the following:

1. Get all rows
2. Get a specific row (via condition)
3. Get all contents of a specific column
4. Pagination support

**Get all rows (page 1, limit 10):**
Params: 


```
{
  "table": "users",
  "page": 1,
  "limit": 10
}
```
**Get a specific column:**
Params: 

```
{
  "table": "users",
  "column": "email",
  "page": 1,
  "limit": 5
}

```

**Get a specific row by unique ID / column name:**
Params: 

```
{
  "table": "users",
  "condition": { "id": 1 },
  "page": 1,
  "limit": 1
}

```

## Update Row Sample Input

```
{
  "table": "users",
  "where": {
    "id": 1
  },
  "data": {
    "name": "Updated Name",
    "email": "newemail@example.com"
  }
}

```


## Delete Row Sample Input

* ✅ Supports multiple deletion with limit.
* ✅ If more than limit, abort safely before any deletion.
* ✅ Logs the deleted rows in deleted_{table} for recovery.

```
{
  "table": "products",
  "column": "email",
  "values": ["sample@sample.com"],
  "limit": 5
}

```




`fin`
