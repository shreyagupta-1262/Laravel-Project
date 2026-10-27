<?php
require_once "db.php";

function ask($message) {
    echo $message;
    return trim(fgets(STDIN));
}
function getCustomer($conn, $account) {
    $stmt = $conn->prepare(
        "SELECT * FROM customers WHERE account_number = ?"
    );

    $stmt->bind_param("s", $account);
    $stmt->execute();

    $result = $stmt->get_result();
    $customer = $result->fetch_assoc();

    $stmt->close();
    return $customer;
}

// Display customer information
function showCustomer($customer) {
    echo "\n========== CUSTOMER DETAILS ==========\n";
    echo "Customer ID    : " . $customer["customer_id"] . "\n";
    echo "Account Number : " . $customer["account_number"] . "\n";
    echo "Full Name      : " . $customer["full_name"] . "\n";
    echo "Email          : " . ($customer["email"] ?? "") . "\n";
    echo "Phone          : " . ($customer["phone"] ?? "") . "\n";
    echo "Balance        : Rs. " .
        number_format((float)$customer["balance"], 2) . "\n";
    echo "Created At     : " . $customer["created_at"] . "\n";
    echo "======================================\n";
}

//valid amount
function validAmount($amount) {
    return preg_match('/^\d+(\.\d{1,2})?$/', $amount)
        && (float)$amount > 0
        && (float)$amount <= 9999999999.99;
}

// Register a new customer
function addCustomer($conn) {
    $account = ask("Enter account number: ");
    $name = ask("Enter full name: ");
    $email = ask("Enter email: ");
    $phone = ask("Enter phone number: ");
    $initial = ask("Enter opening balance (default 0): ");

    if ($account === "" || $name === "") {
        echo "Please enter both an account number and a name.\n";
        return;
    }

    if ($initial === "") {
        $initial = "0";
    }

    if (!preg_match('/^\d+(\.\d{1,2})?$/', $initial)
        || (float)$initial < 0
        || (float)$initial > 9999999999.99) {
        echo "Please enter a valid opening balance.\n";
        return;
    }

    try {
        $balance = (float)$initial;

        $stmt = $conn->prepare(
            "INSERT INTO customers
            (account_number, full_name, email, phone, balance)
            VALUES (?, ?, ?, ?, ?)"
        );

        $stmt->bind_param(
            "ssssd",
            $account,
            $name,
            $email,
            $phone,
            $balance
        );

        $stmt->execute();
        $stmt->close();

        echo "Great! The customer has been added successfully.\n";

    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() == 1062) {
            echo "This account number already exists. Please use another.\n";
        } else {
            echo "Sorry, the customer could not be saved.\n";
            echo "Database error: " . $e->getMessage() . "\n";
        }
    }
}

function showAllCustomers($conn) {
    $result = $conn->query(
        "SELECT * FROM customers ORDER BY customer_id"
    );

    if ($result->num_rows === 0) {
        echo "No customers have been registered yet.\n";
        return;
    }

    while ($customer = $result->fetch_assoc()) {
        showCustomer($customer);
    }

    echo "All customer records displayed successfully.\n";
}

function showSpecificCustomer($conn) {
    $account = ask("Enter the account number to search: ");
    $customer = getCustomer($conn, $account);

    if ($customer) {
        showCustomer($customer);
    } else {
        echo "Sorry, no customer was found with that account number.\n";
    }
}
function updateCustomer($conn) {
    $account = ask("Enter the account number to update: ");
    $customer = getCustomer($conn, $account);

    if (!$customer) {
        echo "Customer not found. Please check the account number.\n";
        return;
    }

    echo "Press Enter if you want to keep the existing information.\n";

    $name = ask("Full name [" . $customer["full_name"] . "]: ");
    $email = ask("Email [" . ($customer["email"] ?? "") . "]: ");
    $phone = ask("Phone [" . ($customer["phone"] ?? "") . "]: ");

    if ($name === "") {
        $name = $customer["full_name"];
    }

    if ($email === "") {
        $email = $customer["email"] ?? "";
    }

    if ($phone === "") {
        $phone = $customer["phone"] ?? "";
    }

    $stmt = $conn->prepare(
        "UPDATE customers
         SET full_name = ?, email = ?, phone = ?
         WHERE account_number = ?"
    );

    $stmt->bind_param("ssss", $name, $email, $phone, $account);
    $stmt->execute();
    $stmt->close();

    echo "Customer information updated successfully.\n";
}

// Delete a customer after confirmation.
function deleteCustomer($conn) {
    $account = ask("Enter the account number to delete: ");
    $customer = getCustomer($conn, $account);

    if (!$customer) {
        echo "Customer not found.\n";
        return;
    }

    showCustomer($customer);
    $confirm = ask("Type YES to confirm deletion: ");

    if ($confirm !== "YES") {
        echo "No changes made. Customer deletion cancelled.\n";
        return;
    }

    $stmt = $conn->prepare(
        "DELETE FROM customers WHERE account_number = ?"
    );

    $stmt->bind_param("s", $account);
    $stmt->execute();
    $stmt->close();

    echo "Customer record deleted successfully.\n";
}
function transferOperation($conn, $type) {
    $account = ask("Enter the account number: ");
    $amountText = ask("Enter the amount: ");

    if (!validAmount($amountText)) {
        echo "Please enter a positive amount with up to 2 decimal places.\n";
        return;
    }

    $amount = (float)$amountText;

    try {
        // Start a transaction to protect the balance update.
        $conn->begin_transaction();

        $stmt = $conn->prepare(
            "SELECT balance FROM customers
             WHERE account_number = ? FOR UPDATE"
        );

        $stmt->bind_param("s", $account);
        $stmt->execute();

        $result = $stmt->get_result();
        $customer = $result->fetch_assoc();
        $stmt->close();

        if (!$customer) {
            $conn->rollback();
            echo "Customer not found. Please check the account number.\n";
            return;
        }

        $balance = (float)$customer["balance"];

        if ($type === "debit" && $amount > $balance) {
            $conn->rollback();

            echo "Insufficient balance. You currently have Rs. " .
                number_format($balance, 2) . "\n";

            return;
        }

        if ($type === "credit"
            && $balance + $amount > 9999999999.99) {
            $conn->rollback();
            echo "This transaction exceeds the permitted balance limit.\n";
            return;
        }

        if ($type === "credit") {
            $stmt = $conn->prepare(
                "UPDATE customers
                 SET balance = balance + ?
                 WHERE account_number = ?"
            );
        } else {
            $stmt = $conn->prepare(
                "UPDATE customers
                 SET balance = balance - ?
                 WHERE account_number = ?"
            );
        }

        $stmt->bind_param("ds", $amount, $account);
        $stmt->execute();
        $stmt->close();

        $conn->commit();

        $updated = getCustomer($conn, $account);

        echo ucfirst($type) . " completed successfully!\n";
        echo "Your updated balance is Rs. " .
            number_format((float)$updated["balance"], 2) . "\n";

    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
            // The transaction may already have been rolled back.
        }

        echo "Sorry, the transaction could not be completed.\n";
        echo "Please check the database configuration and try again.\n";
    }
}

// Main menu
while (true) {
    echo "           HORIZON BANK SYSTEM\n";
    echo "1. Add Customer\n";
    echo "2. Display All Customers\n";
    echo "3. Display Specific Customer\n";
    echo "4. Update Customer Details\n";
    echo "5. Delete Customer\n";
    echo "6. Credit Amount\n";
    echo "7. Debit Amount\n";
    echo "8. Exit\n";
    echo "========================================\n";

    $choice = ask("Enter your choice: ");

    try {
        switch ($choice) {
            case "1":
                addCustomer($conn);
                break;

            case "2":
                showAllCustomers($conn);
                break;

            case "3":
                showSpecificCustomer($conn);
                break;

            case "4":
                updateCustomer($conn);
                break;

            case "5":
                deleteCustomer($conn);
                break;

            case "6":
                transferOperation($conn, "credit");
                break;

            case "7":
                transferOperation($conn, "debit");
                break;

            case "8":
                echo "Thank you for choosing Horizon Bank. Goodbye!\n";
                $conn->close();
                exit;

            default:
                echo "Please choose a valid option from 1 to 8.\n";
        }
    } catch (Throwable $e) {
        echo "Something went wrong: " . $e->getMessage() . "\n";
    }
}
?>
