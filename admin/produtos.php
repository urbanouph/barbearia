<?php
require_once '../config/database.php';
verificarAutenticacao();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
$csrfValido = $_SERVER['REQUEST_METHOD'] !== 'POST' || hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''));

$mensagemSucesso = '';
$mensagemErro = '';
if (!$csrfValido) {
    $mensagemErro = 'Sua sessão expirou. Atualize a página e tente novamente.';
}

if (isset($_GET['sucesso_prod'])) {
    $mensagemSucesso = "Produto cadastrado com sucesso!";
} elseif (isset($_GET['sucesso_edicao'])) {
    $mensagemSucesso = "Produto atualizado com sucesso!";
} elseif (isset($_GET['sucesso_estoque'])) {
    $mensagemSucesso = "Estoque atualizado!";
} elseif (isset($_GET['sucesso_venda'])) {
    $mensagemSucesso = "Venda concluída com sucesso!";
}

// 1. Cadastrar Novo Produto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $csrfValido && isset($_POST['cadastrar_produto'])) {
    $nome = trim((string)($_POST['nome'] ?? ''));
    $precoCusto = (float)($_POST['preco_custo'] ?? 0);
    $precoVenda = (float)($_POST['preco_venda'] ?? 0);
    $comissao = (float)($_POST['comissao_percentual'] ?? 10.00);
    $estoque = (int)($_POST['quantidade_estoque'] ?? 0);
    $estoqueMinimo = (int)($_POST['estoque_minimo'] ?? 5);
    $mostrarNoAgendamento = isset($_POST['mostrar_no_agendamento']) ? 1 : 0;

    if ($nome !== '' && strlen($nome) <= 100 && $precoCusto >= 0 && $precoVenda > 0 && $comissao >= 0 && $comissao <= 100 && $estoque >= 0 && $estoqueMinimo >= 0) {
        $stmt = $pdo->prepare("
            INSERT INTO produtos (nome, preco_custo, preco_venda, comissao_percentual, quantidade_estoque, estoque_minimo, mostrar_no_agendamento) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$nome, $precoCusto, $precoVenda, $comissao, $estoque, $estoqueMinimo, $mostrarNoAgendamento]);
        header('Location: produtos.php?sucesso_prod=1');
        exit;
    } else {
        $mensagemErro = "Informe um nome de até 100 caracteres, preços válidos e quantidades não negativas.";
    }
}

// 2. Editar nome e disponibilidade do produto no agendamento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $csrfValido && isset($_POST['editar_produto'])) {
    $produtoId = (int)($_POST['produto_id'] ?? 0);
    $nome = trim((string)($_POST['nome'] ?? ''));
    $mostrar = isset($_POST['mostrar_no_agendamento']) ? 1 : 0;
    if ($produtoId > 0 && $nome !== '' && strlen($nome) <= 100) {
        $stmt = $pdo->prepare("UPDATE produtos SET nome = ?, mostrar_no_agendamento = ? WHERE id = ? AND status = 'ativo'");
        $stmt->execute([$nome, $mostrar, $produtoId]);
        header('Location: produtos.php?sucesso_edicao=1');
        exit;
    }
    $mensagemErro = 'Informe um nome válido para o produto.';
}

// 3. Ajustar Estoque Rápido (+ ou -)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $csrfValido && isset($_POST['ajustar_estoque'])) {
    $produtoId = (int)$_POST['produto_id'];
    $novaQuantidade = (int)$_POST['nova_quantidade'];

    if ($novaQuantidade >= 0) {
        $stmt = $pdo->prepare("UPDATE produtos SET quantidade_estoque = ? WHERE id = ?");
        $stmt->execute([$novaQuantidade, $produtoId]);
        header('Location: produtos.php?sucesso_estoque=1');
        exit;
    }
}

// 3. Registrar Venda no Balcão (PDV) com Cliente e Vendedor (suporta anônimo)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $csrfValido && isset($_POST['registrar_venda'])) {
    $produtoId = (int)$_POST['venda_produto_id'];
    $quantidade = (int)$_POST['venda_quantidade'];
    $formaPagamento = $_POST['forma_pagamento'] ?? 'pix';
    if (!in_array($formaPagamento, ['dinheiro', 'pix', 'cartao_credito', 'cartao_debito'], true)) {
        $formaPagamento = 'pix';
    }
    
    // Vendedor: ID do profissional ou NULL se Anônimo
    $profissionalId = !empty($_POST['venda_profissional_id']) ? (int)$_POST['venda_profissional_id'] : null;

    // Cliente: ID do cliente ou NULL se Anônimo
    $clienteId = !empty($_POST['venda_cliente_id']) ? (int)$_POST['venda_cliente_id'] : null;

    if ($quantidade <= 0) {
        $mensagemErro = "Selecione uma quantidade maior que zero.";
    } else {
        // Busca produto para conferir estoque e preço
        $stmtProd = $pdo->prepare("SELECT * FROM produtos WHERE id = ? AND status = 'ativo'");
        $stmtProd->execute([$produtoId]);
        $prod = $stmtProd->fetch();

        if (!$prod) {
            $mensagemErro = "Produto não encontrado.";
        } elseif ($prod['quantidade_estoque'] < $quantidade) {
            $mensagemErro = "Estoque insuficiente! Disponível: {$prod['quantidade_estoque']} unidade(s).";
        } else {
            $totalVenda = $prod['preco_venda'] * $quantidade;

            $pdo->beginTransaction();
            try {
                // Registra venda com cliente_id e profissional_id
                $stmtVenda = $pdo->prepare("
                    INSERT INTO vendas_produtos (cliente_id, profissional_id, forma_pagamento, total) 
                    VALUES (?, ?, ?, ?)
                ");
                $stmtVenda->execute([$clienteId, $profissionalId, $formaPagamento, $totalVenda]);
                $vendaId = $pdo->lastInsertId();

                // Registra item da venda
                $stmtItem = $pdo->prepare("
                    INSERT INTO itens_venda_produtos (venda_id, produto_id, quantidade, preco_unitario, subtotal) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmtItem->execute([$vendaId, $produtoId, $quantidade, $prod['preco_venda'], $totalVenda]);

                // Baixa no estoque
                $stmtBaixa = $pdo->prepare("UPDATE produtos SET quantidade_estoque = quantidade_estoque - ? WHERE id = ?");
                $stmtBaixa->execute([$quantidade, $produtoId]);

                $pdo->commit();
                header('Location: produtos.php?sucesso_venda=1');
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $mensagemErro = "Erro ao registrar venda: " . $e->getMessage();
            }
        }
    }
}

// 4. Desativar produto por POST (não executar ações destrutivas via link GET)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $csrfValido && isset($_POST['desativar_produto'])) {
    $id = (int)($_POST['produto_id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE produtos SET status = 'inativo' WHERE id = ?");
        $stmt->execute([$id]);
    }
    header('Location: produtos.php');
    exit;
}

// Listagens
$produtos = $pdo->query("SELECT * FROM produtos WHERE status = 'ativo' ORDER BY nome ASC")->fetchAll();
$profissionais = $pdo->query("SELECT * FROM profissionais WHERE status = 'ativo' ORDER BY nome ASC")->fetchAll();
$clientes = $pdo->query("SELECT id, nome, telefone, cpf FROM clientes ORDER BY nome ASC")->fetchAll();

// Vendas Recentes com dados do Cliente e Vendedor
$vendasRecentes = $pdo->query("
    SELECT v.*, 
           p.nome as prof_nome, 
           c.nome as cliente_nome,
           pr.nome as produto_nome, 
           iv.quantidade, 
           iv.preco_unitario
    FROM vendas_produtos v
    JOIN itens_venda_produtos iv ON v.id = iv.venda_id
    JOIN produtos pr ON iv.produto_id = pr.id
    LEFT JOIN profissionais p ON v.profissional_id = p.id
    LEFT JOIN clientes c ON v.cliente_id = c.id
    ORDER BY v.data_venda DESC
    LIMIT 20
")->fetchAll();

$faturamentoProdutos = $pdo->query("SELECT COALESCE(SUM(total), 0) FROM vendas_produtos")->fetchColumn();
$produtosAlertaEstoque = $pdo->query("SELECT COUNT(*) FROM produtos WHERE status = 'ativo' AND quantidade_estoque <= estoque_minimo")->fetchColumn();

// Carrega o layout visual somente após processamento
require_once 'header.php';
?>

<div class="max-w-6xl mx-auto space-y-6">
    <div>
        <h2 class="text-2xl font-bold">Frente de Caixa (PDV) & Controle de Estoque</h2>
        <p class="text-zinc-400 text-sm">Registro de vendas rápidas com pesquisa inteligente de cliente e produto, controle de equipe e estoque.</p>
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

    <!-- CARDS DE RESUMO DO ESTOQUE & VENDAS -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-zinc-900 border border-zinc-800 p-5 rounded-2xl">
            <div class="flex items-center justify-between text-zinc-400 text-xs uppercase tracking-wider mb-2">
                <span>Total de Produtos</span>
                <i class="fa-solid fa-boxes-stacked text-amber-500 text-lg"></i>
            </div>
            <div class="text-2xl font-bold text-white"><?= count($produtos) ?></div>
            <span class="text-[11px] text-zinc-500 mt-1 block">Itens cadastrados no catálogo</span>
        </div>

        <div class="bg-zinc-900 border border-zinc-800 p-5 rounded-2xl">
            <div class="flex items-center justify-between text-zinc-400 text-xs uppercase tracking-wider mb-2">
                <span>Itens com Estoque Baixo</span>
                <i class="fa-solid fa-triangle-exclamation text-rose-500 text-lg"></i>
            </div>
            <div class="text-2xl font-bold <?= $produtosAlertaEstoque > 0 ? 'text-rose-400' : 'text-zinc-200' ?>">
                <?= $produtosAlertaEstoque ?>
            </div>
            <span class="text-[11px] text-zinc-500 mt-1 block">Abaixo do limite de segurança</span>
        </div>

        <div class="bg-zinc-900 border border-zinc-800 p-5 rounded-2xl">
            <div class="flex items-center justify-between text-zinc-400 text-xs uppercase tracking-wider mb-2">
                <span>Faturamento em Produtos</span>
                <i class="fa-solid fa-cash-register text-emerald-400 text-lg"></i>
            </div>
            <div class="text-2xl font-bold text-emerald-400">R$ <?= number_format($faturamentoProdutos, 2, ',', '.') ?></div>
            <span class="text-[11px] text-zinc-500 mt-1 block">Receita obtida no balcão</span>
        </div>
    </div>

    <!-- SEÇÃO 1: PDV COM BUSCA EM TEMPO REAL PARA CLIENTE E PRODUTO -->
    <div class="bg-zinc-900 border border-zinc-800 p-6 rounded-2xl space-y-5">
        <div class="flex items-center gap-2.5 pb-3 border-b border-zinc-800">
            <i class="fa-solid fa-cart-shopping text-amber-500 text-xl"></i>
            <div>
                <h3 class="font-bold text-lg text-white">Registrar Venda no Balcão (PDV)</h3>
                <p class="text-xs text-zinc-400">Busque o cliente ou marque como anônimo, localize o produto e selecione quem vendeu.</p>
            </div>
        </div>

        <form method="POST" id="formVendaPdv" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                
                <!-- BUSCA E SELEÇÃO DE CLIENTE (COM OPÇÃO ANÔNIMO) -->
                <div class="bg-zinc-800/40 border border-zinc-800 p-4 rounded-xl space-y-2.5">
                    <div class="flex items-center justify-between">
                        <label class="block text-xs font-semibold text-zinc-300">
                            <i class="fa-solid fa-user text-amber-500 mr-1"></i> Cliente Comprador
                        </label>
                        <span id="labelClienteStatus" class="text-[10px] text-amber-400 font-bold bg-amber-500/10 px-2 py-0.5 rounded border border-amber-500/20">
                            Cliente Anônimo
                        </span>
                    </div>

                    <div class="relative">
                        <input type="text" id="buscaClienteInput" placeholder="🔍 Digite para pesquisar cliente por nome ou telefone..." 
                               class="w-full bg-zinc-800 border border-zinc-700 rounded-xl py-2 px-3 text-xs text-white placeholder-zinc-500 focus:outline-none focus:border-amber-500">
                    </div>

                    <select name="venda_cliente_id" id="selectCliente" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                        <option value="" selected>👤 Cliente Anônimo / Venda Avulsa</option>
                        <?php foreach ($clientes as $c): ?>
                            <option value="<?= $c['id'] ?>" data-texto="<?= strtolower(htmlspecialchars($c['nome'] . ' ' . $c['telefone'] . ' ' . $c['cpf'])) ?>">
                                <?= htmlspecialchars($c['nome']) ?> <?= $c['telefone'] ? '• ' . htmlspecialchars($c['telefone']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- BUSCA E SELEÇÃO DE PRODUTO -->
                <div class="bg-zinc-800/40 border border-zinc-800 p-4 rounded-xl space-y-2.5">
                    <div class="flex items-center justify-between">
                        <label class="block text-xs font-semibold text-zinc-300">
                            <i class="fa-solid fa-barcode text-amber-500 mr-1"></i> Produto
                        </label>
                        <span id="infoPrecoProduto" class="text-[10px] text-emerald-400 font-bold"></span>
                    </div>

                    <div class="relative">
                        <input type="text" id="buscaProdutoInput" placeholder="🔍 Digite para pesquisar produto..." 
                               class="w-full bg-zinc-800 border border-zinc-700 rounded-xl py-2 px-3 text-xs text-white placeholder-zinc-500 focus:outline-none focus:border-amber-500">
                    </div>

                    <select name="venda_produto_id" id="selectProduto" required class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                        <option value="">Selecione o produto...</option>
                        <?php foreach ($produtos as $p): ?>
                            <option value="<?= $p['id'] ?>" 
                                    data-preco="<?= $p['preco_venda'] ?>"
                                    data-estoque="<?= $p['quantidade_estoque'] ?>"
                                    data-texto="<?= strtolower(htmlspecialchars($p['nome'])) ?>"
                                    <?= $p['quantidade_estoque'] <= 0 ? 'disabled' : '' ?>>
                                <?= htmlspecialchars($p['nome']) ?> (Estoque: <?= $p['quantidade_estoque'] ?>) - R$ <?= number_format($p['preco_venda'], 2, ',', '.') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

            </div>

            <!-- LINHA DE QUANTIDADE, VENDEDOR, PAGAMENTO E BOTÃO -->
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3.5 pt-2">
                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Quantidade</label>
                    <input type="number" id="vendaQuantidade" name="venda_quantidade" value="1" min="1" required 
                           class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                </div>

                <!-- QUEM VENDEU (PROFISSIONAL OU ANÔNIMO / CASA) -->
                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Quem Vendeu?</label>
                    <select name="venda_profissional_id" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                        <option value="">👤 Anônimo / Casa (Sem Comissão)</option>
                        <?php foreach ($profissionais as $prof): ?>
                            <option value="<?= $prof['id'] ?>"><?= htmlspecialchars($prof['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Forma de Pagamento</label>
                    <select name="forma_pagamento" required class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                        <option value="pix">PIX</option>
                        <option value="cartao_credito">Cartão de Crédito</option>
                        <option value="cartao_debito">Cartão de Débito</option>
                        <option value="dinheiro">Dinheiro</option>
                    </select>
                </div>

                <div class="flex items-end">
                    <button type="submit" name="registrar_venda" 
                            class="w-full bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold py-2.5 px-4 rounded-xl text-xs transition flex items-center justify-center gap-1.5">
                        <i class="fa-solid fa-check"></i> Concluir Venda (PDV)
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- SEÇÃO 2: CADASTRAR NOVO PRODUTO -->
    <div class="bg-zinc-900 border border-zinc-800 p-6 rounded-2xl space-y-4">
        <div class="flex items-center gap-2.5 pb-3 border-b border-zinc-800">
            <i class="fa-solid fa-box-open text-amber-500 text-xl"></i>
            <div>
                <h3 class="font-bold text-lg text-white">Cadastrar Novo Produto no Estoque</h3>
                <p class="text-xs text-zinc-400">Insira pomadas, óleos, lâminas ou bebidas vendidas no local.</p>
            </div>
        </div>

        <form method="POST" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3.5">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <div class="sm:col-span-2">
                <label class="block text-xs font-semibold text-zinc-400 mb-1">Nome do Produto</label>
                <input type="text" name="nome" placeholder="Ex: Pomada Efeito Matte 80g" required 
                       class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-400 mb-1">Preço Custo (R$)</label>
                <input type="number" step="0.01" name="preco_custo" placeholder="15.00" value="0.00" required 
                       class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-400 mb-1">Preço Venda (R$)</label>
                <input type="number" step="0.01" name="preco_venda" placeholder="35.00" required 
                       class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-400 mb-1">Comissão Barbeiro (%)</label>
                <input type="number" step="0.5" name="comissao_percentual" value="10" min="0" max="100" required 
                       class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500" placeholder="Ex: 10">
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-400 mb-1">Qtd Inicial</label>
                <input type="number" name="quantidade_estoque" value="10" min="0" required 
                       class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
            </div>

            <div class="sm:col-span-2 md:col-span-5">
                <label class="inline-flex items-center gap-2 text-xs text-zinc-300">
                    <input type="checkbox" name="mostrar_no_agendamento" value="1" class="accent-amber-500 w-4 h-4">
                    Mostrar este produto como opcional no agendamento (somente com estoque disponível)
                </label>
            </div>
            <div class="md:col-span-5 flex justify-end">
                <button type="submit" name="cadastrar_produto" 
                        class="bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 text-white font-bold py-2.5 px-6 rounded-xl text-xs transition flex items-center gap-2">
                    <i class="fa-solid fa-plus"></i> Salvar Produto
                </button>
            </div>
        </form>
    </div>

    <!-- SEÇÃO 3: CONTROLE DE ESTOQUE -->
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-zinc-800 flex justify-between items-center">
            <h3 class="font-bold text-white text-base">Controle de Estoque dos Produtos</h3>
            <span class="text-xs text-zinc-400"><?= count($produtos) ?> itens ativos</span>
        </div>

        <div class="overflow-x-auto overflow-y-hidden w-full scroll-suave">
            <table class="w-full text-left border-collapse min-w-[900px]">
                <thead>
                    <tr class="border-b border-zinc-800 text-zinc-400 text-xs uppercase tracking-wider">
                        <th class="p-3.5">Produto</th>
                        <th class="p-3.5">Custo</th>
                        <th class="p-3.5">Venda</th>
                        <th class="p-3.5">Comissão (%)</th>
                        <th class="p-3.5">Estoque Atual</th>
                        <th class="p-3.5">Situação</th>
                        <th class="p-3.5 text-right">Ajuste Rápido / Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800 text-xs">
                    <?php if (empty($produtos)): ?>
                        <tr><td colspan="7" class="p-6 text-center text-zinc-500">Nenhum produto cadastrado.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($produtos as $p): 
                        $alerta = $p['quantidade_estoque'] <= $p['estoque_minimo'];
                    ?>
                    <tr class="hover:bg-zinc-800/30 transition">
                        <td class="p-3.5">
                            <form method="POST" class="flex min-w-[230px] items-center gap-2">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="produto_id" value="<?= (int)$p['id'] ?>">
                                <div class="min-w-0 flex-1 space-y-1.5">
                                    <input type="text" name="nome" value="<?= htmlspecialchars($p['nome'], ENT_QUOTES, 'UTF-8') ?>" maxlength="100" required aria-label="Nome do produto" class="w-full bg-zinc-800 border border-zinc-700 rounded-lg px-2 py-1.5 text-xs text-white">
                                    <label class="flex items-center gap-1.5 text-[10px] text-zinc-400">
                                        <input type="checkbox" name="mostrar_no_agendamento" value="1" <?= !empty($p['mostrar_no_agendamento']) ? 'checked' : '' ?> class="accent-amber-500">
                                        Mostrar no agendamento
                                    </label>
                                </div>
                                <button type="submit" name="editar_produto" value="1" title="Salvar nome e exibição" class="bg-zinc-800 hover:bg-zinc-700 text-amber-400 p-2 rounded-lg border border-zinc-700">
                                    <i class="fa-solid fa-floppy-disk"></i>
                                </button>
                            </form>
                        </td>
                        <td class="p-3.5 text-zinc-400">R$ <?= number_format($p['preco_custo'], 2, ',', '.') ?></td>
                        <td class="p-3.5 font-semibold text-amber-400">R$ <?= number_format($p['preco_venda'], 2, ',', '.') ?></td>
                        <td class="p-3.5 text-emerald-400 font-bold"><?= number_format($p['comissao_percentual'] ?? 10, 1, ',', '.') ?>%</td>
                        <td class="p-3.5 font-bold text-white">
                            <span class="<?= $alerta ? 'text-rose-400 font-extrabold' : 'text-zinc-200' ?>">
                                <?= $p['quantidade_estoque'] ?> un
                            </span>
                        </td>
                        <td class="p-3.5">
                            <?php if ($p['quantidade_estoque'] <= 0): ?>
                                <span class="bg-rose-950 text-rose-400 border border-rose-800 px-2 py-0.5 rounded-full text-[10px] uppercase font-bold">Esgotado</span>
                            <?php elseif ($alerta): ?>
                                <span class="bg-amber-950 text-amber-400 border border-amber-800 px-2 py-0.5 rounded-full text-[10px] uppercase font-bold">Estoque Baixo</span>
                            <?php else: ?>
                                <span class="bg-emerald-950 text-emerald-400 border border-emerald-800 px-2 py-0.5 rounded-full text-[10px] uppercase font-bold">Normal</span>
                            <?php endif; ?>
                        </td>
                        <td class="p-3.5 text-right">
                            <form method="POST" class="inline-flex items-center gap-1.5">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="produto_id" value="<?= (int)$p['id'] ?>">
                                <input type="number" name="nova_quantidade" value="<?= $p['quantidade_estoque'] ?>" min="0" 
                                       class="w-16 bg-zinc-800 border border-zinc-700 rounded-lg p-1 text-center text-xs text-white">
                                <button type="submit" name="ajustar_estoque" class="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 p-1.5 rounded-lg border border-zinc-700" title="Atualizar Estoque">
                                    <i class="fa-solid fa-floppy-disk"></i>
                                </button>
                                <button type="submit" name="desativar_produto" value="1" onclick="return confirm('Deseja desativar este produto?');"
                                   class="text-zinc-500 hover:text-red-400 p-1.5 rounded-lg ml-1" title="Desativar Produto">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- SEÇÃO 4: HISTÓRICO DE VENDAS RECENTES -->
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden">
        <div class="p-4 sm:p-5 border-b border-zinc-800 flex justify-between items-center">
            <h3 class="font-bold text-white text-base">Últimas Vendas Realizadas</h3>
            <span class="text-xs text-zinc-400">Histórico de saídas de mercadorias no balcão</span>
        </div>

        <div class="overflow-x-auto overflow-y-hidden w-full scroll-suave">
            <table class="w-full text-left border-collapse min-w-[750px]">
                <thead>
                    <tr class="border-b border-zinc-800 text-zinc-400 text-xs uppercase tracking-wider">
                        <th class="p-3.5">Data/Hora</th>
                        <th class="p-3.5">Cliente</th>
                        <th class="p-3.5">Produto</th>
                        <th class="p-3.5">Qtd</th>
                        <th class="p-3.5">Total</th>
                        <th class="p-3.5">Pagamento</th>
                        <th class="p-3.5">Vendedor</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800 text-xs">
                    <?php if (empty($vendasRecentes)): ?>
                        <tr><td colspan="7" class="p-6 text-center text-zinc-500">Nenhuma venda registrada ainda.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($vendasRecentes as $v): ?>
                    <tr class="hover:bg-zinc-800/30 transition">
                        <td class="p-3.5 text-zinc-300"><?= date('d/m/Y H:i', strtotime($v['data_venda'])) ?></td>
                        <td class="p-3.5 whitespace-nowrap">
                            <?php if ($v['cliente_nome']): ?>
                                <span class="font-semibold text-white flex items-center gap-1">
                                    <i class="fa-solid fa-user text-amber-500 text-[10px]"></i>
                                    <?= htmlspecialchars($v['cliente_nome']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-zinc-500 italic">Cliente Anônimo</span>
                            <?php endif; ?>
                        </td>
                        <td class="p-3.5 font-bold text-white"><?= htmlspecialchars($v['produto_nome']) ?></td>
                        <td class="p-3.5 text-zinc-300"><?= $v['quantidade'] ?> un</td>
                        <td class="p-3.5 font-bold text-emerald-400">R$ <?= number_format($v['total'], 2, ',', '.') ?></td>
                        <td class="p-3.5 uppercase text-zinc-400"><?= str_replace('_', ' ', $v['forma_pagamento']) ?></td>
                        <td class="p-3.5 whitespace-nowrap">
                            <?php if ($v['prof_nome']): ?>
                                <span class="text-amber-400 font-semibold flex items-center gap-1">
                                    <i class="fa-solid fa-user-tie text-[10px]"></i>
                                    <?= htmlspecialchars($v['prof_nome']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-zinc-500">Anônimo / Casa</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- SCRIPTS DE BUSCA DINÂMICA EM TEMPO REAL PARA CLIENTE E PRODUTO -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    // 1. Busca dinâmica de Cliente
    const buscaClienteInput = document.getElementById('buscaClienteInput');
    const selectCliente = document.getElementById('selectCliente');
    const labelClienteStatus = document.getElementById('labelClienteStatus');

    if (buscaClienteInput && selectCliente) {
        buscaClienteInput.addEventListener('input', (e) => {
            const termo = e.target.value.toLowerCase().trim();
            const options = selectCliente.querySelectorAll('option');

            let encontrou = false;
            options.forEach((opt, index) => {
                if (index === 0) return; // Opção Anônimo sempre disponível
                const texto = opt.getAttribute('data-texto') || '';
                if (texto.includes(termo)) {
                    opt.style.display = '';
                    if (!encontrou && termo !== '') {
                        opt.selected = true;
                        encontrou = true;
                    }
                } else {
                    opt.style.display = 'none';
                }
            });

            if (termo === '') {
                options[0].selected = true;
            }
            atualizarBadgeCliente();
        });

        selectCliente.addEventListener('change', atualizarBadgeCliente);

        function atualizarBadgeCliente() {
            if (selectCliente.value === '') {
                labelClienteStatus.textContent = 'Cliente Anônimo';
                labelClienteStatus.className = 'text-[10px] text-amber-400 font-bold bg-amber-500/10 px-2 py-0.5 rounded border border-amber-500/20';
            } else {
                const optSel = selectCliente.options[selectCliente.selectedIndex];
                labelClienteStatus.textContent = 'Cliente Identificado';
                labelClienteStatus.className = 'text-[10px] text-emerald-400 font-bold bg-emerald-500/10 px-2 py-0.5 rounded border border-emerald-500/20';
            }
        }
    }

    // 2. Busca dinâmica de Produto
    const buscaProdutoInput = document.getElementById('buscaProdutoInput');
    const selectProduto = document.getElementById('selectProduto');
    const infoPrecoProduto = document.getElementById('infoPrecoProduto');
    const vendaQuantidade = document.getElementById('vendaQuantidade');

    if (buscaProdutoInput && selectProduto) {
        buscaProdutoInput.addEventListener('input', (e) => {
            const termo = e.target.value.toLowerCase().trim();
            const options = selectProduto.querySelectorAll('option');

            let encontrou = false;
            options.forEach((opt, index) => {
                if (index === 0) return;
                const texto = opt.getAttribute('data-texto') || '';
                if (texto.includes(termo)) {
                    opt.style.display = '';
                    if (!encontrou && termo !== '' && !opt.disabled) {
                        opt.selected = true;
                        encontrou = true;
                    }
                } else {
                    opt.style.display = 'none';
                }
            });

            atualizarSubtotal();
        });

        selectProduto.addEventListener('change', atualizarSubtotal);
        vendaQuantidade.addEventListener('input', atualizarSubtotal);

        function atualizarSubtotal() {
            const optSel = selectProduto.options[selectProduto.selectedIndex];
            if (optSel && optSel.value) {
                const preco = parseFloat(optSel.getAttribute('data-preco')) || 0;
                const qtd = parseInt(vendaQuantidade.value) || 1;
                const total = preco * qtd;
                infoPrecoProduto.textContent = `Total: R$ ${total.toFixed(2).replace('.', ',')}`;
            } else {
                infoPrecoProduto.textContent = '';
            }
        }
    }
});
</script>

</main>
</body>
</html>
