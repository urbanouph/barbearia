<?php
require_once '../config/database.php';
verificarAutenticacao();

$mensagemSucesso = '';
$mensagemErro = '';

if (isset($_GET['sucesso_vinculo'])) {
    $mensagemSucesso = "Serviços e comissões do profissional atualizados com sucesso!";
} elseif (isset($_GET['sucesso_del'])) {
    $mensagemSucesso = "Profissional removido com sucesso!";
}

// 1. Atualizar Vínculos de Serviços e Comissões do Profissional
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['atualizar_vinculos_prof'])) {
    $profId = (int)$_POST['prof_id'];
    $servicosEscolhidos = $_POST['servicos_vinculados'] ?? [];

    // Limpa vínculos anteriores
    $stmtDel = $pdo->prepare("DELETE FROM profissional_servicos WHERE profissional_id = ?");
    $stmtDel->execute([$profId]);

    // Insere novos vínculos com comissões específicas
    if (!empty($servicosEscolhidos)) {
        $comissoesServ = $_POST['comissao_prof_serv'] ?? [];
        $stmtIns = $pdo->prepare("INSERT INTO profissional_servicos (profissional_id, servico_id, comissao_percentual) VALUES (?, ?, ?)");
        foreach ($servicosEscolhidos as $sId) {
            $comissaoItem = !empty($comissoesServ[$sId]) ? (float)str_replace(',', '.', strval($comissoesServ[$sId])) : 50.00;
            $stmtIns->execute([$profId, (int)$sId, $comissaoItem]);
        }
    }
    header('Location: equipe_servicos.php?sucesso_vinculo=1');
    exit;
}

// 2. Excluir Profissional se solicitado
if (isset($_GET['excluir_prof'])) {
    $id = (int)$_GET['excluir_prof'];
    $pdo->prepare("DELETE FROM profissionais WHERE id = ?")->execute([$id]);
    header('Location: equipe_servicos.php?sucesso_del=1');
    exit;
}

// Buscar profissionais
$profissionais = $pdo->query("SELECT * FROM profissionais ORDER BY nome ASC")->fetchAll();

// Buscar todos os serviços ativos
$servicos = $pdo->query("SELECT * FROM servicos WHERE status = 'ativo' ORDER BY nome ASC")->fetchAll();

// Mapear serviços e comissões vinculadas por profissional
$stmtVinculos = $pdo->query("SELECT * FROM profissional_servicos");
$mapaServicosProf = [];
$mapaComissoesProf = [];
while ($row = $stmtVinculos->fetch()) {
    $mapaServicosProf[$row['profissional_id']][] = (int)$row['servico_id'];
    $mapaComissoesProf[$row['profissional_id']][$row['servico_id']] = (float)$row['comissao_percentual'];
}

require_once 'header.php';
?>

<div class="max-w-6xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold flex items-center gap-2">
                <i class="fa-solid fa-user-gear text-amber-500"></i> Serviços & Comissões por Profissional
            </h2>
            <p class="text-zinc-400 text-sm">Defina exatamente quais serviços cada membro da equipe realiza e a porcentagem de comissão individual.</p>
        </div>
        <div class="flex gap-2">
            <a href="profissionais.php" class="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 font-bold px-3.5 py-2 rounded-xl text-xs transition border border-zinc-700 inline-flex items-center gap-1.5">
                <i class="fa-solid fa-user-plus text-amber-500"></i> Novo Barbeiro
            </a>
            <a href="servicos.php" class="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 font-bold px-3.5 py-2 rounded-xl text-xs transition border border-zinc-700 inline-flex items-center gap-1.5">
                <i class="fa-solid fa-scissors text-amber-500"></i> Catálogo de Serviços
            </a>
        </div>
    </div>

    <?php if ($mensagemSucesso): ?>
        <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs p-3.5 rounded-xl flex items-center justify-between shadow">
            <span><i class="fa-solid fa-circle-check mr-2"></i> <?= htmlspecialchars($mensagemSucesso) ?></span>
            <a href="equipe_servicos.php" class="text-zinc-400 hover:text-white">&times;</a>
        </div>
    <?php endif; ?>

    <?php if ($mensagemErro): ?>
        <div class="bg-red-500/10 border border-red-500/30 text-red-400 text-xs p-3.5 rounded-xl flex items-center justify-between shadow">
            <span><i class="fa-solid fa-circle-exclamation mr-2"></i> <?= htmlspecialchars($mensagemErro) ?></span>
            <a href="equipe_servicos.php" class="text-zinc-400 hover:text-white">&times;</a>
        </div>
    <?php endif; ?>

    <!-- LISTAGEM EXCLUSIVA DE PROFISSIONAIS E SERVIÇOS ATRIBUÍDOS -->
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden shadow-xl">
        <div class="p-4 sm:p-5 border-b border-zinc-800 flex justify-between items-center">
            <h3 class="font-bold text-white text-base">Profissionais Cadastrados (<?= count($profissionais) ?>)</h3>
            <span class="text-xs text-zinc-400">Serviços executados por cada membro da equipe</span>
        </div>

        <div class="divide-y divide-zinc-800">
            <?php if (empty($profissionais)): ?>
                <div class="p-8 text-center text-zinc-500 text-xs">
                    Nenhum profissional cadastrado ainda. <a href="profissionais.php" class="text-amber-500 underline ml-1">Cadastre o primeiro aqui</a>.
                </div>
            <?php endif; ?>

            <?php foreach ($profissionais as $prof): 
                $meusServIds = $mapaServicosProf[$prof['id']] ?? [];
            ?>
                <div class="p-4 sm:p-5 hover:bg-zinc-800/20 transition space-y-3">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <div class="space-y-1.5">
                            <div class="flex items-center gap-2.5">
                                <span class="font-bold text-white text-base"><?= htmlspecialchars($prof['nome']) ?></span>
                                <span class="text-[11px] bg-zinc-800 text-zinc-400 px-2 py-0.5 rounded-full border border-zinc-700">
                                    <?= htmlspecialchars($prof['especialidade'] ?: 'Barbeiro') ?>
                                </span>
                            </div>
                            <div class="text-xs text-zinc-400 flex items-center gap-2">
                                <span><i class="fa-brands fa-whatsapp text-emerald-400 mr-1"></i><?= htmlspecialchars($prof['telefone'] ?: 'Não informado') ?></span>
                            </div>
                        </div>

                        <!-- Botões de Ação -->
                        <div class="flex items-center gap-2 shrink-0">
                            <button type="button" onclick="document.getElementById('modal-prof-<?= $prof['id'] ?>').classList.toggle('hidden')" 
                                    class="text-xs bg-zinc-800 hover:bg-zinc-700 text-zinc-200 border border-zinc-700 px-3.5 py-2 rounded-xl transition flex items-center gap-1.5 font-semibold">
                                <i class="fa-solid fa-pen-to-square text-amber-500"></i> Ajustar Serviços
                            </button>
                            <a href="equipe_servicos.php?excluir_prof=<?= $prof['id'] ?>" onclick="return confirm('Deseja excluir este profissional da equipe?');" 
                               class="text-xs text-red-400 hover:text-red-300 bg-red-950/40 border border-red-900/50 p-2.5 rounded-xl transition" title="Excluir">
                                <i class="fa-solid fa-trash"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Badges dos Serviços e Comissões Atuais -->
                    <div class="flex flex-wrap gap-1.5 pt-1">
                        <?php if (empty($meusServIds)): ?>
                            <span class="text-xs text-zinc-500 italic bg-zinc-950 px-2.5 py-1 rounded-lg border border-zinc-800">
                                Nenhum serviço vinculado a este profissional. Clique em 'Ajustar Serviços' para vincular.
                            </span>
                        <?php else: ?>
                            <?php foreach ($servicos as $s): 
                                if (in_array($s['id'], $meusServIds)): 
                                    $comissaoItem = $mapaComissoesProf[$prof['id']][$s['id']] ?? ($s['comissao_percentual'] ?? 50.00);
                            ?>
                                <span class="inline-flex items-center gap-1.5 bg-amber-500/10 text-amber-400 border border-amber-500/30 px-2.5 py-1 rounded-lg text-xs font-medium">
                                    <i class="fa-solid fa-scissors text-[10px]"></i> 
                                    <span><?= htmlspecialchars($s['nome']) ?></span>
                                    <strong class="text-amber-300 font-bold">(<?= number_format($comissaoItem, 2, ',', '.') ?>%)</strong>
                                </span>
                            <?php 
                                endif;
                            endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- GAVETA EXPANSÍVEL / MODAL DE AJUSTE DE SERVIÇOS E COMISSÕES -->
                    <div id="modal-prof-<?= $prof['id'] ?>" class="hidden bg-zinc-950/90 p-5 rounded-2xl border border-zinc-800 text-xs mt-3 space-y-4">
                        <div class="border-b border-zinc-800 pb-2 flex justify-between items-center">
                            <span class="font-bold text-white text-xs flex items-center gap-1.5">
                                <i class="fa-solid fa-sliders text-amber-500"></i> Selecione os serviços que <strong><?= htmlspecialchars($prof['nome']) ?></strong> pode realizar:
                            </span>
                            <button type="button" onclick="document.getElementById('modal-prof-<?= $prof['id'] ?>').classList.add('hidden')" class="text-zinc-500 hover:text-white">&times;</button>
                        </div>

                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="prof_id" value="<?= $prof['id'] ?>">

                            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                                <?php foreach ($servicos as $s): 
                                    $vinculado = in_array($s['id'], $meusServIds);
                                    $comissaoItemProf = $mapaComissoesProf[$prof['id']][$s['id']] ?? ($s['comissao_percentual'] ?? 50.00);
                                ?>
                                    <div class="bg-zinc-900 border border-zinc-800 p-3 rounded-xl flex items-center justify-between gap-2 hover:border-zinc-700 transition">
                                        <label class="flex items-center gap-2 cursor-pointer font-medium text-zinc-300">
                                            <input type="checkbox" name="servicos_vinculados[]" value="<?= $s['id'] ?>" 
                                                   <?= $vinculado ? 'checked' : '' ?>
                                                   class="rounded text-amber-500 focus:ring-amber-500 bg-zinc-800 border-zinc-700">
                                            <span><?= htmlspecialchars($s['nome']) ?></span>
                                        </label>
                                        <div class="flex items-center gap-1 shrink-0">
                                            <input type="text" inputmode="decimal" name="comissao_prof_serv[<?= $s['id'] ?>]" 
                                                   value="<?= number_format($comissaoItemProf, 2, ',', '.') ?>" 
                                                   class="w-16 bg-zinc-800 border border-zinc-700 rounded-lg p-1.5 text-xs text-right text-amber-400 font-bold focus:outline-none focus:border-amber-500" 
                                                   title="% Comissão">
                                            <span class="text-zinc-500 font-bold">%</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="flex justify-end gap-2 pt-2 border-t border-zinc-800">
                                <button type="button" onclick="document.getElementById('modal-prof-<?= $prof['id'] ?>').classList.add('hidden')" 
                                        class="px-4 py-2 rounded-xl text-xs bg-zinc-800 text-zinc-300 hover:text-white">
                                    Cancelar
                                </button>
                                <button type="submit" name="atualizar_vinculos_prof" 
                                        class="bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold px-5 py-2 rounded-xl text-xs transition shadow flex items-center gap-1.5">
                                    <i class="fa-solid fa-floppy-disk"></i> Salvar Vínculos de <?= htmlspecialchars($prof['nome']) ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
</main>
</body>
</html>
