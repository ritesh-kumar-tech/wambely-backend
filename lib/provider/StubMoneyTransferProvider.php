<?php

namespace Provider;

/**
 * Deterministic, local-only stand-in for Nium — lets the whole app (KYC,
 * wallet, beneficiaries, quotes, transfers) be exercised end-to-end without
 * real Nium sandbox credentials. Clearly a dev/test aid, never meant to run
 * in production: fixed exchange rates, instantly-settling payouts, and
 * plain local bookkeeping instead of real money movement.
 *
 * Owns `users.nium_customer_hash_id` / `users.nium_compliance_status` and
 * `wallets` (including its stub-only `balance` column) directly — see the
 * MoneyTransferProvider interface doc for why that split exists.
 */
final class StubMoneyTransferProvider implements MoneyTransferProvider {
    /**
     * USD-based fixed rates for testing every corridor the app's country
     * picker allows — not real market rates, just plausible fixed values so
     * quotes/transfers work end-to-end for any destination during testing.
     */
    private const RATES = [
        'USD:INR' => 83.50,
        'USD:NGN' => 1530.00,
        'USD:PHP' => 56.20,
        'USD:MXN' => 18.30,
        'USD:KES' => 129.00,
        'USD:GHS' => 15.40,
        'USD:GBP' => 0.79,
        'USD:EUR' => 0.92,
        'USD:CNY' => 7.18,
        'USD:PKR' => 278.00,
        'USD:BDT' => 119.50,
        'USD:VND' => 25400.00,
        'USD:ZAR' => 18.10,
        'USD:EGP' => 48.50,
        'USD:BRL' => 5.40,
        'USD:CAD' => 1.37,
        'USD:AUD' => 1.52,
        'USD:AED' => 3.67,
        'USD:SGD' => 1.34,
        'USD:JPY' => 149.50,
    ];

    private const FEE_FLAT = 2.99;
    private const FEE_PERCENT = 0.005; // 0.5%

    public function createCustomer(string $userId, array $profile): array {
        $customerRef = 'stub_cust_' . bin2hex(random_bytes(8));
        \db()->prepare('UPDATE users SET nium_customer_hash_id = ?, nium_compliance_status = ? WHERE id = ?')
            ->execute([$customerRef, 'INITIATED', $userId]);
        return ['customerRef' => $customerRef, 'status' => 'INITIATED'];
    }

    public function submitKycDocument(string $userId, string $customerRef, string $documentType): array {
        $current = $this->getCustomerStatus($customerRef)['status'];
        // A selfie already submitted (status was bumped to IN PROGRESS by
        // submitKycSelfie first) + a document now -> both pieces are in,
        // auto-approve. Otherwise this is the first piece submitted.
        $next = $current === 'IN PROGRESS' ? 'COMPLETED' : 'IN PROGRESS';
        \db()->prepare('UPDATE users SET nium_compliance_status = ? WHERE id = ?')->execute([$next, $userId]);
        return ['status' => $next];
    }

    public function submitKycSelfie(string $userId, string $customerRef): array {
        // Same auto-approve-on-second-piece rule as submitKycDocument.
        return $this->submitKycDocument($userId, $customerRef, 'selfie');
    }

    public function getCustomerStatus(string $customerRef): array {
        $stmt = \db()->prepare('SELECT nium_compliance_status FROM users WHERE nium_customer_hash_id = ?');
        $stmt->execute([$customerRef]);
        $status = $stmt->fetchColumn();
        return ['status' => $status !== false && $status !== null ? $status : 'INITIATED'];
    }

    public function ensureWallet(string $customerRef, string $currencyCode): array {
        $userId = $this->userIdFor($customerRef);
        $stmt = \db()->prepare('SELECT nium_wallet_hash_id FROM wallets WHERE user_id = ? AND currency_code = ?');
        $stmt->execute([$userId, $currencyCode]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            return ['walletRef' => $existing];
        }

        $walletRef = 'stub_wal_' . bin2hex(random_bytes(8));
        // New stub wallets start funded so a fresh test account can send
        // money immediately without a separate top-up step.
        $startingBalance = $currencyCode === 'USD' ? 5000.00 : 0.00;
        \db()->prepare('INSERT INTO wallets (id, user_id, nium_wallet_hash_id, currency_code, balance) VALUES (?, ?, ?, ?, ?)')
            ->execute([\new_id('wal'), $userId, $walletRef, $currencyCode, $startingBalance]);
        return ['walletRef' => $walletRef];
    }

    public function getWallets(string $customerRef): array {
        $userId = $this->userIdFor($customerRef);
        $stmt = \db()->prepare('SELECT nium_wallet_hash_id, currency_code, balance FROM wallets WHERE user_id = ? ORDER BY currency_code');
        $stmt->execute([$userId]);
        return array_map(fn(array $r) => [
            'walletRef' => $r['nium_wallet_hash_id'],
            'currencyCode' => $r['currency_code'],
            'balance' => (float) $r['balance'],
        ], $stmt->fetchAll());
    }

    public function fundWallet(string $walletRef, float $amount): array {
        \db()->prepare('UPDATE wallets SET balance = balance + ? WHERE nium_wallet_hash_id = ?')->execute([$amount, $walletRef]);
        return ['balance' => $this->balanceOf($walletRef)];
    }

    public function createBeneficiary(string $customerRef, array $payload): array {
        return [
            'beneficiaryRef' => 'stub_ben_' . bin2hex(random_bytes(8)),
            'payoutRef' => 'stub_payoutmethod_' . bin2hex(random_bytes(8)),
        ];
    }

    public function createQuote(string $sourceCurrency, string $destinationCurrency, float $sourceAmount): array {
        $pair = "$sourceCurrency:$destinationCurrency";
        if (!isset(self::RATES[$pair])) {
            throw new UnsupportedCorridorException("No stub exchange rate configured for $pair.");
        }
        $rate = self::RATES[$pair];
        $fee = round($sourceAmount * self::FEE_PERCENT + self::FEE_FLAT, 2);
        return [
            'exchangeRate' => $rate,
            'fee' => $fee,
            'destinationAmount' => round($sourceAmount * $rate, 2),
            'expiresInSeconds' => 600,
            'deliveryEstimate' => '1-2 business days',
        ];
    }

    public function createPayout(array $payload): array {
        $walletRef = $payload['sourceWalletRef'];
        $balance = $this->balanceOf($walletRef);
        if ($balance < $payload['totalDebit']) {
            throw new InsufficientFundsException('Insufficient wallet balance for this transfer.');
        }
        \db()->prepare('UPDATE wallets SET balance = balance - ? WHERE nium_wallet_hash_id = ?')
            ->execute([$payload['totalDebit'], $walletRef]);

        // Real payouts are async (pending/processing -> completed via
        // webhook or reconciliation); the stub settles instantly so the
        // full app flow can be demoed without a queue/cron in place.
        return ['payoutRef' => 'stub_payout_' . bin2hex(random_bytes(8)), 'status' => 'completed'];
    }

    public function getPayoutStatus(string $payoutRef): array {
        return ['status' => 'completed'];
    }

    private function userIdFor(string $customerRef): string {
        $stmt = \db()->prepare('SELECT id FROM users WHERE nium_customer_hash_id = ?');
        $stmt->execute([$customerRef]);
        $userId = $stmt->fetchColumn();
        if ($userId === false) {
            throw new \RuntimeException("No local user found for stub customerRef $customerRef.");
        }
        return $userId;
    }

    private function balanceOf(string $walletRef): float {
        $stmt = \db()->prepare('SELECT balance FROM wallets WHERE nium_wallet_hash_id = ?');
        $stmt->execute([$walletRef]);
        $balance = $stmt->fetchColumn();
        if ($balance === false) {
            throw new \RuntimeException("No stub wallet found for walletRef $walletRef.");
        }
        return (float) $balance;
    }
}
