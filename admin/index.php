<?php
require __DIR__ . '/includes/guard.php';

$q = trim((string) ($_GET['q'] ?? ''));
$type = (string) ($_GET['type'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$from = (string) ($_GET['from'] ?? '');
$to = (string) ($_GET['to'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$validTypes = ['sent', 'received', 'deposit', 'withdrawal'];
$validStatuses = ['pending', 'processing', 'completed', 'failed', 'cancelled'];

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(u.full_name LIKE ? OR u.email LIKE ?)';
    $params[] = "%$q%";
    $params[] = "%$q%";
}
if (in_array($type, $validTypes, true)) {
    $where[] = 't.type = ?';
    $params[] = $type;
}
if (in_array($status, $validStatuses, true)) {
    $where[] = 't.status = ?';
    $params[] = $status;
}
if ($from !== '') {
    $where[] = 't.created_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where[] = 't.created_at <= ?';
    $params[] = $to . ' 23:59:59';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = db()->prepare("SELECT COUNT(*) AS c FROM transactions t JOIN users u ON u.id = t.user_id $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetch()['c'];
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = db()->prepare(
    "SELECT t.*, u.full_name, u.email
     FROM transactions t
     JOIN users u ON u.id = t.user_id
     $whereSql
     ORDER BY t.created_at DESC
     LIMIT $perPage OFFSET $offset"
);
$listStmt->execute($params);
$rows = $listStmt->fetchAll();

$summaryStmt = db()->prepare(
    "SELECT t.status, COUNT(*) AS c, COALESCE(SUM(t.amount), 0) AS total_amount
     FROM transactions t
     JOIN users u ON u.id = t.user_id
     $whereSql
     GROUP BY t.status"
);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetchAll();

function qs(array $overrides): string {
    $params = array_merge($_GET, $overrides);
    return htmlspecialchars('?' . http_build_query($params));
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Wambely Admin — Transactions</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <header class="topbar">
    <h1>Wambely Admin</h1>
    <div class="topbar-right">
      <span>Signed in as <?= htmlspecialchars($_SESSION['admin_username']) ?></span>
      <a href="logout.php">Sign out</a>
    </div>
  </header>

  <main>
    <h2>All-user transaction history</h2>

    <section class="summary-cards">
      <?php foreach ($summary as $s): ?>
        <div class="summary-card">
          <span class="summary-count"><?= (int) $s['c'] ?></span>
          <span class="summary-label"><?= htmlspecialchars(ucfirst($s['status'])) ?></span>
          <span class="summary-amount">$<?= number_format((float) $s['total_amount'], 2) ?></span>
        </div>
      <?php endforeach; ?>
      <div class="summary-card summary-card-total">
        <span class="summary-count"><?= $total ?></span>
        <span class="summary-label">Total matching transactions</span>
      </div>
    </section>

    <form class="filters" method="get">
      <input type="text" name="q" placeholder="Search by user name or email" value="<?= htmlspecialchars($q) ?>">
      <select name="type">
        <option value="">All types</option>
        <?php foreach ($validTypes as $t): ?>
          <option value="<?= $t ?>" <?= $type === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="status">
        <option value="">All statuses</option>
        <?php foreach ($validStatuses as $s): ?>
          <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
      <label>From <input type="date" name="from" value="<?= htmlspecialchars($from) ?>"></label>
      <label>To <input type="date" name="to" value="<?= htmlspecialchars($to) ?>"></label>
      <button type="submit">Filter</button>
      <?php if ($q || $type || $status || $from || $to): ?>
        <a class="clear-link" href="index.php">Clear</a>
      <?php endif; ?>
    </form>

    <table class="txn-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>User</th>
          <th>Type</th>
          <th>Status</th>
          <th>Amount</th>
          <th>Fee</th>
          <th>Recipient</th>
          <th>Reference</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="empty">No transactions match these filters.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><a href="transaction.php?id=<?= urlencode($r['id']) ?>"><?= htmlspecialchars($r['created_at']) ?></a></td>
            <td>
              <div class="user-name"><?= htmlspecialchars($r['full_name']) ?></div>
              <div class="user-email"><?= htmlspecialchars($r['email']) ?></div>
            </td>
            <td><?= htmlspecialchars(ucfirst($r['type'])) ?></td>
            <td><span class="badge badge-<?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars(ucfirst($r['status'])) ?></span></td>
            <td><?= number_format((float) $r['amount'], 2) ?> <?= htmlspecialchars($r['currency_code']) ?></td>
            <td><?= number_format((float) $r['fee'], 2) ?></td>
            <td><?= htmlspecialchars($r['recipient_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($r['reference'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
      <nav class="pagination">
        <?php if ($page > 1): ?><a href="<?= qs(['page' => $page - 1]) ?>">&laquo; Prev</a><?php endif; ?>
        <span>Page <?= $page ?> of <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?><a href="<?= qs(['page' => $page + 1]) ?>">Next &raquo;</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  </main>
</body>
</html>
