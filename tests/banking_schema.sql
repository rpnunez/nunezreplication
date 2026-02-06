-- Banking Application Schema
-- This schema represents a simple banking application with customers, accounts, and transactions

-- Customers table
CREATE TABLE IF NOT EXISTS customers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    phone VARCHAR(20),
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Accounts table
CREATE TABLE IF NOT EXISTS accounts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    account_number VARCHAR(20) NOT NULL UNIQUE,
    account_type ENUM('checking', 'savings', 'business') NOT NULL,
    balance DECIMAL(15, 2) DEFAULT 0.00,
    status ENUM('active', 'inactive', 'closed') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transactions table
CREATE TABLE IF NOT EXISTS transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    account_id INT NOT NULL,
    transaction_type ENUM('deposit', 'withdrawal', 'transfer') NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    description VARCHAR(255),
    reference_number VARCHAR(50) UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reset sample data to make this script idempotent
SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE transactions;
TRUNCATE TABLE accounts;
TRUNCATE TABLE customers;

SET FOREIGN_KEY_CHECKS = 1;

-- Insert sample data
INSERT INTO customers (first_name, last_name, email, phone, address) VALUES
('John', 'Doe', 'john.doe@example.com', '555-0101', '123 Main St, New York, NY 10001'),
('Jane', 'Smith', 'jane.smith@example.com', '555-0102', '456 Oak Ave, Los Angeles, CA 90001'),
('Robert', 'Johnson', 'robert.j@example.com', '555-0103', '789 Pine Rd, Chicago, IL 60601'),
('Maria', 'Garcia', 'maria.g@example.com', '555-0104', '321 Elm St, Houston, TX 77001'),
('Michael', 'Brown', 'michael.b@example.com', '555-0105', '654 Maple Dr, Phoenix, AZ 85001');

INSERT INTO accounts (customer_id, account_number, account_type, balance, status) VALUES
(1, 'CHK-1001', 'checking', 5000.00, 'active'),
(1, 'SAV-1001', 'savings', 15000.00, 'active'),
(2, 'CHK-2001', 'checking', 3500.50, 'active'),
(2, 'BUS-2001', 'business', 25000.00, 'active'),
(3, 'CHK-3001', 'checking', 8750.25, 'active'),
(3, 'SAV-3001', 'savings', 45000.00, 'active'),
(4, 'CHK-4001', 'checking', 2100.75, 'active'),
(5, 'SAV-5001', 'savings', 10500.00, 'active');

INSERT INTO transactions (account_id, transaction_type, amount, description, reference_number) VALUES
(1, 'deposit', 1000.00, 'Salary deposit', 'TXN-001'),
(1, 'withdrawal', 200.00, 'ATM withdrawal', 'TXN-002'),
(2, 'deposit', 5000.00, 'Initial deposit', 'TXN-003'),
(3, 'deposit', 500.50, 'Cash deposit', 'TXN-004'),
(3, 'withdrawal', 100.00, 'Bill payment', 'TXN-005'),
(4, 'deposit', 10000.00, 'Business revenue', 'TXN-006'),
(4, 'transfer', 2000.00, 'Transfer to checking', 'TXN-007'),
(5, 'deposit', 2500.25, 'Paycheck', 'TXN-008'),
(6, 'deposit', 15000.00, 'Investment return', 'TXN-009'),
(7, 'withdrawal', 500.00, 'Grocery shopping', 'TXN-010');
