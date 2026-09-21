<?php
require __DIR__ . '/includes/guard.php';

$id = (string) ($_GET['id'] ?? '');
$stmt = db()->prepare(
    'SELECT t.*, u.full_name, u.email, u.phone
     FROM transactions t
     JOIN users u ON u.id = t.user_id
     WHERE t.id = ?'
);
$stmt->execute([$id]);
$txn = $stmt->fetch();

if (!$txn) {
    http_response_code(404);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Wambely Admin — Transaction</title>
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
    <p><a href="index.php">&laquo; Back to all transactions</a></p>

    <?php if (!$txn): ?>
      <h2>Transaction not found</h2>
    <?php else: ?>
      <h2>Transaction <?= htmlspecialchars($txn['id']) ?></h2>

      <section class="detail-grid">
        <div class="detail-card">
          <h3>User</h3>
          <dl>
            <dt>Name</dt><dd><?= htmlspecialchars($txn['full_name']) ?></dd>
            <dt>Email</dt><dd><?= htmlspecialchars($txn['email']) ?></dd>
            <dt>Phone</dt><dd><?= htmlspecialchars($txn['phone'] ?? '—') ?></dd>
          </dl>
        </div>

        <div class="detail-card">
          <h3>Transaction</h3>
          <dl>
            <dt>Type</dt><dd><?= htmlspecialchars(ucfirst($txn['type'])) ?></dd>
            <dt>Status</dt><dd><span class="badge badge-<?= htmlspecialchars($txn['status']) ?>"><?= htmlspecialchars(ucfirst($txn['status'])) ?></span></dd>
            <dt>Amount</dt><dd><?= number_format((float) $txn['amount'], 2) ?> <?= htmlspecialchars($txn['currency_code']) ?></dd>
            <dt>Fee</dt><dd><?= number_format((float) $txn['fee'], 2) ?></dd>
            <dt>Exchange rate</dt><dd><?= $txn['exchange_rate'] !== null ? number_format((float) $txn['exchange_rate'], 4) : '—' ?></dd>
            <dt>Recipient</dt><dd><?= htmlspecialchars($txn['recipient_name'] ?? '—') ?></dd>
            <dt>Delivery estimate</dt><dd><?= htmlspecialchars($txn['delivery_estimate'] ?? '—') ?></dd>
            <dt>Reference</dt><dd><?= htmlspecialchars($txn['reference'] ?? '—') ?></dd>
            <dt>Payment status</dt><dd><?= htmlspecialchars($txn['payment_status'] ?? '—') ?></dd>
            <dt>Compliance status</dt><dd><?= htmlspecialchars($txn['compliance_status'] ?? '—') ?></dd>
            <dt>Created</dt><dd><?= htmlspecialchars($txn['created_at']) ?></dd>
          </dl>
        </div>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
