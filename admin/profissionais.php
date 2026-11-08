<?php
require_once '../config/database.php';
verificarAutenticacao();

$mensagemSucesso = '';
$mensagemErro = '';

if (isset($_GET['sucesso_cad'])) $mensagemSucesso = "Profissional cadastrado com sucesso!";
if (isset($_GET['sucesso_alt'])) $mensagemSucesso = "Estado do profissional atualizado com sucesso!";
if (isset($_GET['sucesso_del'])) $mensagemSucesso = "Profissional removido com sucesso!";

// 1. Cadastrar Barbeiro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cadastrar_profissional'])) {
    $nome = trim($_POST['nome'] ?? '');
    $especialidade = trim($_POST['especialidade'] ?? 'Barbeiro');
    $telefone = trim($_POST['telefone'] ?? '');

    if (empty($nome)) {
        $mensagemErro = "O nome do profissional é obrigatório.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO profissionais (nome, especialidade, telefone, status) VALUES (?, ?, ?, 'ativo')");
        $stmt->execute([$nome, $especialidade, $telefone]);
        header("Location: profissionais.php?sucesso_cad=1");
        exit;
    }
}

// 2. Alternar Status (Ativo / Inativo)
if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    $stmt = $pdo->prepare("UPDATE profissionais SET status = IF(status = 'ativo', 'inativo', 'ativo') WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: profissionais.php?sucesso_alt=1");
    exit;
}

// 3. Excluir Barbeiro
if (isset($_GET['excluir_prof'])) {
    $id = (int)$_GET['excluir_prof'];
    $stmt = $pdo->prepare("DELETE FROM profissionais WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: profissionais.php?sucesso_del=1");
    exit;
}

// Listar todos os profissionais
$profissionais = $pdo->query("
    SELECT p.*, 
           COUNT(DISTINCT a.id) as total_atendimentos,
           COUNT(DISTINCT t.id) as turnos_cadastrados
    FROM profissionais p
    LEFT JOIN agendamentos a ON a.profissional_id = p.id
    LEFT JOIN turnos_atendimento t ON t.profissional_id = p.id
    GROUP BY p.id
    ORDER BY p.status ASC, p.nome ASC
")->fetchAll();

require_once 'header.php';
?>

<div class="max-w-6xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold flex items-center gap-2">
                <i class="fa-solid fa-users text-amber-500"></i> Gestão de Profissionais & Barbeiros
            </h2>
            <p class="text-zinc-400 text-sm">Registe membros da equipa, altere especialidades e controle o estado de atuação.</p>
        </div>
        <a href="escalas.php" class="bg-zinc-800 hover:bg-zinc-700 text-zinc-200 font-bold px-4 py-2.5 rounded-xl text-xs transition inline-flex items-center gap-2 border border-zinc-700">
            <i class="fa-solid fa-calendar-days text-amber-500"></i> Ver Escalas de Trabalho
        </a>
    </div>

    <?php if ($mensagemSucesso): ?>
        <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs p-3.5 rounded-xl flex items-center justify-between">
            <span><i class="fa-solid fa-circle-check mr-2"></i> <?= htmlspecialchars($mensagemSucesso) ?></span>
            <a href="profissionais.php" class="text-zinc-400 hover:text-white">&times;</a>
        </div>
    <?php endif; ?>

    <?php if ($mensagemErro): ?>
        <div class="bg-red-500/10 border border-red-500/30 text-red-400 text-xs p-3.5 rounded-xl flex items-center justify-between">
            <span><i class="fa-solid fa-circle-exclamation mr-2"></i> <?= htmlspecialchars($mensagemErro) ?></span>
            <a href="profissionais.php" class="text-zinc-400 hover:text-white">&times;</a>
        </div>
    <?php endif; ?>

    <!-- FORMULÁRIO DE CADASTRO -->
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl p-5 shadow-xl space-y-4">
        <h3 class="font-bold text-white text-sm flex items-center gap-2">
            <i class="fa-solid fa-user-plus text-amber-500"></i> Cadastrar Novo Barbeiro
        </h3>

        <form method="POST" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3.5 items-end">
            <div>
                <label class="block text-xs font-semibold text-zinc-400 mb-1">Nome Completo</label>
                <input type="text" name="nome" placeholder="Ex: Lucas Silva" required 
                       class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-400 mb-1">Especialidade / Cargo</label>
                <input type="text" name="especialidade" placeholder="Ex: Barbeiro Master, Visagista" value="Barbeiro" 
                       class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-400 mb-1">Telefone / WhatsApp</label>
                <input type="text" name="telefone" placeholder="Ex: (14) 99999-8888" 
                       class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
            </div>

            <div>
                <button type="submit" name="cadastrar_profissional" 
                        class="w-full bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold py-2.5 px-4 rounded-xl text-xs transition flex items-center justify-center gap-2 shadow">
                    <i class="fa-solid fa-plus"></i> Guardar Barbeiro
                </button>
            </div>
        </form>
    </div>

    <!-- LISTA DE PROFISSIONAIS -->
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden shadow">
        <div class="p-4 border-b border-zinc-800 flex justify-between items-center">
            <h3 class="font-bold text-white text-sm">Barbeiros Registados (<?= count($profissionais) ?>)</h3>
            <span class="text-xs text-zinc-500">Equipa ativa e inativa</span>
        </div>

        <div class="overflow-x-auto w-full">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-zinc-800 text-zinc-400 uppercase tracking-wider text-[11px]">
                        <th class="p-3.5">Profissional</th>
                        <th class="p-3.5">Especialidade</th>
                        <th class="p-3.5">Telefone</th>
                        <th class="p-3.5 text-center">Atendimentos</th>
                        <th class="p-3.5 text-center">Escalas</th>
                        <th class="p-3.5 text-center">Estado</th>
                        <th class="p-3.5 text-right">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800">
                    <?php if (empty($profissionais)): ?>
                        <tr><td colspan="7" class="p-6 text-center text-zinc-500">Nenhum profissional registado.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($profissionais as $p): ?>
                    <tr class="hover:bg-zinc-800/30 transition">
                        <td class="p-3.5 font-bold text-white flex items-center gap-2.5">
                            <div class="w-8 h-8 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 flex items-center justify-center font-extrabold text-xs">
                                <?= strtoupper(substr($p['nome'], 0, 1)) ?>
                            </div>
                            <?= htmlspecialchars($p['nome']) ?>
                        </td>
                        <td class="p-3.5 text-zinc-300"><?= htmlspecialchars($p['especialidade']) ?></td>
                        <td class="p-3.5 text-zinc-400"><?= htmlspecialchars($p['telefone'] ?: 'Não informado') ?></td>
                        <td class="p-3.5 text-center font-bold text-zinc-200"><?= $p['total_atendimentos'] ?></td>
                        <td class="p-3.5 text-center">
                            <a href="escalas.php" class="text-amber-400 hover:underline font-semibold">
                                <?= $p['turnos_cadastrados'] ?> turnos
                            </a>
                        </td>
                        <td class="p-3.5 text-center">
                            <a href="profissionais.php?toggle_status=<?= $p['id'] ?>" 
                               class="px-2.5 py-1 rounded-full font-bold uppercase text-[10px] transition <?= $p['status'] === 'ativo' ? 'bg-emerald-950/80 text-emerald-400 border border-emerald-800/60' : 'bg-zinc-800 text-zinc-500 border border-zinc-700' ?>">
                                <?= $p['status'] ?>
                            </a>
                        </td>
                        <td class="p-3.5 text-right whitespace-nowrap space-x-2">
                            <a href="bloqueios.php" class="text-zinc-400 hover:text-amber-400 p-1" title="Folgas">
                                <i class="fa-solid fa-calendar-xmark"></i>
                            </a>
                            <a href="profissionais.php?excluir_prof=<?= $p['id'] ?>" onclick="return confirm('Deseja eliminar este barbeiro?');" 
                               class="text-zinc-500 hover:text-red-400 p-1" title="Eliminar">
                                <i class="fa-solid fa-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</main>
</body>
</html>
