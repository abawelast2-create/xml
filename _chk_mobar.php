<?php
require __DIR__ . '/includes/db.php';
echo "=== الفروع ===\n";
foreach(db()->query("SELECT id, name, is_active FROM branches ORDER BY id") as $b)
    echo $b['id'].' | '.$b['name'].' | '.($b['is_active']?'نشط':'معطل')."\n";
echo "\n=== الموظفين حسب الفرع ===\n";
$rows = db()->query("SELECT b.id as bid, b.name as bname, COUNT(e.id) as cnt FROM branches b LEFT JOIN employees e ON e.branch_id=b.id AND e.is_active=1 AND e.deleted_at IS NULL GROUP BY b.id ORDER BY b.id")->fetchAll();
foreach($rows as $r) echo $r['bid'].' | '.$r['bname'].' → '.$r['cnt']." موظف\n";
$total = db()->query("SELECT COUNT(*) FROM employees WHERE is_active=1 AND deleted_at IS NULL")->fetchColumn();
echo "\nإجمالي الموظفين النشطين: $total\n";
echo "\n=== جداول النظام ===\n";
$tables = db()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as $t) {
    $cnt = db()->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    echo "$t → $cnt\n";
}
@unlink(__FILE__);
