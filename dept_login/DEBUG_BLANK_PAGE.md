# Debugging Blank Page in Departmental_Governance.php

## Quick Fix Instructions

### Step 1: Enable Debug Mode
At the top of `Departmental_Governance.php`, find:
```php
$DEBUG_MODE = false; // Change to true to see errors
```

Change it to:
```php
$DEBUG_MODE = true; // Change to true to see errors
```

This will show you the actual error message instead of a blank page.

### Step 2: Check Common Issues

1. **Database Connection**: Verify `config.php` sets `$conn` correctly
2. **Session Data**: Verify `session.php` sets `$userInfo['DEPT_ID']`
3. **Required Files**: Ensure all these exist:
   - `session.php`
   - `unified_header.php`
   - `unified_footer.php`
   - `csrf.php`
   - `common_upload_handler.php`

### Step 3: Check Error Logs
Look in your PHP error log for messages starting with:
- `Departmental_Governance: Database connection`
- `Departmental_Governance: Department ID not found`
- `Departmental_Governance: Error loading header`

### Step 4: Test Database Connection
Create `test_connection.php` in `dept_login/`:
```php
<?php
require_once('../config.php');
if (!isset($conn) || !mysqli_ping($conn)) {
    die("Database connection FAILED: " . mysqli_error($conn));
}
echo "Database OK. Testing session...\n";
require('session.php');
echo "DEPT_ID: " . ($userInfo['DEPT_ID'] ?? 'MISSING');
```

## Changes Made

1. ✅ Added `$DEBUG_MODE` flag for easy error visibility
2. ✅ Fixed aggressive buffer clearing (now uses safety checks)
3. ✅ Improved database connection error handling
4. ✅ Replaced `throw Exception` with proper error handling
5. ✅ Added debug logging for AJAX handler triggers
6. ✅ Improved error messages in catch blocks

## After Fixing

Once you identify and fix the issue, set `$DEBUG_MODE = false;` to hide errors in production.

