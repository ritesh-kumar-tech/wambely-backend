<?php
// CLI-only safety net for missed webhooks: finds transactions stuck in
// pending/processing past a threshold and asks the provider for their real
// status. Meant to run every 5-10 minutes via Windows Task Scheduler
// (`php reconcile.php`) — there's no queue/cron runtime in this raw-PHP
// project, so a scheduled script is the equivalent.
//
// Under the stub provider this rarely finds anything (stub payouts settle
// immediately); it matters once MONEY_TRANSFER_PROVIDER=nium, where real
// payouts are genuinely async and a webhook can be missed or delayed.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require __DIR__ . '/config.php';

const STALE_AFTER_MINUTES = 10;

$stmt = db()->prepare(
    "SELECT * FROM transactions
     WHERE status IN ('pending', 'processing')
       AND nium_transaction_id IS NOT NULL
       AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)"
);
$stmt->execute([STALE_AFTER_MINUTES]);
$stale = $stmt->fetchAll();

if (!$stale) {
    echo "No stale transactions to reconcile.\n";
    exit(0);
}

$provider = \Provider\money_transfer_provider();
foreach ($stale as $txn) {
    try {
        $result = $provider->getPayoutStatus($txn['nium_transaction_id']);
        $newStatus = match ($result['status']) {
            'completed' => 'completed',
            'failed', 'rejected', 'returned' => 'failed',
            'cancelled' => 'cancelled',
            default => $txn['status'],
        };
        if ($newStatus !== $txn['status']) {
            db()->prepare('UPDATE transactions SET status = ? WHERE id = ?')->execute([$newStatus, $txn['id']]);
            echo "Updated {$txn['id']}: {$txn['status']} -> $newStatus\n";
        } else {
            echo "No change for {$txn['id']} (still {$txn['status']})\n";
        }
    } catch (\Throwable $e) {
        echo "Failed to reconcile {$txn['id']}: {$e->getMessage()}\n";
    }
}
