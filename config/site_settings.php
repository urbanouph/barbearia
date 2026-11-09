<?php
// Personalização leve em JSON para evitar migração do banco de dados.
function shiftbarber_settings() {
    static $settings = null;
    if ($settings !== null) return $settings;
    $defaults = ['nome' => 'SHIFT BARBER', 'tema' => 'ambar', 'cor' => '#f59e0b', 'logo' => 'assets/logo.png'];
    $file = __DIR__ . '/site-settings.json';
    $saved = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
    if (!is_array($saved)) $saved = [];
    $settings = array_merge($defaults, $saved);
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string)$settings['cor'])) $settings['cor'] = $defaults['cor'];
    if (!preg_match('#^assets/uploads/site-config/logo_[A-Za-z0-9._-]+\.(png|jpe?g|webp)$#i', (string)$settings['logo'])) $settings['logo'] = $defaults['logo'];
    $settings['nome'] = trim(strip_tags((string)$settings['nome'])) ?: $defaults['nome'];
    return $settings;
}
function shiftbarber_public_path($relative) {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $dir = dirname($script);
    if (preg_match('#/admin(?:/|$)#', $script)) $dir = dirname($dir);
    $base = rtrim($dir, '/.');
    return ($base ? $base : '') . '/' . ltrim($relative, '/');
}
function shiftbarber_logo_url() {
    $s = shiftbarber_settings();
    return shiftbarber_public_path($s['logo']);
}
function shiftbarber_brand_css() {
    $s = shiftbarber_settings();
    $color = htmlspecialchars($s['cor'], ENT_QUOTES, 'UTF-8');
    return '<style>:root{--brand-primary:' . $color . ';--brand-primary-rgb:' . implode(',', sscanf($s['cor'], '#%02x%02x%02x')) . '} .text-amber-400,.text-amber-500,.text-amber-600{color:var(--brand-primary)!important}.bg-amber-500{background-color:var(--brand-primary)!important}.border-amber-500,.border-amber-500\/30,.border-amber-500\/40,.border-amber-500\/60{border-color:var(--brand-primary)!important}.accent-amber-500{accent-color:var(--brand-primary)!important}.focus\\:border-amber-500:focus{border-color:var(--brand-primary)!important}.hover\\:text-amber-400:hover,.hover\\:text-amber-500:hover{color:var(--brand-primary)!important}.hover\\:bg-amber-600:hover{background-color:var(--brand-primary)!important;filter:brightness(.92)}.bg-amber-500\\/10{background-color:rgba(var(--brand-primary-rgb),.10)!important}</style>';
}
?>
