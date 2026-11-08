<?php
require_once '../config/database.php';
verificarAutenticacao();

$agendamentoId = (int)($_GET['id'] ?? 0);
$mensagemSucesso = '';
$mensagemErro = '';

if (!$agendamentoId) {
    header('Location: agendamentos.php');
    exit;
}

// 1. Busca dados do agendamento
$stmt = $pdo->prepare("
    SELECT a.*, s.nome as servico_nome, s.preco as servico_preco, s.eh_pacote, s.sessoes_pacote, s.validade_dias, p.nome as prof_nome
    FROM agendamentos a
    JOIN servicos s ON a.servico_id = s.id
    LEFT JOIN profissionais p ON a.profissional_id = p.id
    WHERE a.id = ?
");
$stmt->execute([$agendamentoId]);
$ag = $stmt->fetch();

if (!$ag) {
    header('Location: agendamentos.php');
    exit;
}

// 2. Adicionar Item Extra (Serviço ou Produto) na Comanda
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adicionar_item_extra'])) {
    $tipo = $_POST['tipo_item']; // 'servico' ou 'produto'
    $itemId = (int)$_POST['item_id'];
    $qtd = max(1, (int)($_POST['quantidade'] ?? 1));

    if ($tipo === 'servico') {
        $st = $pdo->prepare("SELECT nome, preco FROM servicos WHERE id = ?");
        $st->execute([$itemId]);
        $item = $st->fetch();
        if ($item) {
            $subtotal = $item['preco'] * $qtd;
            $ins = $pdo->prepare("INSERT INTO agendamento_itens (agendamento_id, tipo, item_id, nome_item, quantidade, preco_unitario, subtotal) VALUES (?, 'servico', ?, ?, ?, ?, ?)");
            $ins->execute([$agendamentoId, $itemId, $item['nome'], $qtd, $item['preco'], $subtotal]);
            $mensagemSucesso = "Serviço '{$item['nome']}' adicionado à comanda!";
        }
    } elseif ($tipo === 'produto') {
        $st = $pdo->prepare("SELECT nome, preco_venda, quantidade_estoque FROM produtos WHERE id = ?");
        $st->execute([$itemId]);
        $prod = $st->fetch();
        if ($prod) {
            if ($prod['quantidade_estoque'] < $qtd) {
                $mensagemErro = "Estoque insuficiente para {$prod['nome']}! (Disponível: {$prod['quantidade_estoque']})";
            } else {
                $subtotal = $prod['preco_venda'] * $qtd;
                $ins = $pdo->prepare("INSERT INTO agendamento_itens (agendamento_id, tipo, item_id, nome_item, quantidade, preco_unitario, subtotal) VALUES (?, 'produto', ?, ?, ?, ?, ?)");
                $ins->execute([$agendamentoId, $itemId, $prod['nome'], $qtd, $prod['preco_venda'], $subtotal]);
                
                // Dá baixa no estoque
                $baixa = $pdo->prepare("UPDATE produtos SET quantidade_estoque = quantidade_estoque - ? WHERE id = ?");
                $baixa->execute([$qtd, $itemId]);

                $mensagemSucesso = "Produto '{$prod['nome']}' adicionado e estoque atualizado!";
            }
        }
    }
}

// 3. Remover Item Extra da Comanda
if (isset($_GET['remover_item'])) {
    $itemRemoverId = (int)$_GET['remover_item'];
    
    $stItem = $pdo->prepare("SELECT * FROM agendamento_itens WHERE id = ? AND agendamento_id = ?");
    $stItem->execute([$itemRemoverId, $agendamentoId]);
    $itemRec = $stItem->fetch();

    if ($itemRec) {
        if ($itemRec['tipo'] === 'produto') {
            $dev = $pdo->prepare("UPDATE produtos SET quantidade_estoque = quantidade_estoque + ? WHERE id = ?");
            $dev->execute([$itemRec['quantidade'], $itemRec['item_id']]);
        }
        $del = $pdo->prepare("DELETE FROM agendamento_itens WHERE id = ?");
        $del->execute([$itemRemoverId]);
        header("Location: checkout.php?id={$agendamentoId}");
        exit;
    }
}

// 4. Confirmar Pagamento e Finalizar Atendimento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_pagamento'])) {
    $formaPagamento = $_POST['forma_pagamento'] ?? 'pix';
    $valorTotalFinal = (float)$_POST['valor_total_final'];

    $upd = $pdo->prepare("
        UPDATE agendamentos 
        SET status = 'concluido', 
            forma_pagamento = ?, 
            valor_total = ? 
        WHERE id = ?
    ");
    $upd->execute([$formaPagamento, $valorTotalFinal, $agendamentoId]);

    // 1. Se este agendamento veio de um pacote já contratado, abate 1 sessão de forma precisa
    if (!empty($ag['cliente_pacote_id'])) {
        $stBuscaPac = $pdo->prepare("SELECT sessoes_restantes FROM cliente_pacotes WHERE id = ?");
        $stBuscaPac->execute([(int)$ag['cliente_pacote_id']]);
        $saldoAtual = (int)$stBuscaPac->fetchColumn();

        $novoSaldo = max(0, $saldoAtual - 1);
        $novoStatus = ($novoSaldo <= 0) ? 'esgotado' : 'ativo';

        $stmtAbate = $pdo->prepare("
            UPDATE cliente_pacotes 
            SET sessoes_restantes = ?,
                status = ?
            WHERE id = ?
        ");
        $stmtAbate->execute([$novoSaldo, $novoStatus, (int)$ag['cliente_pacote_id']]);
    } elseif (!empty($ag['eh_pacote']) && !empty($ag['cliente_id'])) {
        // 2. Primeira vez pagando o pacote: ativa o pacote para o cliente!
        $sTotal = max(1, (int)$ag['sessoes_pacote']);
        $sRestantes = max(0, $sTotal - 1); // A 1ª sessão já está sendo concluída agora!
        $diasVal = max(1, (int)$ag['validade_dias']);
        $dtExp = date('Y-m-d', strtotime("+{$diasVal} days"));
        $statusNovo = ($sRestantes <= 0) ? 'esgotado' : 'ativo';

        $stNovoPac = $pdo->prepare("
            INSERT INTO cliente_pacotes (cliente_id, servico_id, sessoes_total, sessoes_restantes, data_compra, data_expiracao, status) 
            VALUES (?, ?, ?, ?, CURDATE(), ?, ?)
        ");
        $stNovoPac->execute([$ag['cliente_id'], $ag['servico_id'], $sTotal, $sRestantes, $dtExp, $statusNovo]);
        $novoPacId = $pdo->lastInsertId();

        // Vincula este 1º agendamento ao pacote recém-criado
        $pdo->prepare("UPDATE agendamentos SET cliente_pacote_id = ? WHERE id = ?")->execute([$novoPacId, $agendamentoId]);
    }

    // Redireciona com aviso para a lista de agendamentos ANTES de qualquer HTML ser emitido
    header("Location: index.php?sucesso_pagamento=1");
    exit;
}

// 5. Itens adicionais da comanda
$stmtItens = $pdo->prepare("SELECT * FROM agendamento_itens WHERE agendamento_id = ? ORDER BY id ASC");
$stmtItens->execute([$agendamentoId]);
$itensExtras = $stmtItens->fetchAll();

// Catálogo de serviços e produtos para os selects
$todosServicos = $pdo->query("SELECT * FROM servicos WHERE status = 'ativo' ORDER BY nome ASC")->fetchAll();
$todosProdutos = $pdo->query("SELECT * FROM produtos WHERE status = 'ativo' AND quantidade_estoque > 0 ORDER BY nome ASC")->fetchAll();

// Cálculo do Total considerando sessões de pacote já pago
$ehSessaoPacotePago = !empty($ag['cliente_pacote_id']) || (isset($ag['valor_total']) && (float)$ag['valor_total'] == 0.0);
$precoServicoPrincipal = $ehSessaoPacotePago ? 0.00 : (float)$ag['servico_preco'];
$totalComanda = $precoServicoPrincipal;
foreach ($itensExtras as $it) {
    $totalComanda += (float)$it['subtotal'];
}

// Agora que todas as regras de redirecionamento foram processadas sem envio prévio, carrega o header HTML
require_once 'header.php';
?>

<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-bold flex items-center gap-2">
                <i class="fa-solid fa-receipt text-amber-500"></i> Comanda & Fechamento de Pagamento
            </h2>
            <p class="text-zinc-400 text-sm">Adicione serviços ou produtos extras, confira o valor e selecione a forma de pagamento.</p>
        </div>
        <a href="agendamentos.php" class="text-xs text-zinc-400 hover:text-white bg-zinc-800 border border-zinc-700 px-3 py-2 rounded-xl transition">
            <i class="fa-solid fa-arrow-left mr-1"></i> Voltar
        </a>
    </div>

    <?php if ($mensagemSucesso): ?>
        <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs p-3.5 rounded-xl">
            <i class="fa-solid fa-circle-check mr-1.5"></i> <?= htmlspecialchars($mensagemSucesso) ?>
        </div>
    <?php endif; ?>

    <?php if ($mensagemErro): ?>
        <div class="bg-red-500/10 border border-red-500/30 text-red-400 text-xs p-3.5 rounded-xl">
            <i class="fa-solid fa-triangle-exclamation mr-1.5"></i> <?= htmlspecialchars($mensagemErro) ?>
        </div>
    <?php endif; ?>

    <!-- CARD DO CLIENTE & ATENDIMENTO -->
    <div class="bg-zinc-900 border border-zinc-800 p-5 rounded-2xl flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <span class="text-[11px] text-zinc-500 uppercase tracking-wider block">Dados do Cliente</span>
            <div class="text-lg font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-user text-amber-500 text-sm"></i>
                <?= htmlspecialchars($ag['cliente_nome']) ?>
            </div>
            <div class="text-xs text-zinc-400 mt-0.5">
                <?= htmlspecialchars($ag['cliente_telefone']) ?> • Profissional: <strong class="text-amber-400"><?= htmlspecialchars($ag['prof_nome'] ?? 'Qualquer Barbeiro') ?></strong>
            </div>
        </div>
        <div class="text-left sm:text-right">
            <span class="text-[11px] text-zinc-500 uppercase tracking-wider block">Horário Agendado</span>
            <div class="text-sm font-bold text-zinc-200">
                <?= date('d/m/Y', strtotime($ag['data_agendamento'])) ?> às <?= substr($ag['horario'], 0, 5) ?>
            </div>
            <span class="text-xs uppercase tracking-wider px-2 py-0.5 rounded-full border bg-amber-950 text-amber-400 border-amber-800 mt-1 inline-block">
                <?= ucfirst($ag['status']) ?>
            </span>
        </div>
    </div>

    <!-- SEÇÃO DE ADICIONAR ITENS EXTRAS (SERVIÇO OU PRODUTO) -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        
        <!-- Adicionar Serviço Extra -->
        <div class="bg-zinc-900 border border-zinc-800 p-5 rounded-2xl space-y-3">
            <div class="flex items-center gap-2 text-sm font-bold text-white">
                <i class="fa-solid fa-scissors text-amber-500"></i> Adicionar Mais Algum Serviço
            </div>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="tipo_item" value="servico">
                <div>
                    <label class="block text-[11px] text-zinc-400 mb-1">Selecione o Serviço Extra</label>
                    <select name="item_id" required class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                        <option value="">Escolha um serviço...</option>
                        <?php foreach ($todosServicos as $s): ?>
                            <option value="<?= $s['id'] ?>">
                                <?= htmlspecialchars($s['nome']) ?> • R$ <?= number_format($s['preco'], 2, ',', '.') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="adicionar_item_extra" class="w-full bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 text-white font-semibold py-2 rounded-xl text-xs transition flex items-center justify-center gap-1.5">
                    <i class="fa-solid fa-plus text-amber-500"></i> Incluir Serviço na Comanda
                </button>
            </form>
        </div>

        <!-- Adicionar Produto Consumido/Comprado -->
        <div class="bg-zinc-900 border border-zinc-800 p-5 rounded-2xl space-y-3">
            <div class="flex items-center gap-2 text-sm font-bold text-white">
                <i class="fa-solid fa-bottle-water text-amber-500"></i> Adicionar Produto / Bebida
            </div>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="tipo_item" value="produto">
                <div class="grid grid-cols-3 gap-2">
                    <div class="col-span-2">
                        <label class="block text-[11px] text-zinc-400 mb-1">Selecione o Produto</label>
                        <select name="item_id" required class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                            <option value="">Escolha um produto...</option>
                            <?php foreach ($todosProdutos as $p): ?>
                                <option value="<?= $p['id'] ?>">
                                    <?= htmlspecialchars($p['nome']) ?> (Estoque: <?= $p['quantidade_estoque'] ?>) • R$ <?= number_format($p['preco_venda'], 2, ',', '.') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] text-zinc-400 mb-1">Qtd</label>
                        <input type="number" name="quantidade" value="1" min="1" required class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white text-center focus:outline-none focus:border-amber-500">
                    </div>
                </div>
                <button type="submit" name="adicionar_item_extra" class="w-full bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 text-white font-semibold py-2 rounded-xl text-xs transition flex items-center justify-center gap-1.5">
                    <i class="fa-solid fa-plus text-amber-500"></i> Incluir Produto na Comanda
                </button>
            </form>
        </div>

    </div>

    <!-- TABELA DA COMANDA COM TODOS OS ITENS -->
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden">
        <div class="p-4 border-b border-zinc-800 flex justify-between items-center">
            <h3 class="font-bold text-white text-sm">Itens da Comanda</h3>
            <span class="text-xs text-zinc-400">Serviço principal + extras adicionados</span>
        </div>

        <table class="w-full text-left border-collapse text-xs">
            <thead>
                <tr class="border-b border-zinc-800 text-zinc-400 uppercase tracking-wider">
                    <th class="p-3.5">Item / Descrição</th>
                    <th class="p-3.5">Tipo</th>
                    <th class="p-3.5 text-center">Qtd</th>
                    <th class="p-3.5">Unitário</th>
                    <th class="p-3.5">Subtotal</th>
                    <th class="p-3.5 text-right">Ação</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-800">
                <!-- Item Principal do Agendamento -->
                <tr class="bg-zinc-850/30">
                    <td class="p-3.5 font-bold text-white">
                        <i class="fa-solid fa-star text-amber-500 mr-1.5"></i> <?= htmlspecialchars($ag['servico_nome']) ?>
                    </td>
                    <td class="p-3.5 text-zinc-400">
                        <?= $ehSessaoPacotePago ? '<span class="text-emerald-400 font-bold">Sessão Pacote Pago (R$ 0,00)</span>' : 'Serviço Principal' ?>
                    </td>
                    <td class="p-3.5 text-center font-bold text-zinc-300">1</td>
                    <td class="p-3.5 text-zinc-300">R$ <?= number_format($precoServicoPrincipal, 2, ',', '.') ?></td>
                    <td class="p-3.5 font-bold <?= $ehSessaoPacotePago ? 'text-emerald-400' : 'text-amber-400' ?>">
                        R$ <?= number_format($precoServicoPrincipal, 2, ',', '.') ?>
                    </td>
                    <td class="p-3.5 text-right text-zinc-500 italic">Padrão</td>
                </tr>

                <!-- Itens Extras Adicionados -->
                <?php foreach ($itensExtras as $extra): ?>
                <tr class="hover:bg-zinc-800/30 transition">
                    <td class="p-3.5 font-medium text-white"><?= htmlspecialchars($extra['nome_item']) ?></td>
                    <td class="p-3.5">
                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold <?= $extra['tipo'] === 'produto' ? 'bg-blue-950 text-blue-400 border border-blue-800' : 'bg-purple-950 text-purple-400 border border-purple-800' ?>">
                            <?= ucfirst($extra['tipo']) ?>
                        </span>
                    </td>
                    <td class="p-3.5 text-center text-zinc-300"><?= $extra['quantidade'] ?></td>
                    <td class="p-3.5 text-zinc-300">R$ <?= number_format($extra['preco_unitario'], 2, ',', '.') ?></td>
                    <td class="p-3.5 font-bold text-amber-400">R$ <?= number_format($extra['subtotal'], 2, ',', '.') ?></td>
                    <td class="p-3.5 text-right">
                        <a href="checkout.php?id=<?= $agendamentoId ?>&remover_item=<?= $extra['id'] ?>" onclick="return confirm('Deseja remover este item da comanda?');" class="text-zinc-500 hover:text-red-400 transition" title="Remover Item">
                            <i class="fa-solid fa-trash"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

                        <!-- TOTAL GERAL, DESCONTO E FORMULÁRIO DE PAGAMENTO -->
        <div class="p-6 bg-zinc-950 border-t border-zinc-800 rounded-b-2xl">
            <form method="POST" class="flex flex-col xl:flex-row items-stretch xl:items-end justify-between gap-6">
                <input type="hidden" name="valor_total_final" id="campoValorTotalFinal" value="<?= $totalComanda ?>">
                <input type="hidden" name="desconto_tipo" id="campoDescontoTipo" value="reais">

                <!-- Lado Esquerdo: Valores e Totais -->
                <div class="space-y-1.5 shrink-0">
                    <div class="flex items-center gap-3 text-xs">
                        <span class="text-zinc-400">Subtotal: <strong class="text-white font-semibold">R$ <?= number_format($totalComanda, 2, ',', '.') ?></strong></span>

                    </div>
                    <span class="text-[11px] font-bold text-zinc-500 uppercase tracking-widest block">Total a Pagar</span>
                    <div id="displayTotalFinal" class="text-3xl sm:text-4xl font-extrabold text-emerald-400 tracking-tight leading-none">
                        R$ <?= number_format($totalComanda, 2, ',', '.') ?>
                    </div>
                </div>

                <!-- Lado Direito: Controles com Dois Campos de Desconto Sincronizados -->
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end w-full xl:w-auto">
                    <!-- Campo Desconto em R$ -->
                    <div>
                        <label class="block text-xs font-semibold text-zinc-300 mb-1.5">Desconto (R$)</label>
                        <div class="relative flex items-center">
                            <span class="absolute left-3 text-xs font-bold text-amber-400 pointer-events-none">R$</span>
                            <input type="text" inputmode="decimal" name="desconto_valor" id="inputDescReais" value="0,00" 
                                   oninput="aoDigitarReais(this.value)" 
                                   class="w-full bg-zinc-800 border border-zinc-700 rounded-xl py-2.5 pl-9 pr-3 text-xs text-white font-bold focus:outline-none focus:border-amber-500 text-right transition" 
                                   placeholder="0,00">
                        </div>
                    </div>

                    <!-- Campo Desconto em % -->
                    <div>
                        <label class="block text-xs font-semibold text-zinc-300 mb-1.5">Desconto (%)</label>
                        <div class="relative flex items-center">
                            <input type="text" inputmode="decimal" id="inputDescPorcentagem" value="0,00" 
                                   oninput="aoDigitarPorcentagem(this.value)" 
                                   class="w-full bg-zinc-800 border border-zinc-700 rounded-xl py-2.5 pl-3 pr-8 text-xs text-amber-400 font-bold focus:outline-none focus:border-amber-500 text-right transition" 
                                   placeholder="0,00">
                            <span class="absolute right-3 text-xs font-bold text-zinc-400 pointer-events-none">%</span>
                        </div>
                    </div>

                    <!-- Forma de Pagamento -->
                    <div>
                        <label class="block text-xs font-semibold text-zinc-300 mb-1.5">Forma de Pagamento</label>
                        <select name="forma_pagamento" required 
                                class="w-full bg-zinc-800 border border-zinc-700 rounded-xl py-2.5 px-3 text-xs text-white focus:outline-none focus:border-amber-500 font-medium">
                            <option value="pix">📱 PIX</option>
                            <option value="cartao_credito">💳 Cartão de Crédito</option>
                            <option value="cartao_debito">💳 Cartão de Débito</option>
                            <option value="dinheiro">💵 Dinheiro em Espécie</option>
                        </select>
                    </div>

                    <!-- Botão Confirmar -->
                    <div>
                        <button type="submit" name="confirmar_pagamento" 
                                class="w-full bg-emerald-500 hover:bg-emerald-600 active:scale-[0.98] text-zinc-950 font-bold py-2.5 px-5 rounded-xl text-xs transition flex items-center justify-center gap-2 shadow-lg shadow-emerald-500/20 whitespace-nowrap">
                            <i class="fa-solid fa-circle-check text-sm"></i> Confirmar & Concluir
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <script>
        const subtotalBase = <?= (float)$totalComanda ?>;

        function aoDigitarReais(valorStr) {
            document.getElementById('campoDescontoTipo').value = 'reais';
            let reais = parseFloat(valorStr.replace(',', '.'));
            if (isNaN(reais) || reais < 0) reais = 0;

            if (reais > subtotalBase) {
                reais = subtotalBase;
                document.getElementById('inputDescReais').value = reais.toFixed(2).replace('.', ',');
            }

            // Calcula a porcentagem correspondente
            let pct = 0;
            if (subtotalBase > 0) {
                pct = (reais / subtotalBase) * 100;
            }
            document.getElementById('inputDescPorcentagem').value = pct.toFixed(2).replace('.', ',');

            atualizarTotaisFinais(reais);
        }

        function aoDigitarPorcentagem(valorStr) {
            document.getElementById('campoDescontoTipo').value = 'reais';
            let pct = parseFloat(valorStr.replace(',', '.'));
            if (isNaN(pct) || pct < 0) pct = 0;

            if (pct > 100) {
                pct = 100;
                document.getElementById('inputDescPorcentagem').value = '100,00';
            }

            // Calcula o valor em reais correspondente
            let reais = (subtotalBase * (pct / 100));
            if (reais > subtotalBase) reais = subtotalBase;

            document.getElementById('inputDescReais').value = reais.toFixed(2).replace('.', ',');

            atualizarTotaisFinais(reais);
        }

        function atualizarTotaisFinais(valorAbatido) {
            const totalFinal = Math.max(0, subtotalBase - valorAbatido);



            document.getElementById('displayTotalFinal').textContent = 'R$ ' + totalFinal.toFixed(2).replace('.', ',');
            document.getElementById('campoValorTotalFinal').value = totalFinal.toFixed(2);
        }
        </script>
    </div>
</div>
</main>
</body>
</html>