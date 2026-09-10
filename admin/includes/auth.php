<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_role('admin');
/**
 * Current sort key + direction from the query string.
 * @return array{0:string,1:string} [key, 'asc'|'desc']
 */
function sort_state(string $default_key = '', string $default_dir = 'asc'): array {
    $key = (string)($_GET['sort'] ?? $default_key);
    $dir = strtolower((string)($_GET['dir'] ?? $default_dir)) === 'desc' ? 'desc' : 'asc';
    return [$key, $dir];
}
/** A clickable <th> that toggles sorting on $key, preserving all other query params. */
function sort_th(string $label, string $key): string {
    [$curKey, $curDir] = sort_state();
    $nextDir = ($curKey === $key && $curDir === 'asc') ? 'desc' : 'asc';
    $params = array_merge($_GET, ['sort' => $key, 'dir' => $nextDir]);
    $arrow = $curKey === $key ? ($curDir === 'asc' ? ' ▲' : ' ▼') : ' <span class="sort-dim">↕</span>';
    return '<th><a class="sort-th" href="?' . e(http_build_query($params)) . '">' . e($label) . $arrow . '</a></th>';
}
/** Build a safe "ORDER BY ..." clause from a whitelist map {key: "sql column(s)"}. */
function sort_order_by(array $map, string $default_sql): string {
    [$key, $dir] = sort_state();
    if (!isset($map[$key])) return 'ORDER BY ' . $default_sql;
    return 'ORDER BY ' . $map[$key] . ' ' . ($dir === 'desc' ? 'DESC' : 'ASC');
}
/** Hidden inputs so a GET filter form keeps the active sort. */
function sort_hidden_inputs(): string {
    [$key, $dir] = sort_state();
    if ($key === '') return '';
    return '<input type="hidden" name="sort" value="' . e($key) . '"><input type="hidden" name="dir" value="' . e($dir) . '">';
}
