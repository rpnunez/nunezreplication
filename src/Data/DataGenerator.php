<?php

namespace NunezReplication\Data;

use PDO;

class DataGenerator
{
    private $pdo;
    
    // Sample data pools for realistic generation
    private $firstNames = [
        'James', 'Mary', 'John', 'Patricia', 'Robert', 'Jennifer', 'Michael', 'Linda',
        'William', 'Barbara', 'David', 'Elizabeth', 'Richard', 'Susan', 'Joseph', 'Jessica',
        'Thomas', 'Sarah', 'Charles', 'Karen', 'Christopher', 'Nancy', 'Daniel', 'Lisa',
        'Matthew', 'Betty', 'Anthony', 'Margaret', 'Mark', 'Sandra', 'Donald', 'Ashley',
        'Steven', 'Kimberly', 'Paul', 'Emily', 'Andrew', 'Donna', 'Joshua', 'Michelle',
        'Kenneth', 'Dorothy', 'Kevin', 'Carol', 'Brian', 'Amanda', 'George', 'Melissa',
        'Edward', 'Deborah', 'Ronald', 'Stephanie', 'Timothy', 'Rebecca', 'Jason', 'Sharon',
        'Jeffrey', 'Laura', 'Ryan', 'Cynthia', 'Jacob', 'Kathleen', 'Gary', 'Amy',
        'Nicholas', 'Shirley', 'Eric', 'Angela', 'Jonathan', 'Helen', 'Stephen', 'Anna',
        'Larry', 'Brenda', 'Justin', 'Pamela', 'Scott', 'Nicole', 'Brandon', 'Emma'
    ];
    
    private $lastNames = [
        'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis',
        'Rodriguez', 'Martinez', 'Hernandez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson', 'Thomas',
        'Taylor', 'Moore', 'Jackson', 'Martin', 'Lee', 'Perez', 'Thompson', 'White',
        'Harris', 'Sanchez', 'Clark', 'Ramirez', 'Lewis', 'Robinson', 'Walker', 'Young',
        'Allen', 'King', 'Wright', 'Scott', 'Torres', 'Nguyen', 'Hill', 'Flores',
        'Green', 'Adams', 'Nelson', 'Baker', 'Hall', 'Rivera', 'Campbell', 'Mitchell',
        'Carter', 'Roberts', 'Gomez', 'Phillips', 'Evans', 'Turner', 'Diaz', 'Parker',
        'Cruz', 'Edwards', 'Collins', 'Reyes', 'Stewart', 'Morris', 'Morales', 'Murphy',
        'Cook', 'Rogers', 'Gutierrez', 'Ortiz', 'Morgan', 'Cooper', 'Peterson', 'Bailey',
        'Reed', 'Kelly', 'Howard', 'Ramos', 'Kim', 'Cox', 'Ward', 'Richardson'
    ];
    
    private $streetNames = [
        'Main', 'Oak', 'Pine', 'Maple', 'Cedar', 'Elm', 'Washington', 'Lake', 'Hill', 'Park',
        'First', 'Second', 'Third', 'Fourth', 'Fifth', 'Sixth', 'Seventh', 'Eighth', 'Ninth', 'Tenth',
        'Church', 'Market', 'Walnut', 'Chestnut', 'Broad', 'Spring', 'Franklin', 'Highland', 'Forest', 'Lincoln'
    ];
    
    private $streetTypes = ['St', 'Ave', 'Rd', 'Dr', 'Ln', 'Blvd', 'Way', 'Ct', 'Pl'];
    
    private $cities = [
        'New York', 'Los Angeles', 'Chicago', 'Houston', 'Phoenix', 'Philadelphia', 'San Antonio', 'San Diego',
        'Dallas', 'San Jose', 'Austin', 'Jacksonville', 'Fort Worth', 'Columbus', 'San Francisco', 'Charlotte',
        'Indianapolis', 'Seattle', 'Denver', 'Boston', 'El Paso', 'Detroit', 'Nashville', 'Portland',
        'Memphis', 'Oklahoma City', 'Las Vegas', 'Louisville', 'Baltimore', 'Milwaukee', 'Albuquerque', 'Tucson',
        'Fresno', 'Mesa', 'Sacramento', 'Atlanta', 'Kansas City', 'Colorado Springs', 'Miami', 'Raleigh'
    ];
    
    private $states = [
        'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA',
        'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD',
        'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ',
        'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC',
        'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY'
    ];
    
    private $transactionTypes = ['deposit', 'withdrawal', 'transfer'];
    private $accountTypes = ['checking', 'savings', 'business'];
    private $accountStatuses = ['active', 'inactive', 'closed'];
    
    private $descriptions = [
        'deposit' => [
            'Salary deposit', 'Direct deposit', 'Cash deposit', 'Check deposit', 'Wire transfer',
            'Paycheck', 'Bonus payment', 'Tax refund', 'Investment return', 'Dividend payment'
        ],
        'withdrawal' => [
            'ATM withdrawal', 'Bill payment', 'Grocery shopping', 'Gas station', 'Restaurant',
            'Online purchase', 'Utility payment', 'Rent payment', 'Mortgage payment', 'Insurance premium'
        ],
        'transfer' => [
            'Transfer to savings', 'Transfer to checking', 'Internal transfer', 'Account transfer',
            'Balance transfer', 'Fund transfer', 'Emergency transfer', 'Investment transfer'
        ]
    ];
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    
    /**
     * Generate data for a specific table
     */
    public function generateDataForTable($tableName, $rowCount, $foreignKeyData = [])
    {
        $columns = $this->getTableColumns($tableName);
        
        $insertedIds = [];
        
        for ($i = 0; $i < $rowCount; $i++) {
            $data = $this->generateRowData($tableName, $columns, $foreignKeyData);
            
            $columnNames = array_keys($data);
            $placeholders = array_map(function($col) { return ":$col"; }, $columnNames);
            
            $sql = sprintf(
                "INSERT INTO `%s` (%s) VALUES (%s)",
                $tableName,
                implode(', ', array_map(function($col) { return "`$col`"; }, $columnNames)),
                implode(', ', $placeholders)
            );
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($data);
            
            $insertedIds[] = $this->pdo->lastInsertId();
        }
        
        return $insertedIds;
    }
    
    /**
     * Get table columns and their metadata
     */
    private function getTableColumns($tableName)
    {
        // Validate table name exists in database to prevent SQL injection
        $stmt = $this->pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!in_array($tableName, $tables)) {
            throw new \Exception("Invalid table name: $tableName");
        }
        
        // Table name is validated, safe to use in query
        $stmt = $this->pdo->prepare("SHOW COLUMNS FROM `$tableName`");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Generate data for a single row
     */
    private function generateRowData($tableName, $columns, $foreignKeyData)
    {
        $data = [];
        
        foreach ($columns as $column) {
            $columnName = $column['Field'];
            $columnType = $column['Type'];
            $isNullable = $column['Null'] === 'YES';
            $extra = $column['Extra'];
            
            // Skip auto-increment columns
            if (strpos($extra, 'auto_increment') !== false) {
                continue;
            }
            
            // Skip timestamp columns with CURRENT_TIMESTAMP default
            $defaultValue = $column['Default'] ?? null;
            if (strpos($columnType, 'timestamp') !== false && $defaultValue !== null && strpos($defaultValue, 'CURRENT_TIMESTAMP') !== false) {
                continue;
            }
            
            // Handle foreign keys
            if (isset($foreignKeyData[$columnName]) && !empty($foreignKeyData[$columnName])) {
                $data[$columnName] = $foreignKeyData[$columnName][array_rand($foreignKeyData[$columnName])];
                continue;
            }
            
            // Generate based on column name patterns (Banking App specific)
            if ($this->matchesPattern($columnName, ['first_name', 'firstname'])) {
                $data[$columnName] = $this->randomElement($this->firstNames);
            } elseif ($this->matchesPattern($columnName, ['last_name', 'lastname'])) {
                $data[$columnName] = $this->randomElement($this->lastNames);
            } elseif ($this->matchesPattern($columnName, ['email'])) {
                $data[$columnName] = $this->generateEmail();
            } elseif ($this->matchesPattern($columnName, ['phone'])) {
                $data[$columnName] = $this->generatePhone();
            } elseif ($this->matchesPattern($columnName, ['address'])) {
                $data[$columnName] = $this->generateAddress();
            } elseif ($this->matchesPattern($columnName, ['account_number', 'reference_number'])) {
                $data[$columnName] = $this->generateAccountNumber($tableName);
            } elseif ($this->matchesPattern($columnName, ['account_type'])) {
                $data[$columnName] = $this->randomElement($this->accountTypes);
            } elseif ($this->matchesPattern($columnName, ['transaction_type'])) {
                $data[$columnName] = $this->randomElement($this->transactionTypes);
            } elseif ($this->matchesPattern($columnName, ['status'])) {
                $data[$columnName] = $this->randomElement($this->accountStatuses);
            } elseif ($this->matchesPattern($columnName, ['balance', 'amount'])) {
                $data[$columnName] = $this->generateDecimal(10, 10000);
            } elseif ($this->matchesPattern($columnName, ['description'])) {
                // Use transaction type from data if available for better context
                $txType = $data['transaction_type'] ?? 'deposit';
                $data[$columnName] = $this->generateDescription($txType);
            } else {
                // Generate based on column type
                $data[$columnName] = $this->generateByType($columnType, $columnName, $isNullable);
            }
        }
        
        return $data;
    }
    
    /**
     * Update existing data in a table
     */
    public function updateDataInTable($tableName, $updateCount)
    {
        $columns = $this->getTableColumns($tableName);
        $updatableColumns = $this->getUpdatableColumns($columns);
        
        if (empty($updatableColumns)) {
            return ['updated' => 0, 'message' => 'No updatable columns found'];
        }
        
        // Get primary key
        $primaryKey = $this->getPrimaryKey($tableName);
        if (!$primaryKey) {
            return ['updated' => 0, 'message' => 'No primary key found'];
        }
        
        // Sanitize limit value
        $limit = (int)$updateCount;
        if ($limit <= 0) {
            return ['updated' => 0, 'message' => 'Invalid update count'];
        }
        
        // Get random rows to update (table and column names already validated)
        $stmt = $this->pdo->query("SELECT `$primaryKey` FROM `$tableName` ORDER BY RAND() LIMIT $limit");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $updatedCount = 0;
        foreach ($rows as $row) {
            $updateData = [];
            
            // Randomly select 1-3 columns to update
            $columnsToUpdate = $this->randomSubset($updatableColumns, rand(1, min(3, count($updatableColumns))));
            
            foreach ($columnsToUpdate as $column) {
                $updateData[$column['Field']] = $this->generateUpdateValue($column);
            }
            
            if (!empty($updateData)) {
                $setParts = [];
                foreach (array_keys($updateData) as $col) {
                    $setParts[] = "`$col` = :$col";
                }
                
                $sql = sprintf(
                    "UPDATE `%s` SET %s WHERE `%s` = :pk_value",
                    $tableName,
                    implode(', ', $setParts),
                    $primaryKey
                );
                
                $updateData['pk_value'] = $row[$primaryKey];
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($updateData);
                $updatedCount++;
            }
        }
        
        return ['updated' => $updatedCount, 'message' => "Updated $updatedCount rows"];
    }
    
    /**
     * Get updatable columns (exclude PKs, auto-increment, etc.)
     */
    private function getUpdatableColumns($columns)
    {
        $updatable = [];
        
        foreach ($columns as $column) {
            $columnName = $column['Field'];
            $extra = $column['Extra'];
            
            // Skip auto-increment
            if (strpos($extra, 'auto_increment') !== false) {
                continue;
            }
            
            // Skip primary keys (usually 'id')
            if ($columnName === 'id' || strpos($columnName, '_id') !== false) {
                continue;
            }
            
            // Skip created_at
            if ($this->matchesPattern($columnName, ['created_at', 'createdat'])) {
                continue;
            }
            
            // Include common updatable fields
            if ($this->matchesPattern($columnName, [
                'name', 'title', 'description', 'updated_at', 'status',
                'email', 'phone', 'address', 'balance', 'amount'
            ])) {
                $updatable[] = $column;
            }
        }
        
        return $updatable;
    }
    
    /**
     * Generate an update value for a column
     */
    private function generateUpdateValue($column)
    {
        $columnName = $column['Field'];
        $columnType = $column['Type'];
        
        if ($this->matchesPattern($columnName, ['first_name', 'firstname'])) {
            return $this->randomElement($this->firstNames);
        } elseif ($this->matchesPattern($columnName, ['last_name', 'lastname'])) {
            return $this->randomElement($this->lastNames);
        } elseif ($this->matchesPattern($columnName, ['email'])) {
            return $this->generateEmail();
        } elseif ($this->matchesPattern($columnName, ['phone'])) {
            return $this->generatePhone();
        } elseif ($this->matchesPattern($columnName, ['address'])) {
            return $this->generateAddress();
        } elseif ($this->matchesPattern($columnName, ['description', 'title', 'name'])) {
            return $this->generateRandomText(20, 100);
        } elseif ($this->matchesPattern($columnName, ['balance', 'amount'])) {
            return $this->generateDecimal(10, 10000);
        } elseif ($this->matchesPattern($columnName, ['status'])) {
            return $this->randomElement($this->accountStatuses);
        } elseif ($this->matchesPattern($columnName, ['updated_at'])) {
            return date('Y-m-d H:i:s');
        } else {
            return $this->generateByType($columnType, $columnName, false);
        }
    }
    
    /**
     * Get primary key column name
     */
    private function getPrimaryKey($tableName)
    {
        // Table name already validated in getTableColumns
        $stmt = $this->pdo->query("SHOW KEYS FROM `$tableName` WHERE Key_name = 'PRIMARY'");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['Column_name'] : null;
    }
    
    /**
     * Helper methods for data generation
     */
    private function matchesPattern($str, $patterns)
    {
        $str = strtolower($str);
        foreach ($patterns as $pattern) {
            if (strpos($str, strtolower($pattern)) !== false) {
                return true;
            }
        }
        return false;
    }
    
    private function randomElement($array)
    {
        return $array[array_rand($array)];
    }
    
    private function randomSubset($array, $count)
    {
        shuffle($array);
        return array_slice($array, 0, $count);
    }
    
    private function generateEmail()
    {
        $firstName = strtolower($this->randomElement($this->firstNames));
        $lastName = strtolower($this->randomElement($this->lastNames));
        $domains = ['example.com', 'email.com', 'mail.com', 'test.com'];
        return $firstName . '.' . $lastName . rand(1, 999) . '@' . $this->randomElement($domains);
    }
    
    private function generatePhone()
    {
        return sprintf('(%03d) %03d-%04d', rand(100, 999), rand(100, 999), rand(1000, 9999));
    }
    
    private function generateAddress()
    {
        $number = rand(100, 9999);
        $street = $this->randomElement($this->streetNames);
        $type = $this->randomElement($this->streetTypes);
        $city = $this->randomElement($this->cities);
        $state = $this->randomElement($this->states);
        $zip = rand(10000, 99999);
        
        return "$number $street $type, $city, $state $zip";
    }
    
    private function generateAccountNumber($tableName)
    {
        $prefix = strtoupper(substr($tableName, 0, 3));
        return $prefix . '-' . rand(1000, 9999) . rand(10, 99);
    }
    
    private function generateDescription($transactionType)
    {
        if (isset($this->descriptions[$transactionType])) {
            return $this->randomElement($this->descriptions[$transactionType]);
        }
        return $this->generateRandomText(10, 50);
    }
    
    private function generateDecimal($min, $max)
    {
        return round($min + (mt_rand() / mt_getrandmax()) * ($max - $min), 2);
    }
    
    private function generateRandomText($minLength, $maxLength)
    {
        $words = ['Updated', 'Modified', 'Changed', 'Revised', 'Adjusted', 'New', 'Current', 'Latest'];
        $text = '';
        $targetLength = rand($minLength, $maxLength);
        
        while (strlen($text) < $targetLength) {
            $text .= $this->randomElement($words) . ' ';
        }
        
        return trim(substr($text, 0, $targetLength));
    }
    
    private function generateByType($columnType, $columnName, $isNullable)
    {
        // Handle NULL
        if ($isNullable && rand(1, 10) > 8) {
            return null;
        }
        
        // Parse type
        if (strpos($columnType, 'int') !== false) {
            return rand(1, 10000);
        } elseif (strpos($columnType, 'decimal') !== false || strpos($columnType, 'float') !== false) {
            return $this->generateDecimal(1, 1000);
        } elseif (strpos($columnType, 'varchar') !== false || strpos($columnType, 'text') !== false) {
            preg_match('/\((\d+)\)/', $columnType, $matches);
            $maxLength = isset($matches[1]) ? min((int)$matches[1], 100) : 50;
            return $this->generateRandomText(5, $maxLength);
        } elseif (strpos($columnType, 'date') !== false || strpos($columnType, 'timestamp') !== false) {
            return date('Y-m-d H:i:s', strtotime('-' . rand(0, 365) . ' days'));
        } elseif (strpos($columnType, 'enum') !== false) {
            // Extract enum values
            preg_match("/enum\('(.+?)'\)/", $columnType, $matches);
            if (isset($matches[1])) {
                $values = explode("','", $matches[1]);
                return $this->randomElement($values);
            }
            return 'active';
        }
        
        return 'value-' . rand(1, 100);
    }
}
