<?php
/**
 * branch-compare.php  - tiny read-only git helper for branch-compare.html
 * DEV TOOL ONLY: delete both files before deploying to production.
 */
header('Content-Type: application/json');

// Only allow requests from your own machine. Set true ONLY if you know what you're doing.
const ALLOW_REMOTE = false;

if (!ALLOW_REMOTE && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Local access only']);
    exit;
}

function repoRoot(): ?string {
    $d = __DIR__;
    while (!file_exists($d . '/.git')) {
        $p = dirname($d);
        if ($p === $d) return null;
        $d = $p;
    }
    return $d;
}

function git(string $root, array $args): array {
    $p = proc_open(array_merge(['git', '-C', $root], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) return [1, '', 'Cannot run git'];
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), $out, $err];
}

function okRef($r): bool {
    return is_string($r) && $r !== '' && $r[0] !== '-' && strpos($r, '..') === false
        && preg_match('#^[A-Za-z0-9._/@^~+-]+$#', $r);
}

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

$root = repoRoot();
if (!$root) fail('No .git folder found above ' . __DIR__, 500);

$action = $_GET['action'] ?? '';

if ($action === 'branches') {
    [, $out] = git($root, ['for-each-ref', '--format=%(refname:short)', 'refs/heads', 'refs/remotes']);
    $branches = array_values(array_filter(array_map('trim', explode("\n", $out)),
        fn($b) => $b !== '' && !preg_match('#/HEAD$#', $b) && $b !== 'origin'));
    [, $cur] = git($root, ['rev-parse', '--abbrev-ref', 'HEAD']);
    echo json_encode(['branches' => $branches, 'current' => trim($cur), 'repo' => basename($root)]);
    exit;
}

if ($action === 'diff') {
    $base = $_GET['base'] ?? '';
    $head = $_GET['head'] ?? '';
    if (!okRef($base) || !okRef($head)) fail('Invalid branch name');
    $dots = ($_GET['mode'] ?? '...') === '..' ? '..' : '...';
    $ctx = max(0, min(99999, (int)($_GET['ctx'] ?? 3)));
    $args = ['diff', '--no-color', '--no-ext-diff', '-M', '-U' . $ctx];
    if (($_GET['ws'] ?? '') === '1') $args[] = '-w';
    $args[] = $base . $dots . $head;
    [$code, $out, $err] = git($root, $args);
    if ($code !== 0) fail(trim($err) ?: 'git diff failed', 500);
    echo json_encode(['diff' => $out]);
    exit;
}

fail('Unknown action');