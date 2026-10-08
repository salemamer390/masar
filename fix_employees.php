<?php

$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=tazamun_hr;charset=utf8mb4',
    'amer',
    'aaaaaa',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

echo "===== TAZAMUN EMPLOYEE FIX =====" . PHP_EOL;

// 1) Delete old duplicate employee
$stmt = $pdo->prepare(
    "DELETE FROM employees WHERE employee_code = :code"
);

$stmt->execute([
    ':code' => 'EMP001'
]);

echo "DELETED EMP001: " . $stmt->rowCount() . PHP_EOL;


// 2) Find text columns in employees
$stmt = $pdo->query("
    SELECT COLUMN_NAME, DATA_TYPE
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'employees'
      AND DATA_TYPE IN ('varchar', 'text', 'mediumtext', 'longtext')
");

$columns = $stmt->fetchAll();


// 3) Fix only values containing Mojibake markers
foreach ($columns as $column) {

    $name = $column['COLUMN_NAME'];

    $sql = "
        UPDATE employees
        SET `$name` =
            CONVERT(
                BINARY CONVERT(`$name` USING latin1)
                USING utf8mb4
            )
        WHERE `$name` IS NOT NULL
          AND (
              `$name` LIKE '%Ø%'
              OR `$name` LIKE '%Ù%'
          )
    ";

    try {

        $affected = $pdo->exec($sql);

        if ($affected > 0) {
            echo "FIXED: {$name} = {$affected}" . PHP_EOL;
        }

    } catch (Throwable $e) {

        echo "SKIPPED: {$name}" . PHP_EOL;
    }
}


echo PHP_EOL;
echo "===== FINAL EMPLOYEES =====" . PHP_EOL;

$stmt = $pdo->query("
    SELECT id, employee_code, full_name
    FROM employees
    ORDER BY id
");

foreach ($stmt as $employee) {

    echo
        $employee['id']
        . " | "
        . $employee['employee_code']
        . " | "
        . $employee['full_name']
        . PHP_EOL;
}

echo PHP_EOL;
echo "===== DONE =====" . PHP_EOL;