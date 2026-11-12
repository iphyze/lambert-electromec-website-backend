<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/connection.php';

function cliPrompt(string $label): string
{
    fwrite(STDOUT, $label);
    return trim((string) fgets(STDIN));
}

function cliPasswordPrompt(string $label): string
{
    fwrite(STDOUT, $label);

    $hidden = false;
    if (DIRECTORY_SEPARATOR !== '\\' && function_exists('shell_exec')) {
        @shell_exec('stty -echo');
        $hidden = true;
    }

    $value = rtrim((string) fgets(STDIN), "\r\n");

    if ($hidden) {
        @shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }

    return $value;
}

try {
    $firstName = cliPrompt('First name: ');
    $lastName = cliPrompt('Last name: ');
    $email = strtolower(cliPrompt('Email: '));
    $role = strtolower(cliPrompt('Role [super_admin/admin/editor] (default super_admin): '));
    $role = $role === '' ? 'super_admin' : $role;
    $password = cliPasswordPrompt('Password: ');

    if ($firstName === '' || strlen($firstName) > 80 || $lastName === '' || strlen($lastName) > 80) {
        throw new RuntimeException('First and last name are required and must be 80 characters or fewer.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        throw new RuntimeException('Enter a valid email address.');
    }
    if (!in_array($role, ['super_admin', 'admin', 'editor'], true)) {
        throw new RuntimeException('Invalid role.');
    }
    if (strlen($password) < 12 || strlen($password) > 255) {
        throw new RuntimeException('Password must be between 12 and 255 characters.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare(
        'INSERT INTO admin_users (first_name, last_name, email, password_hash, role, status)
         VALUES (?, ?, ?, ?, ?, "active")'
    );
    $stmt->bind_param('sssss', $firstName, $lastName, $email, $hash, $role);
    $stmt->execute();
    $adminId = $stmt->insert_id;
    $stmt->close();

    fwrite(STDOUT, "Administrator created successfully with ID {$adminId}." . PHP_EOL);
} catch (mysqli_sql_exception $error) {
    if ((int) $error->getCode() === 1062) {
        fwrite(STDERR, 'An administrator with that email already exists.' . PHP_EOL);
        exit(1);
    }

    error_log('Lambert create admin database error: ' . $error->getMessage());
    fwrite(STDERR, 'Unable to create administrator.' . PHP_EOL);
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
