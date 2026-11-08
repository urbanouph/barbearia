<?php
require_once '../config/database.php';
verificarAutenticacao();

$mensagemSucesso = '';
$mensagemErro = '';

if (isset($_GET['sucesso_bloqueio'])) {
    $mensagemSucesso = "Dia bloqueado com sucesso! Não haverá agendamentos nesta data.";
} elseif (isset($_GET['sucesso_remocao'])) {
    $mensagemSucesso = "Bloqueio removido com sucesso! A data foi liberada para agendamentos.";
}

// 1. Inserir Bloqueio de Data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cadastrar_bloqueio'])) {
    $dataBloqueio = $_POST['data_bloqueio'];
    $motivo = trim($_POST['motivo']) ?: 'Fechado / Folga Geral';

    if (!empty($dataBloqueio)) {
        $profissionalId = !empty($_POST['profissional_id']) ? (int)$_POST['profissional_id'] : null;
        try {
            // Verifica se já existe o mesmo bloqueio para o mesmo profissional (ou geral)
            $stmtCheck = $pdo->prepare("
                SELECT COUNT(*) FROM bloqueios_agenda 
                WHERE data_bloqueio = ? AND (profissional_id <=> ?)
            ");
            $stmtCheck->execute([$dataBloqueio, $profissionalId]);
            if ($stmtCheck->fetchColumn() > 0) {
                $mensagemErro = "Esta data já possui um bloqueio registado para este profissional/estabelecimento!";
            } else {
                $stmt = $pdo->prepare("INSERT INTO bloqueios_agenda (profissional_id, data_bloqueio, motivo) VALUES (?, ?, ?)");
                $stmt->execute([$profissionalId, $dataBloqueio, $motivo]);
                header('Location: bloqueios.php?sucesso_bloqueio=1');
                exit;
            }
        } catch (PDOException $e) {
            $mensagemErro = "Erro ao registar bloqueio: " . $e->getMessage();
        }
    } else {
        $mensagemErro = "Por favor, selecione uma data válida.";
    }
}

// 2. Remover Bloqueio
if (isset($_GET['remover_bloqueio'])) {
    $id = (int)$_GET['remover_bloqueio'];
    $stmt = $pdo->prepare("DELETE FROM bloqueios_agenda WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: bloqueios.php?sucesso_remocao=1');
    exit;
}

// Buscar lista de profissionais para o select
$profissionais = $pdo->query("SELECT * FROM profissionais WHERE status = 'ativo' ORDER BY nome ASC")->fetchAll();

// Buscar bloqueios com o nome do profissional (se houver)
$bloqueios = $pdo->query("
    SELECT b.*, p.nome as prof_nome 
    FROM bloqueios_agenda b 
    LEFT JOIN profissionais p ON b.profissional_id = p.id 
    ORDER BY b.data_bloqueio ASC
")->fetchAll();

require_once 'header.php';
?>

<div class="max-w-4xl mx-auto space-y-6">
    <div>
        <h2 class="text-2xl font-bold flex items-center gap-2">
            <i class="fa-solid fa-calendar-xmark text-amber-500"></i> Bloqueio de Dias & Feriados
        </h2>
        <p class="text-zinc-400 text-sm">Defina datas em que a barbearia estará fechada. Nesses dias, nenhum cliente poderá marcar horários.</p>
    </div>

    <?php if ($mensagemSucesso): ?>
        <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs p-3.5 rounded-xl flex items-center justify-between">
            <span><i class="fa-solid fa-circle-check mr-2"></i> <?= htmlspecialchars($mensagemSucesso) ?></span>
            <a href="bloqueios.php" class="text-zinc-400 hover:text-white">&times;</a>
        </div>
    <?php endif; ?>

    <?php if ($mensagemErro): ?>
        <div class="bg-red-500/10 border border-red-500/30 text-red-400 text-xs p-3.5 rounded-xl flex items-center justify-between">
            <span><i class="fa-solid fa-circle-exclamation mr-2"></i> <?= htmlspecialchars($mensagemErro) ?></span>
            <a href="bloqueios.php" class="text-zinc-400 hover:text-white">&times;</a>
        </div>
    <?php endif; ?>

    <!-- Formulário para Bloquear Data -->
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl p-5 shadow-xl space-y-4">
        <h3 class="font-bold text-white text-sm flex items-center gap-2">
            <i class="fa-solid fa-ban text-red-400"></i> Adicionar Novo Dia de Fechamento
        </h3>

        <form method="POST" class="grid grid-cols-1 sm:grid-cols-4 gap-3.5">
            <div>
                <label class="block text-xs font-semibold text-zinc-400 mb-1">Quem vai folgar?</label>
                <select name="profissional_id" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-amber-400 font-bold focus:outline-none focus:border-amber-500">
                    <option value="">🏢 Toda a Barbearia (Geral)</option>
                    <?php foreach ($profissionais as $p): ?>
                        <option value="<?= $p['id'] ?>">✂️ Apenas <?= htmlspecialchars($p['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-400 mb-1">Data da Folga / Bloqueio</label>
                <input type="date" name="data_bloqueio" value="<?= date('Y-m-d') ?>" required 
                       class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
            </div>

            <div class="sm:col-span-2">
                <label class="block text-xs font-semibold text-zinc-400 mb-1">Motivo / Descrição</label>
                <div class="flex gap-2">
                    <input type="text" name="motivo" placeholder="Ex: Folga semanal, Médico, Viagem, Feriado" required 
                           class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                    <button type="submit" name="cadastrar_bloqueio" 
                            class="bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold px-4 py-2.5 rounded-xl text-xs transition whitespace-nowrap flex items-center gap-1.5 shadow">
                        <i class="fa-solid fa-lock"></i> Salvar Bloqueio
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Tabela de Datas Bloqueadas -->
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden shadow-xl">
        <div class="p-4 border-b border-zinc-800 flex justify-between items-center">
            <h3 class="font-bold text-white text-sm">Calendário de Dias Bloqueados (<?= count($bloqueios) ?>)</h3>
            <span class="text-xs text-zinc-500">Datas fechadas para agendamento online</span>
        </div>

        <div class="overflow-x-auto w-full">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-zinc-800 text-zinc-400 uppercase tracking-wider text-[11px]">
                        <th class="p-3.5">Data Bloqueada</th>
                        <th class="p-3.5">Dia da Semana</th>
                        <th class="p-3.5">Quem Folga / Aplicação</th>
                        <th class="p-3.5">Motivo</th>
                        <th class="p-3.5 text-right">Ação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800">
                    <?php if (empty($bloqueios)): ?>
                        <tr>
                            <td colspan="5" class="p-6 text-center text-zinc-500">
                                Nenhuma data bloqueada no momento. A barbearia segue a escala padrão.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php 
                    $diasNomes = [0 => 'Domingo', 1 => 'Segunda-feira', 2 => 'Terça-feira', 3 => 'Quarta-feira', 4 => 'Quinta-feira', 5 => 'Sexta-feira', 6 => 'Sábado'];
                    foreach ($bloqueios as $b): 
                        $timeB = strtotime($b['data_bloqueio']);
                        $passado = $b['data_bloqueio'] < date('Y-m-d');
                    ?>
                    <tr class="hover:bg-zinc-800/30 transition <?= $passado ? 'opacity-50' : '' ?>">
                        <td class="p-3.5 font-bold text-white flex items-center gap-2">
                            <i class="fa-solid fa-calendar-day text-amber-500"></i>
                            <?= date('d/m/Y', $timeB) ?>
                            <?php if ($passado): ?>
                                <span class="text-[10px] bg-zinc-800 text-zinc-400 px-1.5 py-0.5 rounded">Passado</span>
                            <?php endif; ?>
                        </td>
                        <td class="p-3.5 text-zinc-300">
                            <?= $diasNomes[(int)date('w', $timeB)] ?? '' ?>
                        </td>
                        <td class="p-3.5">
                            <?php if (!empty($b['profissional_id'])): ?>
                                <span class="bg-amber-500/10 text-amber-400 border border-amber-500/30 px-2 py-0.5 rounded-full text-[11px] font-bold inline-flex items-center gap-1">
                                    <i class="fa-solid fa-user-tie text-[10px]"></i> Apenas <?= htmlspecialchars($b['prof_nome'] ?? 'Barbeiro') ?>
                                </span>
                            <?php else: ?>
                                <span class="bg-red-950/60 text-red-300 border border-red-800/60 px-2 py-0.5 rounded-full text-[11px] font-bold inline-flex items-center gap-1">
                                    <i class="fa-solid fa-store-slash text-[10px]"></i> Toda a Barbearia
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="p-3.5 text-zinc-200 font-medium">
                            <?= htmlspecialchars($b['motivo']) ?>
                        </td>
                        <td class="p-3.5 text-right">
                            <a href="bloqueios.php?remover_bloqueio=<?= $b['id'] ?>" onclick="return confirm('Deseja liberar esta data para agendamentos?');" 
                               class="text-zinc-500 hover:text-red-400 p-1.5 rounded-lg transition" title="Liberar data">
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
