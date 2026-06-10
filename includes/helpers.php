<?php
/**
 * Shared helpers for the Credit Health Management System.
 * Included by auth_check.php — do not call session_start() here.
 */

// ── Output escaping ──────────────────────────────────────────────────────────
function h($s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

// ── PDO query helpers ────────────────────────────────────────────────────────
function fetchOne(PDO $pdo, string $sql, array $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

function fetchAll(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function fetchValue(PDO $pdo, string $sql, array $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_NUM);
    return $row ? $row[0] : null;
}

// ── Activity log ─────────────────────────────────────────────────────────────
function logActivity(PDO $pdo, int $userId, string $action, string $description): void {
    try {
        $pdo->prepare("INSERT INTO activity_log (user_id, action, description) VALUES (?, ?, ?)")
            ->execute([$userId, $action, $description]);
    } catch (PDOException $e) {
        // Non-fatal — silently ignore logging failures
    }
}

// ── CSRF helpers ─────────────────────────────────────────────────────────────
function csrfToken(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrfVerify(string $token): bool {
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

// ── CIBIL score band helper ──────────────────────────────────────────────────
function scoreBand(int $score): array {
    if ($score >= 800) return ['label' => 'Excellent', 'color' => 'blue',   'tailwind' => 'text-blue-600',   'bg' => 'bg-blue-100'];
    if ($score >= 740) return ['label' => 'Very Good', 'color' => 'green',  'tailwind' => 'text-green-600',  'bg' => 'bg-green-100'];
    if ($score >= 670) return ['label' => 'Good',      'color' => 'yellow', 'tailwind' => 'text-yellow-600', 'bg' => 'bg-yellow-100'];
    if ($score >= 580) return ['label' => 'Fair',      'color' => 'orange', 'tailwind' => 'text-orange-600', 'bg' => 'bg-orange-100'];
    return                     ['label' => 'Poor',      'color' => 'red',    'tailwind' => 'text-red-600',    'bg' => 'bg-red-100'];
}

// ── Number formatting helpers ────────────────────────────────────────────────
function inr(float $amount): string {
    return '₹' . number_format($amount, 2);
}

function inrShort(float $amount): string {
    if ($amount >= 1_00_00_000) return '₹' . number_format($amount / 1_00_00_000, 2) . 'Cr';
    if ($amount >= 1_00_000)    return '₹' . number_format($amount / 1_00_000, 2) . 'L';
    if ($amount >= 1_000)       return '₹' . number_format($amount / 1_000, 1) . 'K';
    return '₹' . number_format($amount, 0);
}
