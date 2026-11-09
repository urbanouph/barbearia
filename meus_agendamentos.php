<?php
require_once 'config/database.php';

if (!isset($_SESSION['cliente_id'])) {
    header('Location: cliente_login.php');
    exit;
}

$clienteId = (int)$_SESSION['cliente_id'];
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Busca pacotes do cliente
$pdo->query("UPDATE cliente_pacotes SET status = 'expirado' WHERE status = 'ativo' AND data_expiracao < CURDATE()");
$stmtMeusPacotes = $pdo->prepare("
    SELECT cp.*, s.nome as servico_nome 
    FROM cliente_pacotes cp 
    JOIN servicos s ON cp.servico_id = s.id 
    WHERE cp.cliente_id = ? 
    ORDER BY cp.data_expiracao ASC
");
$stmtMeusPacotes->execute([$clienteId]);
$meusPacotes = $stmtMeusPacotes->fetchAll();

$mensagem = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancelar_agendamento'])) {
    $agendamentoId = (int)($_POST['agendamento_id'] ?? 0);
    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $mensagem = 'Sua sessão expirou. Atualize a página e tente novamente.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmtEstado = $pdo->prepare("SELECT status FROM agendamentos WHERE id = ? AND cliente_id = ? FOR UPDATE");
            $stmtEstado->execute([$agendamentoId, $clienteId]);
            $estado = $stmtEstado->fetchColumn();
            if ($estado !== 'pendente') {
                throw new RuntimeException('Este agendamento não pode mais ser cancelado por esta tela.');
            }

            $stmtProdutos = $pdo->prepare("SELECT item_id, SUM(quantidade) AS quantidade FROM agendamento_itens WHERE agendamento_id = ? AND tipo = 'produto' GROUP BY item_id");
            $stmtProdutos->execute([$agendamentoId]);
            $stmtDevolve = $pdo->prepare("UPDATE produtos SET quantidade_estoque = quantidade_estoque + ? WHERE id = ?");
            foreach ($stmtProdutos->fetchAll() as $itemProduto) {
                $stmtDevolve->execute([(int)$itemProduto['quantidade'], (int)$itemProduto['item_id']]);
            }

            $stmtCancela = $pdo->prepare("UPDATE agendamentos SET status = 'cancelado' WHERE id = ? AND cliente_id = ? AND status = 'pendente'");
            $stmtCancela->execute([$agendamentoId, $clienteId]);
            if ($stmtCancela->rowCount() !== 1) {
                throw new RuntimeException('Não foi possível confirmar o cancelamento. Atualize a página e tente novamente.');
            }
            $pdo->commit();
            $mensagem = 'Agendamento cancelado com sucesso. Os produtos reservados voltaram ao estoque.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $mensagem = $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível cancelar agora. Tente novamente.';
        }
    }
}

$stmt = $pdo->prepare("
    SELECT a.*, s.nome AS servico_nome, s.preco, s.duracao_minutos 
    FROM agendamentos a
    JOIN servicos s ON a.servico_id = s.id
    WHERE a.cliente_id = ?
    ORDER BY a.data_agendamento DESC, a.horario DESC
");
$stmt->execute([$clienteId]);
$meusAgendamentos = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus Agendamentos - Barbearia</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="assets/logo.png">
    <link rel="apple-touch-icon" href="assets/logo.png">
</head>
<body class="bg-zinc-950 text-zinc-100 min-h-screen flex items-center justify-center p-4">

    <!-- Container padronizado com max-w-md w-full -->
    <div class="max-w-md w-full bg-zinc-900 border border-zinc-800 rounded-2xl p-6 sm:p-8 shadow-2xl my-8 space-y-6">

        <!-- Topo -->
        <div class="flex items-center justify-between pb-4 border-b border-zinc-800">
            <div>
                <span class="text-[11px] text-zinc-400 uppercase tracking-wider block">Área do Cliente</span>
                <h1 class="text-xl font-bold text-white"><?= htmlspecialchars($_SESSION['cliente_nome']) ?></h1>
            </div>
            <div class="flex items-center gap-2">
                <a href="perfil.php" class="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 p-2.5 rounded-xl text-xs transition" title="Editar Perfil e Palavra-passe">
                    <i class="fa-solid fa-user-gear"></i>
                </a>
                <a href="logout_cliente.php" class="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 p-2.5 rounded-xl text-xs transition" title="Terminar Sessão">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i>
                </a>
            </div>
        </div>

        <!-- Botão Novo Horário -->
        <div class="flex items-center justify-between">
            <span class="text-xs font-semibold uppercase tracking-wider text-zinc-400">As minhas marcações</span>
            <a href="agendar.php" class="bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold px-3 py-1.5 rounded-xl text-xs transition flex items-center gap-1.5">
                <i class="fa-solid fa-plus"></i> Novo Horário
            </a>
        </div>

        <?php if ($mensagem): ?>
            <div class="bg-amber-500/10 border border-amber-500/30 text-amber-400 text-xs p-3 rounded-xl text-center">
                <i class="fa-solid fa-circle-info mr-1"></i> <?= htmlspecialchars($mensagem) ?>
            </div>
        <?php endif; ?>

        <!-- Lista de Agendamentos -->
        <div class="space-y-3 max-h-[420px] overflow-y-auto pr-1">
            <?php if (empty($meusAgendamentos)): ?>
                <div class="bg-zinc-800/40 border border-zinc-800 p-8 rounded-2xl text-center space-y-3">
                    <i class="fa-solid fa-calendar-xmark text-3xl text-zinc-600"></i>
                    <p class="text-xs text-zinc-400">Ainda não tem marcações registadas.</p>
                    <a href="agendar.php" class="inline-block bg-amber-500 text-zinc-950 font-bold px-4 py-2 rounded-xl text-xs">
                        Marcar Primeiro Horário
                    </a>
                </div>
            <?php else: ?>
                <?php foreach ($meusAgendamentos as $ag): ?>
                    <?php 
                        $badgeCor = match($ag['status']) {
                            'confirmado' => 'bg-blue-950/80 text-blue-400 border-blue-800',
                            'concluido'  => 'bg-emerald-950/80 text-emerald-400 border-emerald-800',
                            'cancelado'  => 'bg-red-950/80 text-red-400 border-red-800',
                            default      => 'bg-amber-950/80 text-amber-400 border-amber-800'
                        };
                    ?>
                    <div class="bg-zinc-800/50 border border-zinc-800 p-4 rounded-xl space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="bg-zinc-800 border border-zinc-700/80 rounded-lg p-2 text-center min-w-[60px]">
                                    <span class="block text-[10px] text-zinc-400 uppercase font-semibold">
                                        <?= date('d/m', strtotime($ag['data_agendamento'])) ?>
                                    </span>
                                    <span class="text-xs font-bold text-amber-500">
                                        <?= substr($ag['horario'], 0, 5) ?>
                                    </span>
                                </div>
                                <div>
                                    <h3 class="font-bold text-white text-xs"><?= htmlspecialchars($ag['servico_nome']) ?></h3>
                                    <span class="text-[11px] text-zinc-400 block mt-0.5">
                                        <?= $ag['duracao_minutos'] ?> min &bull; R$ <?= number_format($ag['preco'], 2, ',', '.') ?>
                                    </span>
                                </div>
                            </div>
                            <span class="text-[11px] uppercase tracking-wider px-2 py-0.5 rounded-full border <?= $badgeCor ?>">
                                <?= ucfirst($ag['status']) ?>
                            </span>
                        </div>

                        <?php if ($ag['status'] === 'pendente'): ?>
                            <div class="flex items-center justify-end gap-2 pt-2 border-t border-zinc-800/80">
                                <a href="remarcar.php?id=<?= $ag['id'] ?>" class="text-[11px] text-amber-400 hover:text-amber-300 border border-amber-500/40 hover:bg-amber-500/10 px-2.5 py-1 rounded-lg transition">
                                    <i class="fa-solid fa-clock-rotate-left mr-1"></i> Remarcar
                                </a>

                                <form method="POST" onsubmit="return confirm('Deseja realmente cancelar este horário?');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="agendamento_id" value="<?= $ag['id'] ?>">
                                    <button type="submit" name="cancelar_agendamento" class="text-[11px] text-red-400 hover:text-red-300 border border-red-900/50 hover:bg-red-950/30 px-2.5 py-1 rounded-lg transition">
                                        Cancelar
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>

</body>
</html>
