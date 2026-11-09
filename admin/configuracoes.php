<?php
require_once '../config/database.php';
verificarAutenticacao();
require_once '../config/site_settings.php';

if (empty($_SESSION['csrf_personalizacao'])) $_SESSION['csrf_personalizacao'] = bin2hex(random_bytes(32));
$erro = '';
$sucesso = isset($_GET['salvo']);
$settings = shiftbarber_settings();
$temas = ['ambar' => '#f59e0b', 'azul' => '#38bdf8', 'vinho' => '#f43f5e', 'esmeralda' => '#34d399', 'roxo' => '#a78bfa'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_personalizacao'], (string)($_POST['csrf'] ?? ''))) {
        $erro = 'A sessão expirou. Atualize a página e tente novamente.';
    } else {
        $nome = trim(strip_tags((string)($_POST['nome'] ?? '')));
        $tema = (string)($_POST['tema'] ?? 'ambar');
        $cor = (string)($_POST['cor'] ?? '#f59e0b');
        if (!isset($temas[$tema])) $tema = 'ambar';
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $cor)) $cor = $temas[$tema];
        if ($nome === '' || mb_strlen($nome) > 60) $erro = 'Informe um nome de marca com até 60 caracteres.';

        $logoAtual = $settings['logo'];
        if (!$erro && isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['logo']['error'] !== UPLOAD_ERR_OK || $_FILES['logo']['size'] > 3 * 1024 * 1024) {
                $erro = 'Não foi possível receber a imagem. O limite é 3 MB.';
            } else {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['logo']['tmp_name']);
                $extensoes = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
                if (!isset($extensoes[$mime]) || @getimagesize($_FILES['logo']['tmp_name']) === false) {
                    $erro = 'Use uma imagem PNG, JPG ou WebP válida.';
                } else {
                    $pasta = dirname(__DIR__) . '/assets/uploads/site-config/';
                    if (!is_dir($pasta) && !mkdir($pasta, 0755, true) && !is_dir($pasta)) $erro = 'Não foi possível criar a pasta de imagens.';
                    if (!$erro) {
                        $nomeArquivo = 'logo_' . bin2hex(random_bytes(8)) . '.' . $extensoes[$mime];
                        if (!move_uploaded_file($_FILES['logo']['tmp_name'], $pasta . $nomeArquivo)) $erro = 'Não foi possível salvar o logo. Confira a permissão de gravação da hospedagem.';
                        else $logoAtual = 'assets/uploads/site-config/' . $nomeArquivo;
                    }
                }
            }
        }
        if (!$erro && !empty($_POST['restaurar_logo'])) $logoAtual = 'assets/logo.png';
        if (!$erro) {
            $payload = ['nome' => $nome, 'tema' => $tema, 'cor' => $cor, 'logo' => $logoAtual];
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
            if (file_put_contents(__DIR__ . '/../config/site-settings.json', $json . PHP_EOL, LOCK_EX) === false) {
                $erro = 'Não foi possível gravar as configurações. Verifique as permissões da pasta config.';
            } else {
                header('Location: configuracoes.php?salvo=1');
                exit;
            }
        }
    }
}
$settings = shiftbarber_settings();
require_once 'header.php';
?>
<div class="max-w-4xl mx-auto space-y-6">
    <div>
        <h2 class="text-2xl font-bold flex items-center gap-2"><i class="fa-solid fa-palette text-amber-500"></i> Personalização do sistema</h2>
        <p class="text-sm text-zinc-400 mt-1">Altere o nome, o tema de cores e o logo exibidos na área principal e nas telas dos clientes.</p>
    </div>
    <?php if ($sucesso): ?><div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm text-emerald-300">Personalização salva. As alterações já estão aplicadas.</div><?php endif; ?>
    <?php if ($erro): ?><div class="rounded-xl border border-red-500/30 bg-red-500/10 p-4 text-sm text-red-300"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="bg-zinc-900 border border-zinc-800 rounded-2xl p-5 sm:p-7 space-y-6">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_personalizacao'], ENT_QUOTES, 'UTF-8') ?>">
        <section class="space-y-2">
            <label for="nomeMarca" class="block text-sm font-semibold text-zinc-200">Nome da barbearia / marca</label>
            <input id="nomeMarca" type="text" name="nome" maxlength="60" required value="<?= htmlspecialchars($settings['nome'], ENT_QUOTES, 'UTF-8') ?>" class="w-full max-w-xl bg-zinc-800 border border-zinc-700 rounded-xl p-3 text-sm text-white focus:outline-none focus:border-amber-500">
        </section>
        <section class="space-y-3">
            <div><h3 class="text-sm font-semibold text-zinc-200">Tema e cor principal</h3><p class="text-xs text-zinc-400 mt-1">Escolha uma paleta pronta ou ajuste a cor pelo seletor.</p></div>
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-2">
                <?php foreach ($temas as $id => $hex): ?>
                    <label class="cursor-pointer rounded-xl border border-zinc-700 bg-zinc-800 p-3 text-xs flex items-center gap-2 hover:border-zinc-500">
                        <input type="radio" name="tema" value="<?= $id ?>" <?= $settings['tema'] === $id ? 'checked' : '' ?> data-color="<?= $hex ?>" class="accent-amber-500">
                        <span class="w-4 h-4 rounded-full border border-white/20" style="background:<?= $hex ?>"></span><?= ucfirst($id) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <label class="inline-flex items-center gap-3 text-xs text-zinc-300">Cor personalizada <input id="corMarca" type="color" name="cor" value="<?= htmlspecialchars($settings['cor'], ENT_QUOTES, 'UTF-8') ?>" class="w-12 h-9 rounded cursor-pointer bg-transparent border-0"></label>
        </section>
        <section class="space-y-3">
            <div><h3 class="text-sm font-semibold text-zinc-200">Logo</h3><p class="text-xs text-zinc-400 mt-1">Envie PNG, JPG ou WebP de até 3 MB. O logo aparece no painel e no agendamento.</p></div>
            <div class="flex flex-wrap items-center gap-4">
                <img src="<?= htmlspecialchars(shiftbarber_logo_url(), ENT_QUOTES, 'UTF-8') ?>" alt="Logo atual" class="w-20 h-20 object-cover rounded-2xl border border-zinc-700 bg-zinc-800">
                <div class="space-y-2"><input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="block text-xs text-zinc-300 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-700 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-white">
                <label class="flex items-center gap-2 text-xs text-zinc-400"><input type="checkbox" name="restaurar_logo" value="1" class="rounded"> Restaurar o logo atual do pacote</label></div>
            </div>
        </section>
        <div class="flex justify-end border-t border-zinc-800 pt-4"><button type="submit" class="bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold px-5 py-3 rounded-xl text-sm"><i class="fa-solid fa-floppy-disk mr-2"></i>Salvar personalização</button></div>
    </form>
</div>
<script>
document.querySelectorAll('input[name="tema"]').forEach(r => r.addEventListener('change', () => { document.getElementById('corMarca').value = r.dataset.color; }));
</script>
</main></body></html>
