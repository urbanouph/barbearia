<?php
require_once '../config/database.php';
verificarAutenticacao();

$mensagem = '';
$tipoMensagem = 'success';
$edicao = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_cliente'])) {
    $id = filter_var($_POST['cliente_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $nome = trim($_POST['nome'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');

    $tamanhoNome = function_exists('mb_strlen') ? mb_strlen($nome, 'UTF-8') : strlen($nome);
    $tamanhoTelefone = function_exists('mb_strlen') ? mb_strlen($telefone, 'UTF-8') : strlen($telefone);
    if ($nome === '' || $tamanhoNome > 150 || $tamanhoTelefone > 40) {
        $mensagem = 'Informe um nome válido e confira o telefone.';
        $tipoMensagem = 'error';
    } elseif ($id !== false && $id !== null) {
        $stmt = $pdo->prepare('UPDATE clientes SET nome = ?, telefone = ? WHERE id = ?');
        $stmt->execute([$nome, $telefone, $id]);
        header('Location: clientes.php?atualizado=1', true, 303);
        exit;
    } else {
        $stmtDup = $pdo->prepare('SELECT id FROM clientes WHERE telefone = ? AND telefone <> "" LIMIT 1');
        $stmtDup->execute([$telefone]);
        if ($telefone !== '' && $stmtDup->fetch()) {
            $mensagem = 'Já existe um cliente com esse telefone. Pesquise a lista antes de cadastrar novamente.';
            $tipoMensagem = 'error';
        } else {
            $stmt = $pdo->prepare('INSERT INTO clientes (nome, telefone) VALUES (?, ?)');
            $stmt->execute([$nome, $telefone]);
            header('Location: clientes.php?cadastrado=1', true, 303);
            exit;
        }
    }
}

if (isset($_GET['cadastrado'])) $mensagem = 'Cliente cadastrado com sucesso.';
if (isset($_GET['atualizado'])) $mensagem = 'Cadastro do cliente atualizado.';

if (isset($_GET['editar'])) {
    $idEditar = filter_var($_GET['editar'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($idEditar !== false) {
        $stmt = $pdo->prepare('SELECT id, nome, telefone FROM clientes WHERE id = ? LIMIT 1');
        $stmt->execute([$idEditar]);
        $edicao = $stmt->fetch() ?: null;
    }
}

$busca = trim($_GET['q'] ?? '');
$sql = "SELECT c.id, c.nome, c.telefone, COUNT(a.id) AS total_agendamentos,
               MAX(a.data_agendamento) AS ultimo_agendamento
        FROM clientes c
        LEFT JOIN agendamentos a ON a.cliente_id = c.id";
$params = [];
if ($busca !== '') {
    $sql .= ' WHERE c.nome LIKE ? OR c.telefone LIKE ?';
    $params = ['%' . $busca . '%', '%' . $busca . '%'];
}
$sql .= ' GROUP BY c.id, c.nome, c.telefone ORDER BY c.nome ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$clientes = $stmt->fetchAll();
$totalClientes = (int)$pdo->query('SELECT COUNT(*) FROM clientes')->fetchColumn();

require_once 'header.php';
?>

<div class="max-w-6xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h2 class="text-2xl font-bold text-white">Controle de Clientes</h2>
            <p class="text-sm text-zinc-400">Cadastre, pesquise e atualize os contatos usados nos agendamentos.</p>
        </div>
        <span class="rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-2 text-sm font-bold text-amber-400"><?= $totalClientes ?> clientes cadastrados</span>
    </div>

    <?php if ($mensagem !== ''): ?>
        <div class="<?= $tipoMensagem === 'error' ? 'bg-red-500/10 border-red-500/30 text-red-300' : 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300' ?> border rounded-xl p-3 text-sm" role="status"><?= htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <section class="bg-zinc-900 border border-zinc-800 rounded-2xl p-5">
        <h3 class="font-bold text-white mb-4"><?= $edicao ? 'Editar cliente' : 'Cadastrar cliente' ?></h3>
        <form method="POST" class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
            <input type="hidden" name="salvar_cliente" value="1">
            <?php if ($edicao): ?><input type="hidden" name="cliente_id" value="<?= (int)$edicao['id'] ?>"><?php endif; ?>
            <div>
                <label class="block text-xs text-zinc-400 mb-1" for="nomeCliente">Nome completo</label>
                <input id="nomeCliente" type="text" name="nome" maxlength="150" required value="<?= htmlspecialchars($edicao['nome'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-3 text-sm text-white focus:outline-none focus:border-amber-500">
            </div>
            <div>
                <label class="block text-xs text-zinc-400 mb-1" for="telefoneCliente">Telefone / WhatsApp</label>
                <input id="telefoneCliente" type="tel" name="telefone" maxlength="40" value="<?= htmlspecialchars($edicao['telefone'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="(11) 99999-9999" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-3 text-sm text-white focus:outline-none focus:border-amber-500">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="flex-1 bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold rounded-xl px-4 py-3 text-sm"><?= $edicao ? 'Salvar alterações' : 'Cadastrar cliente' ?></button>
                <?php if ($edicao): ?><a href="clientes.php" class="bg-zinc-800 hover:bg-zinc-700 text-zinc-200 rounded-xl px-4 py-3 text-sm">Cancelar</a><?php endif; ?>
            </div>
        </form>
        <p class="text-xs text-zinc-500 mt-3">Os clientes também podem ser vinculados ao fazer um agendamento. Registros com histórico são mantidos para preservar os atendimentos anteriores.</p>
    </section>

    <section class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-zinc-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div><h3 class="font-bold text-white">Clientes cadastrados</h3><p class="text-xs text-zinc-500 mt-1"><?= count($clientes) ?> resultado(s)</p></div>
            <form method="GET" class="flex gap-2">
                <input type="search" name="q" value="<?= htmlspecialchars($busca, ENT_QUOTES, 'UTF-8') ?>" placeholder="Buscar nome ou telefone" class="bg-zinc-800 border border-zinc-700 rounded-xl px-3 py-2 text-sm text-white focus:outline-none focus:border-amber-500">
                <button class="bg-zinc-700 hover:bg-zinc-600 text-white rounded-xl px-4 py-2 text-sm font-semibold">Buscar</button>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm min-w-[650px]">
                <thead><tr class="text-zinc-400 text-xs uppercase border-b border-zinc-800"><th class="p-4">Cliente</th><th class="p-4">Telefone</th><th class="p-4">Agendamentos</th><th class="p-4">Último atendimento</th><th class="p-4 text-right">Ação</th></tr></thead>
                <tbody class="divide-y divide-zinc-800">
                <?php if (!$clientes): ?>
                    <tr><td colspan="5" class="p-8 text-center text-zinc-500">Nenhum cliente encontrado.</td></tr>
                <?php else: foreach ($clientes as $cliente): ?>
                    <tr class="hover:bg-zinc-800/40">
                        <td class="p-4 font-semibold text-zinc-100"><?= htmlspecialchars($cliente['nome'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="p-4 text-zinc-300"><?= htmlspecialchars($cliente['telefone'] ?? '', ENT_QUOTES, 'UTF-8') ?: '—' ?></td>
                        <td class="p-4 text-amber-400 font-bold"><?= (int)$cliente['total_agendamentos'] ?></td>
                        <td class="p-4 text-zinc-400"><?= $cliente['ultimo_agendamento'] ? date('d/m/Y', strtotime($cliente['ultimo_agendamento'])) : '—' ?></td>
                        <td class="p-4 text-right"><div class="inline-flex gap-2"><a href="relatorio_clientes.php?cliente_id=<?= (int)$cliente['id'] ?>" class="inline-flex items-center gap-2 rounded-lg bg-amber-500/10 hover:bg-amber-500/20 px-3 py-2 text-xs font-semibold text-amber-300"><i class="fa-solid fa-chart-line"></i> Histórico</a><a href="clientes.php?editar=<?= (int)$cliente['id'] ?><?= $busca !== '' ? '&q=' . urlencode($busca) : '' ?>" class="inline-flex items-center gap-2 rounded-lg bg-zinc-800 hover:bg-zinc-700 px-3 py-2 text-xs font-semibold text-white"><i class="fa-solid fa-pen"></i> Editar</a></div></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

</main>
</body>
</html>
