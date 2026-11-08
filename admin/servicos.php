<?php
require_once '../config/database.php';
verificarAutenticacao();

// Auto-migração: garante que as colunas descricao e imagem existam na tabela servicos
try {
    $pdo->exec("ALTER TABLE servicos ADD COLUMN IF NOT EXISTS descricao TEXT NULL AFTER nome");
    $pdo->exec("ALTER TABLE servicos ADD COLUMN IF NOT EXISTS imagem VARCHAR(255) NULL AFTER descricao");
    $pdo->exec("ALTER TABLE servicos ADD COLUMN IF NOT EXISTS incompativeis TEXT NULL AFTER eh_pacote");
} catch (Exception $e) {}

$mensagemSucesso = '';
$mensagemErro = '';

if (isset($_GET['sucesso_cad'])) $mensagemSucesso = "Serviço cadastrado com sucesso!";
if (isset($_GET['sucesso_alt'])) $mensagemSucesso = "Serviço atualizado com sucesso!";
if (isset($_GET['sucesso_del'])) $mensagemSucesso = "Serviço removido com sucesso!";

// Criar pasta de uploads para imagens de serviços se não existir
$diretorioUploads = '../assets/uploads/servicos/';
if (!is_dir($diretorioUploads)) {
    mkdir($diretorioUploads, 0777, true);
}

// Função auxiliar para salvar imagem em Base64 gerada pelo editor de recorte
function salvarImagemBase64($base64Data, $diretorioUploads) {
    if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
        $data = substr($base64Data, strpos($base64Data, ',') + 1);
        $type = strtolower($type[1]);
        if (!in_array($type, ['jpg', 'jpeg', 'gif', 'png', 'webp'])) {
            $type = 'jpg';
        }
        $data = base64_decode($data);
        if ($data !== false) {
            $nomeArquivo = 'servico_' . uniqid() . '.' . $type;
            if (file_put_contents($diretorioUploads . $nomeArquivo, $data)) {
                return 'assets/uploads/servicos/' . $nomeArquivo;
            }
        }
    }
    return null;
}

// 1. Cadastrar Novo Serviço ou Pacote
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cadastrar_servico'])) {
    $nome = trim($_POST['nome']);
    $descricao = trim($_POST['descricao'] ?? '');
    $preco = (float)str_replace(',', '.', $_POST['preco']);
    $duracao = (int)$_POST['duracao_minutos'];
    $comissao = (float)str_replace(',', '.', $_POST['comissao_percentual']);
    $ehPacote = !empty($_POST['eh_pacote']) ? 1 : 0;
    $incompativeisArr = isset($_POST['incompativeis']) && is_array($_POST['incompativeis']) 
        ? array_values(array_map('intval', $_POST['incompativeis'])) 
        : [];
    $incompativeisJson = !empty($incompativeisArr) ? json_encode($incompativeisArr) : null;
    $sessoesPacote = $ehPacote ? (int)($_POST['sessoes_pacote'] ?? 3) : null;
    $validadeDias = $ehPacote ? (int)($_POST['validade_dias'] ?? 30) : null;

    $caminhoImagem = null;

    // 1º Prioridade: Imagem recortada e centralizada pelo editor (Base64)
    if (!empty($_POST['imagem_base64'])) {
        $caminhoImagem = salvarImagemBase64($_POST['imagem_base64'], $diretorioUploads);
    }
    // 2º Upload tradicional de arquivo
    elseif (isset($_FILES['imagem']) && $_FILES['imagem']['error'] === UPLOAD_ERR_OK) {
        $extensao = strtolower(pathinfo($_FILES['imagem']['name'], PATHINFO_EXTENSION));
        $extensoesPermitidas = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        if (in_array($extensao, $extensoesPermitidas)) {
            $nomeArquivo = 'servico_' . uniqid() . '.' . $extensao;
            $destino = $diretorioUploads . $nomeArquivo;

            if (move_uploaded_file($_FILES['imagem']['tmp_name'], $destino)) {
                $caminhoImagem = 'assets/uploads/servicos/' . $nomeArquivo;
            }
        }
    }

    if (empty($nome) || $preco <= 0 || $duracao <= 0) {
        $mensagemErro = 'Preencha corretamente o nome, preço e duração do serviço.';
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO servicos (nome, descricao, imagem, preco, duracao_minutos, comissao_percentual, eh_pacote, sessoes_pacote, validade_dias, incompativeis, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ativo')
        ");
        $stmt->execute([$nome, $descricao, $caminhoImagem, $preco, $duracao, $comissao, $ehPacote, $sessoesPacote, $validadeDias, $incompativeisJson]);
        header("Location: servicos.php?sucesso_cad=1");
        exit;
    }
}

// 2. Editar Serviço Existente via Modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_servico'])) {
    $servicoId = (int)$_POST['editar_servico_id'];
    $nome = trim($_POST['editar_nome']);
    $descricao = trim($_POST['editar_descricao'] ?? '');
    $preco = (float)str_replace(',', '.', $_POST['editar_preco']);
    $duracao = (int)$_POST['editar_duracao_minutos'];
    $comissao = (float)str_replace(',', '.', $_POST['editar_comissao_percentual']);
    $ehPacote = !empty($_POST['editar_eh_pacote']) ? 1 : 0;
    $incompativeisArr = isset($_POST['editar_incompativeis']) && is_array($_POST['editar_incompativeis']) 
        ? array_values(array_map('intval', $_POST['editar_incompativeis'])) 
        : [];
    $incompativeisJson = !empty($incompativeisArr) ? json_encode($incompativeisArr) : null;
    $sessoesPacote = $ehPacote ? (int)($_POST['editar_sessoes_pacote'] ?? 3) : null;
    $validadeDias = $ehPacote ? (int)($_POST['editar_validade_dias'] ?? 30) : null;
    $status = $_POST['editar_status'] ?? 'ativo';

    // Buscar imagem atual
    $stmtAntigo = $pdo->prepare("SELECT imagem FROM servicos WHERE id = ?");
    $stmtAntigo->execute([$servicoId]);
    $imagemAtual = $stmtAntigo->fetchColumn();
    $caminhoImagem = $imagemAtual;

    // 1º Prioridade: Imagem recortada e centralizada pelo editor (Base64)
    if (!empty($_POST['editar_imagem_base64'])) {
        $novaImg = salvarImagemBase64($_POST['editar_imagem_base64'], $diretorioUploads);
        if ($novaImg) {
            $caminhoImagem = $novaImg;
        }
    }
    // 2º Upload tradicional de arquivo
    elseif (isset($_FILES['editar_imagem']) && $_FILES['editar_imagem']['error'] === UPLOAD_ERR_OK) {
        $extensao = strtolower(pathinfo($_FILES['editar_imagem']['name'], PATHINFO_EXTENSION));
        $extensoesPermitidas = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        if (in_array($extensao, $extensoesPermitidas)) {
            $nomeArquivo = 'servico_' . uniqid() . '.' . $extensao;
            $destino = $diretorioUploads . $nomeArquivo;

            if (move_uploaded_file($_FILES['editar_imagem']['tmp_name'], $destino)) {
                $caminhoImagem = 'assets/uploads/servicos/' . $nomeArquivo;
            }
        }
    }

    if (empty($nome) || $preco <= 0 || $duracao <= 0) {
        $mensagemErro = 'Preencha corretamente o nome, preço e duração do serviço na edição.';
    } else {
        $stmtUpdate = $pdo->prepare("
            UPDATE servicos 
            SET nome = ?, descricao = ?, imagem = ?, preco = ?, duracao_minutos = ?, comissao_percentual = ?, eh_pacote = ?, sessoes_pacote = ?, validade_dias = ?, incompativeis = ?, status = ? 
            WHERE id = ?
        ");
        $stmtUpdate->execute([$nome, $descricao, $caminhoImagem, $preco, $duracao, $comissao, $ehPacote, $sessoesPacote, $validadeDias, $incompativeisJson, $status, $servicoId]);
        header("Location: servicos.php?sucesso_alt=1");
        exit;
    }
}

// 3. Alternar Estado (Ativo / Inativo)
if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    $stmt = $pdo->prepare("UPDATE servicos SET status = IF(status = 'ativo', 'inativo', 'ativo') WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: servicos.php?sucesso_alt=1");
    exit;
}

// 4. Excluir Serviço
if (isset($_GET['excluir_servico'])) {
    $id = (int)$_GET['excluir_servico'];
    $stmt = $pdo->prepare("DELETE FROM servicos WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: servicos.php?sucesso_del=1");
    exit;
}

// Buscar apenas os serviços cadastrados
$servicos = $pdo->query("SELECT * FROM servicos ORDER BY eh_pacote DESC, status ASC, nome ASC")->fetchAll();

require_once 'header.php';
?>

<div class="max-w-6xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold flex items-center gap-2">
                <i class="fa-solid fa-list-check text-amber-500"></i> Catálogo de Serviços & Pacotes
            </h2>
            <p class="text-zinc-400 text-sm">Registe novos cortes, barbas e combos com imagem, enquadramento personalizado e descrição detalhada.</p>
        </div>
        <div class="flex gap-2">
            <a href="produtos.php" class="bg-zinc-800 hover:bg-zinc-700 text-zinc-300 font-bold px-3.5 py-2 rounded-xl text-xs transition border border-zinc-700 inline-flex items-center gap-1.5">
                <i class="fa-solid fa-boxes-stacked text-amber-500"></i> Produtos & Estoque
            </a>
        </div>
    </div>

    <?php if ($mensagemSucesso): ?>
        <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs p-3.5 rounded-xl flex items-center justify-between shadow">
            <span><i class="fa-solid fa-circle-check mr-2"></i> <?= htmlspecialchars($mensagemSucesso) ?></span>
            <a href="servicos.php" class="text-zinc-400 hover:text-white">&times;</a>
        </div>
    <?php endif; ?>

    <?php if ($mensagemErro): ?>
        <div class="bg-red-500/10 border border-red-500/30 text-red-400 text-xs p-3.5 rounded-xl flex items-center justify-between shadow">
            <span><i class="fa-solid fa-circle-exclamation mr-2"></i> <?= htmlspecialchars($mensagemErro) ?></span>
            <a href="servicos.php" class="text-zinc-400 hover:text-white">&times;</a>
        </div>
    <?php endif; ?>

    <!-- FORMULÁRIO DE CADASTRO COM DESCRIÇÃO E IMAGEM -->
    <div class="bg-zinc-900 border border-zinc-800 p-6 rounded-2xl shadow-xl space-y-4">
        <div class="flex items-center gap-2.5 pb-3 border-b border-zinc-800">
            <i class="fa-solid fa-scissors text-amber-500 text-xl"></i>
            <div>
                <h3 class="font-bold text-base text-white">Adicionar Serviço ou Pacote ao Catálogo</h3>
                <p class="text-xs text-zinc-400">Configure o nome, foto com recorte, descrição, preço, tempo de execução e comissão.</p>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <!-- Campo oculto para imagem recortada/centralizada -->
            <input type="hidden" name="imagem_base64" id="cadImagemBase64">

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Nome do Serviço / Pacote</label>
                    <input type="text" name="nome" placeholder="Ex: Corte Degradê Navalhado, Barba Terapia Premium" required
                           class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Preço Total (R$)</label>
                    <input type="text" inputmode="decimal" name="preco" placeholder="Ex: 50,00" required
                           class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white font-bold focus:outline-none focus:border-amber-500 text-right">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Duração do Atendimento</label>
                    <select name="duracao_minutos" required class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                        <option value="20">20 minutos</option>
                        <option value="30" selected>30 minutos</option>
                        <option value="40">40 minutos</option>
                        <option value="45">45 minutos</option>
                        <option value="60">60 minutos (1 hora)</option>
                        <option value="90">90 minutos (1h 30m)</option>
                    </select>
                </div>
            </div>

            <!-- Descrição e Imagem do Serviço -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">
                        <i class="fa-solid fa-align-left text-amber-500 mr-1"></i> Descrição do Serviço
                    </label>
                    <textarea name="descricao" rows="3" placeholder="Ex: Inclui lavagem com shampoo especial, acabamento com navalha e finalização com pomada modeladora."
                              class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500"></textarea>
                </div>

                <div class="space-y-2">
                    <label class="block text-xs font-semibold text-zinc-400">
                        <i class="fa-solid fa-image text-amber-500 mr-1"></i> Imagem / Foto Ilustrativa
                    </label>
                    <div class="flex items-center gap-3">
                        <label class="flex-1 flex flex-col items-center justify-center border-2 border-dashed border-zinc-700 hover:border-amber-500 rounded-xl p-3 cursor-pointer bg-zinc-800/50 transition">
                            <i class="fa-solid fa-crop-simple text-amber-500 text-lg mb-1"></i>
                            <span class="text-xs text-zinc-300 font-semibold" id="labelUploadNome">Escolher e Centralizar Foto</span>
                            <span class="text-[10px] text-zinc-500">JPG, PNG ou WebP (Editor de recorte incluso)</span>
                            <input type="file" name="imagem" id="inputImagemServico" accept="image/*" class="hidden" onchange="iniciarEditorImagem(this, 'cad')">
                        </label>
                        <div id="containerPreview" onclick="reabrirEditorImagem('cad')" title="Clique para reposicionar e ajustar o enquadramento da foto" class="hidden w-20 h-20 rounded-xl overflow-hidden border border-zinc-700 bg-zinc-800 shrink-0 cursor-pointer relative group shadow">
                            <img id="imgPreview" src="" alt="Prévia" class="w-full h-full object-cover">
                            <div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition flex flex-col items-center justify-center text-amber-400">
                                <i class="fa-solid fa-crop-simple text-sm mb-0.5"></i>
                                <span class="text-[9px] font-bold text-white uppercase">Ajustar</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-1">
                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Comissão Padrão do Barbeiro (%)</label>
                    <input type="number" step="0.01" name="comissao_percentual" value="50.00" required
                           class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500 text-right">
                </div>

                <!-- Configuração de Pacote -->
                <div class="sm:col-span-2 bg-zinc-800/40 border border-zinc-800 p-3 rounded-xl flex flex-col justify-between">
                    <label class="flex items-center gap-2 cursor-pointer text-xs font-bold text-amber-400">
                        <input type="checkbox" name="eh_pacote" id="checkPacote" onchange="toggleOpcoesPacote(this.checked, 'containerCamposPacote', ['cadSessoes', 'cadValidade'])"
                               class="rounded text-amber-500 focus:ring-amber-500 bg-zinc-900 border-zinc-700">
                        <span>É um Pacote de Sessões? (Ex: Paga 1ª vez, próximas vezes valor zero)</span>
                    </label>

                    <div id="containerCamposPacote" class="hidden grid grid-cols-2 gap-3 mt-2 pt-2 border-t border-zinc-700/60">
                        <div>
                            <label class="block text-[11px] font-semibold text-zinc-400 mb-1">Total de Sessões</label>
                            <input type="number" min="2" name="sessoes_pacote" id="cadSessoes" value="3" disabled
                                   class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2 text-xs text-white">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-zinc-400 mb-1">Validade do Pacote (Dias)</label>
                            <input type="number" min="1" name="validade_dias" id="cadValidade" value="30" disabled
                                   class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2 text-xs text-white">
                        </div>
                    </div>
                </div>
            </div>

                        <!-- Regras de Incompatibilidade: Serviços que NÃO podem ser marcados juntos -->
            <div class="bg-zinc-800/40 border border-zinc-800 p-4 rounded-xl space-y-2">
                <div class="flex items-center justify-between">
                    <label class="block text-xs font-bold text-red-400 flex items-center gap-1.5">
                        <i class="fa-solid fa-ban"></i> Serviços Incompatíveis (Não permitir marcar juntos com este)
                    </label>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="marcarTodosIncompCad(true)" class="text-[10px] font-bold text-red-400 hover:text-red-300 bg-red-950/40 border border-red-900/60 px-2 py-0.5 rounded transition">
                            <i class="fa-solid fa-check-double mr-1"></i> Selecionar Tudo
                        </button>
                        <button type="button" onclick="marcarTodosIncompCad(false)" class="text-[10px] text-zinc-400 hover:text-white bg-zinc-800 border border-zinc-700 px-2 py-0.5 rounded transition">
                            Desmarcar
                        </button>
                    </div>
                </div>
                <p class="text-[11px] text-zinc-400">Marque os serviços que NÃO podem ser escolhidos na mesma comanda (ex: se escolher Pé, não pode Pé+Mão ou Tudo):</p>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2 pt-1 max-h-36 overflow-y-auto pr-1">
                    <?php foreach ($servicos as $outroS): ?>
                        <label class="flex items-center gap-2 p-2 bg-zinc-900/80 border border-zinc-800 rounded-lg cursor-pointer hover:border-zinc-700 transition text-xs">
                            <input type="checkbox" name="incompativeis[]" value="<?= $outroS['id'] ?>" class="rounded text-amber-500 bg-zinc-800 border-zinc-700">
                            <span class="text-zinc-300 truncate"><?= htmlspecialchars($outroS['nome']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

<div class="flex justify-end pt-2">
                <button type="submit" name="cadastrar_servico" 
                        class="bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold px-6 py-2.5 rounded-xl text-xs transition flex items-center gap-2 shadow">
                    <i class="fa-solid fa-plus"></i> Salvar no Catálogo
                </button>
            </div>
        </form>
    </div>

    <!-- TABELA EXCLUSIVA DE SERVIÇOS E PACOTES (CLICÁVEL PARA EDITAR) -->
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden shadow">
        <div class="p-4 border-b border-zinc-800 flex justify-between items-center">
            <div>
                <h3 class="font-bold text-white text-sm">Serviços & Pacotes Ativos (<?= count($servicos) ?>)</h3>
                <span class="text-xs text-amber-400 font-semibold"><i class="fa-solid fa-hand-pointer mr-1"></i> Clique em qualquer linha para editar o serviço</span>
            </div>
            <span class="text-xs text-zinc-500">Exibidos na marcação online</span>
        </div>

        <div class="overflow-x-auto w-full">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-zinc-800 text-zinc-400 uppercase tracking-wider text-[11px]">
                        <th class="p-3.5 w-16">Foto</th>
                        <th class="p-3.5">Serviço & Descrição</th>
                        <th class="p-3.5">Tipo</th>
                        <th class="p-3.5">Duração</th>
                        <th class="p-3.5">Preço Total</th>
                        <th class="p-3.5">Comissão</th>
                        <th class="p-3.5 text-center">Estado</th>
                        <th class="p-3.5 text-right">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800">
                    <?php if (empty($servicos)): ?>
                        <tr><td colspan="8" class="p-6 text-center text-zinc-500">Nenhum serviço cadastrado no catálogo.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($servicos as $s): 
                        $ehPacote = !empty($s['eh_pacote']);
                        $foto = !empty($s['imagem']) ? '../' . ltrim($s['imagem'], '/') : null;
                        $dadosJson = htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8');
                    ?>
                    <tr class="hover:bg-zinc-800/60 transition cursor-pointer group" onclick="abrirModalEdicaoServico(<?= $dadosJson ?>)">
                        <td class="p-3.5">
                            <?php if ($foto): ?>
                                <img src="<?= htmlspecialchars($foto) ?>" alt="<?= htmlspecialchars($s['nome']) ?>" 
                                     class="w-12 h-12 rounded-xl object-cover border border-zinc-700 bg-zinc-800 shadow-sm">
                            <?php else: ?>
                                <div class="w-12 h-12 rounded-xl bg-zinc-800 border border-zinc-700 text-zinc-500 flex items-center justify-center">
                                    <i class="fa-solid fa-scissors text-base text-zinc-600"></i>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="p-3.5 font-bold text-white max-w-xs">
                            <span class="text-sm block group-hover:text-amber-400 transition"><?= htmlspecialchars($s['nome']) ?></span>
                            <?php if (!empty($s['descricao'])): ?>
                                <span class="text-[11px] font-normal text-zinc-400 block line-clamp-2 mt-0.5">
                                    <?= htmlspecialchars($s['descricao']) ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($ehPacote): ?>
                                <span class="block text-[11px] font-normal text-amber-400 mt-0.5">
                                    Contém <?= $s['sessoes_pacote'] ?> sessões • Válido por <?= $s['validade_dias'] ?> dias
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="p-3.5 whitespace-nowrap">
                            <?php if ($ehPacote): ?>
                                <span class="bg-amber-500/10 text-amber-400 border border-amber-500/30 px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase">
                                    <i class="fa-solid fa-box-archive mr-1"></i> Pacote
                                </span>
                            <?php else: ?>
                                <span class="bg-zinc-800 text-zinc-300 border border-zinc-700 px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase">
                                    Avulso
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="p-3.5 text-zinc-300 whitespace-nowrap"><?= $s['duracao_minutos'] ?> min</td>
                        <td class="p-3.5 font-bold text-amber-400 whitespace-nowrap">R$ <?= number_format($s['preco'], 2, ',', '.') ?></td>
                        <td class="p-3.5 text-zinc-400 whitespace-nowrap"><?= number_format($s['comissao_percentual'], 1) ?>%</td>
                        <td class="p-3.5 text-center whitespace-nowrap" onclick="event.stopPropagation()">
                            <a href="servicos.php?toggle_status=<?= $s['id'] ?>" 
                               class="px-2.5 py-1 rounded-full font-bold uppercase text-[10px] transition <?= $s['status'] === 'ativo' ? 'bg-emerald-950/80 text-emerald-400 border border-emerald-800/60' : 'bg-zinc-800 text-zinc-500 border border-zinc-700' ?>">
                                <?= $s['status'] ?>
                            </a>
                        </td>
                        <td class="p-3.5 text-right whitespace-nowrap space-x-1" onclick="event.stopPropagation()">
                            <button type="button" onclick="abrirModalEdicaoServico(<?= $dadosJson ?>)" 
                                    class="text-zinc-400 hover:text-amber-400 p-1.5 rounded-lg transition" title="Editar Serviço">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </button>
                            <a href="servicos.php?excluir_servico=<?= $s['id'] ?>" onclick="return confirm('Deseja eliminar este serviço do catálogo?');" 
                               class="text-zinc-500 hover:text-red-400 p-1.5 rounded-lg transition" title="Excluir">
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

<!-- MODAL DE EDIÇÃO DE SERVIÇO / PACOTE -->
<div id="modalEdicaoServico" class="fixed inset-0 bg-black/75 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl max-w-2xl w-full p-6 space-y-4 shadow-2xl overflow-y-auto max-h-[90vh]">
        <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
            <h3 class="text-base font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-pen-to-square text-amber-500"></i> Editar Serviço / Pacote
            </h3>
            <button type="button" onclick="fecharModalEdicaoServico()" class="text-zinc-400 hover:text-white text-lg">&times;</button>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="editar_servico_id" id="modalEditId">
            <!-- Campo oculto para imagem editada/recortada -->
            <input type="hidden" name="editar_imagem_base64" id="modalEditImagemBase64">

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3.5">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Nome do Serviço</label>
                    <input type="text" name="editar_nome" id="modalEditNome" required
                           class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Estado</label>
                    <select name="editar_status" id="modalEditStatus" class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                        <option value="ativo">Ativo</option>
                        <option value="inativo">Inativo</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Preço Total (R$)</label>
                    <input type="text" inputmode="decimal" name="editar_preco" id="modalEditPreco" required
                           class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white font-bold text-right focus:outline-none focus:border-amber-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Duração</label>
                    <select name="editar_duracao_minutos" id="modalEditDuracao" required class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                        <option value="20">20 minutos</option>
                        <option value="30">30 minutos</option>
                        <option value="40">40 minutos</option>
                        <option value="45">45 minutos</option>
                        <option value="60">60 minutos (1 hora)</option>
                        <option value="90">90 minutos (1h 30m)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Comissão (%)</label>
                    <input type="number" step="0.01" name="editar_comissao_percentual" id="modalEditComissao" required
                           class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white text-right focus:outline-none focus:border-amber-500">
                </div>
            </div>

            <!-- Descrição e Imagem na Edição -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-zinc-400 mb-1">Descrição Detalhada</label>
                    <textarea name="editar_descricao" id="modalEditDescricao" rows="3"
                              class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500"></textarea>
                </div>

                <div class="space-y-2">
                    <label class="block text-xs font-semibold text-zinc-400">Alterar & Centralizar Foto</label>
                    <div class="flex items-center gap-3">
                        <label class="flex-1 flex flex-col items-center justify-center border-2 border-dashed border-zinc-700 hover:border-amber-500 rounded-xl p-2.5 cursor-pointer bg-zinc-800/50 transition">
                            <i class="fa-solid fa-crop-simple text-amber-500 text-base mb-0.5"></i>
                            <span class="text-xs text-zinc-300 font-semibold" id="modalLabelUploadNome">Escolher e Centralizar Foto</span>
                            <span class="text-[10px] text-zinc-500">JPG, PNG ou WebP</span>
                            <input type="file" name="editar_imagem" id="modalInputImagem" accept="image/*" class="hidden" onchange="iniciarEditorImagem(this, 'edit')">
                        </label>
                        <div id="modalContainerPreview" onclick="reabrirEditorImagem('edit')" title="Clique para reposicionar e ajustar o enquadramento da foto" class="w-16 h-16 rounded-xl overflow-hidden border border-zinc-700 bg-zinc-800 shrink-0 cursor-pointer relative group shadow">
                            <img id="modalImgPreview" src="" alt="Foto" class="w-full h-full object-cover">
                            <div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition flex flex-col items-center justify-center text-amber-400">
                                <i class="fa-solid fa-crop-simple text-sm mb-0.5"></i>
                                <span class="text-[9px] font-bold text-white uppercase">Ajustar</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Configurações de Pacote na Edição -->
            <div class="bg-zinc-800/50 border border-zinc-800 p-3.5 rounded-xl space-y-2.5">
                <label class="flex items-center gap-2 cursor-pointer text-xs font-bold text-amber-400">
                    <input type="checkbox" name="editar_eh_pacote" id="modalEditEhPacote" onchange="toggleOpcoesPacote(this.checked, 'modalContainerPacote', ['modalEditSessoes', 'modalEditValidade'])"
                           class="rounded text-amber-500 focus:ring-amber-500 bg-zinc-900 border-zinc-700">
                    <span>Configurar como Pacote de Sessões</span>
                </label>

                <div id="modalContainerPacote" class="hidden grid grid-cols-2 gap-3 pt-2 border-t border-zinc-700/60">
                    <div>
                        <label class="block text-[11px] font-semibold text-zinc-400 mb-1">Total de Sessões</label>
                        <input type="number" min="2" name="editar_sessoes_pacote" id="modalEditSessoes"
                               class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2 text-xs text-white">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-zinc-400 mb-1">Validade (Dias)</label>
                        <input type="number" min="1" name="editar_validade_dias" id="modalEditValidade"
                               class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2 text-xs text-white">
                    </div>
                </div>
            </div>

                        <!-- Regras de Incompatibilidade no Modal de Edição -->
            <div class="bg-zinc-800/40 border border-zinc-800 p-4 rounded-xl space-y-2">
                <div class="flex items-center justify-between">
                    <label class="block text-xs font-bold text-red-400 flex items-center gap-1.5">
                        <i class="fa-solid fa-ban"></i> Serviços Incompatíveis (Não permitir marcar juntos com este)
                    </label>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="marcarTodosIncompModal(true)" class="text-[10px] font-bold text-red-400 hover:text-red-300 bg-red-950/40 border border-red-900/60 px-2 py-0.5 rounded transition">
                            <i class="fa-solid fa-check-double mr-1"></i> Selecionar Tudo
                        </button>
                        <button type="button" onclick="marcarTodosIncompModal(false)" class="text-[10px] text-zinc-400 hover:text-white bg-zinc-800 border border-zinc-700 px-2 py-0.5 rounded transition">
                            Desmarcar
                        </button>
                    </div>
                </div>
                <p class="text-[11px] text-zinc-400">Marque quais serviços NÃO podem ser escolhidos junto com este:</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2 pt-1 max-h-36 overflow-y-auto pr-1" id="modalContainerIncompativeis">
                    <?php foreach ($servicos as $outroS): ?>
                        <label class="flex items-center gap-2 p-2 bg-zinc-900/80 border border-zinc-800 rounded-lg cursor-pointer hover:border-zinc-700 transition text-xs item-incompativel-check" data-id="<?= $outroS['id'] ?>">
                            <input type="checkbox" name="editar_incompativeis[]" value="<?= $outroS['id'] ?>" class="rounded text-amber-500 bg-zinc-800 border-zinc-700 check-incomp-modal">
                            <span class="text-zinc-300 truncate"><?= htmlspecialchars($outroS['nome']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

<div class="flex justify-end gap-2 pt-3 border-t border-zinc-800">
                <button type="button" onclick="fecharModalEdicaoServico()" 
                        class="px-4 py-2 rounded-xl text-xs bg-zinc-800 text-zinc-300 hover:text-white">Cancelar</button>
                <button type="submit" name="editar_servico" 
                        class="px-5 py-2 rounded-xl text-xs bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold shadow">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> Salvar Alterações
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL EDITOR DE IMAGEM (CENTRALIZAR, MOVER E DAR ZOOM) -->
<div id="modalEditorRecorte" class="fixed inset-0 bg-black/85 backdrop-blur-md z-[60] flex items-center justify-center p-4 hidden">
    <div class="bg-zinc-900 border border-zinc-800 rounded-3xl max-w-lg w-full p-6 space-y-4 shadow-2xl">
        <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
            <div>
                <h3 class="text-base font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-crop-simple text-amber-500"></i> Centralizar & Enquadrar Imagem
                </h3>
                <p class="text-xs text-zinc-400">Arraste a imagem para centralizar e use a barra para ajustar o zoom.</p>
            </div>
            <button type="button" onclick="fecharEditorRecorte()" class="text-zinc-400 hover:text-white text-xl">&times;</button>
        </div>

        <!-- Área de Enquadramento Interativo -->
        <div class="relative w-full aspect-square max-w-[340px] mx-auto bg-zinc-950 rounded-2xl overflow-hidden border-2 border-amber-500 shadow-inner flex items-center justify-center select-none cursor-grab active:cursor-grabbing" id="cropCanvasContainer">
            <canvas id="cropCanvas" class="w-full h-full block"></canvas>
            
            <!-- Grid de enquadramento sutil sobre a imagem -->
            <div class="absolute inset-0 pointer-events-none grid grid-cols-3 grid-rows-3 border border-white/20">
                <div class="border-r border-b border-white/10"></div>
                <div class="border-r border-b border-white/10"></div>
                <div class="border-b border-white/10"></div>
                <div class="border-r border-b border-white/10"></div>
                <div class="border-r border-b border-white/10"></div>
                <div class="border-b border-white/10"></div>
                <div class="border-r border-white/10"></div>
                <div class="border-r border-white/10"></div>
                <div></div>
            </div>
        </div>

        <!-- Controles de Zoom e Posição -->
        <div class="space-y-3 pt-2">
            <div class="flex items-center gap-3 bg-zinc-800/60 p-3 rounded-xl border border-zinc-800">
                <i class="fa-solid fa-magnifying-glass-minus text-zinc-400 text-xs"></i>
                <input type="range" id="zoomRange" min="1" max="4" step="0.05" value="1" 
                       class="flex-1 accent-amber-500 cursor-pointer h-1.5 bg-zinc-700 rounded-lg">
                <i class="fa-solid fa-magnifying-glass-plus text-amber-500 text-xs"></i>
                <button type="button" onclick="resetarPosicaoZoom()" class="text-[11px] text-zinc-400 hover:text-white underline ml-2">
                    Redefinir
                </button>
            </div>
        </div>

        <div class="flex justify-end gap-2 pt-2 border-t border-zinc-800">
            <button type="button" onclick="fecharEditorRecorte()" class="px-4 py-2 rounded-xl text-xs bg-zinc-800 text-zinc-300 hover:text-white">
                Cancelar
            </button>
            <button type="button" onclick="aplicarRecorteCentralizado()" class="px-5 py-2 rounded-xl text-xs bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold shadow flex items-center gap-1.5">
                <i class="fa-solid fa-check"></i> Aplicar Enquadramento
            </button>
        </div>
    </div>
</div>

<script>
// Correção essencial: quando o pacote está desmarcado, desabilita os inputs para não disparar 'not focusable'
function toggleOpcoesPacote(ativo, containerId, inputIds) {
    const el = document.getElementById(containerId);
    if (el) el.classList.toggle('hidden', !ativo);

    if (inputIds && Array.isArray(inputIds)) {
        inputIds.forEach(id => {
            const inp = document.getElementById(id);
            if (inp) {
                inp.disabled = !ativo;
            }
        });
    }
}

function abrirModalEdicaoServico(servico) {
    document.getElementById('modalEditId').value = servico.id;
    document.getElementById('modalEditNome').value = servico.nome;
    document.getElementById('modalEditStatus').value = servico.status || 'ativo';
    document.getElementById('modalEditPreco').value = parseFloat(servico.preco).toFixed(2).replace('.', ',');
    document.getElementById('modalEditDuracao').value = servico.duracao_minutos;
    document.getElementById('modalEditComissao').value = parseFloat(servico.comissao_percentual).toFixed(2);
    document.getElementById('modalEditDescricao').value = servico.descricao || '';
    document.getElementById('modalEditImagemBase64').value = '';

    // Foto atual
    const imgEl = document.getElementById('modalImgPreview');
    const containerImg = document.getElementById('modalContainerPreview');
    if (servico.imagem && servico.imagem.trim() !== '') {
        imgEl.src = '../' + servico.imagem.replace(/^\/+/, '');
        containerImg.classList.remove('hidden');
    } else {
        imgEl.src = '';
        containerImg.classList.add('hidden');
    }
    document.getElementById('modalLabelUploadNome').textContent = 'Escolher e Centralizar Foto';

    // Pacote
    const ehPacote = (servico.eh_pacote == 1);
    document.getElementById('modalEditEhPacote').checked = ehPacote;
    document.getElementById('modalEditSessoes').value = servico.sessoes_pacote || 3;
    document.getElementById('modalEditValidade').value = servico.validade_dias || 30;
    
    // Aplica visibilidade e desabilitação correta para evitar erro 'is not focusable'
    toggleOpcoesPacote(ehPacote, 'modalContainerPacote', ['modalEditSessoes', 'modalEditValidade']);

    // Carregar e marcar regras de incompatibilidade
    const incompList = servico.incompativeis ? (typeof servico.incompativeis === 'string' ? JSON.parse(servico.incompativeis) : servico.incompativeis) : [];
    document.querySelectorAll('.item-incompativel-check').forEach(item => {
        const id = parseInt(item.getAttribute('data-id'));
        const chk = item.querySelector('.check-incomp-modal');
        // Esconde o próprio serviço da lista de incompatíveis consigo mesmo
        if (id === parseInt(servico.id)) {
            item.classList.add('hidden');
            chk.checked = false;
        } else {
            item.classList.remove('hidden');
            chk.checked = incompList.includes(id);
        }
    });

    document.getElementById('modalEdicaoServico').classList.remove('hidden');
}

function fecharModalEdicaoServico() {
    document.getElementById('modalEdicaoServico').classList.add('hidden');
}

/* =========================================================
   EDITOR DE IMAGEM INTERATIVO (CANVAS COM DRAG & ZOOM)
========================================================= */
let cropContexto = 'cad'; // 'cad' ou 'edit'
let imgOriginal = new Image();
let canvas = document.getElementById('cropCanvas');
let ctx = canvas.getContext('2d');

let escala = 1;
let imgX = 0;
let imgY = 0;
let isDragging = false;
let startX = 0;
let startY = 0;

function reabrirEditorImagem(contexto) {
    cropContexto = contexto;
    const imgEl = (contexto === 'cad') ? document.getElementById('imgPreview') : document.getElementById('modalImgPreview');
    if (!imgEl || !imgEl.src || imgEl.src.trim() === '' || imgEl.src === window.location.href) return;

    imgOriginal = new Image();
    if (imgEl.src.startsWith('http')) {
        imgOriginal.crossOrigin = 'anonymous';
    }
    imgOriginal.onload = function() {
        canvas.width = 400;
        canvas.height = 400;
        resetarPosicaoZoom();
        document.getElementById('modalEditorRecorte').classList.remove('hidden');
    };
    imgOriginal.src = imgEl.src;
}

function iniciarEditorImagem(input, contexto) {
    if (input.files && input.files[0]) {
        cropContexto = contexto;
        const file = input.files[0];
        const reader = new FileReader();
        reader.onload = function(e) {
            imgOriginal = new Image();
            imgOriginal.onload = function() {
                canvas.width = 400;
                canvas.height = 400;
                resetarPosicaoZoom();
                document.getElementById('modalEditorRecorte').classList.remove('hidden');
            };
            imgOriginal.src = e.target.result;
        };
        reader.readAsDataURL(file);
    }
}

function resetarPosicaoZoom() {
    escala = Math.max(canvas.width / imgOriginal.width, canvas.height / imgOriginal.height);
    imgX = (canvas.width - imgOriginal.width * escala) / 2;
    imgY = (canvas.height - imgOriginal.height * escala) / 2;
    document.getElementById('zoomRange').value = 1;
    desenharCanvas();
}

function desenharCanvas() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    const zoomSlider = parseFloat(document.getElementById('zoomRange').value) || 1;
    const larguraAtual = imgOriginal.width * escala * zoomSlider;
    const alturaAtual = imgOriginal.height * escala * zoomSlider;

    ctx.drawImage(imgOriginal, imgX, imgY, larguraAtual, alturaAtual);
}

// Manipulação de Arrastar com Mouse ou Touch para Centralizar
const containerCanvas = document.getElementById('cropCanvasContainer');

containerCanvas.addEventListener('mousedown', (e) => {
    isDragging = true;
    startX = e.clientX - imgX;
    startY = e.clientY - imgY;
});

window.addEventListener('mousemove', (e) => {
    if (!isDragging) return;
    imgX = e.clientX - startX;
    imgY = e.clientY - startY;
    desenharCanvas();
});

window.addEventListener('mouseup', () => {
    isDragging = false;
});

// Suporte a Touch para Celulares/Tablets
containerCanvas.addEventListener('touchstart', (e) => {
    if (e.touches.length === 1) {
        isDragging = true;
        startX = e.touches[0].clientX - imgX;
        startY = e.touches[0].clientY - imgY;
    }
});

window.addEventListener('touchmove', (e) => {
    if (!isDragging || e.touches.length !== 1) return;
    imgX = e.touches[0].clientX - startX;
    imgY = e.touches[0].clientY - startY;
    desenharCanvas();
});

window.addEventListener('touchend', () => {
    isDragging = false;
});

// Controle de Zoom
document.getElementById('zoomRange').addEventListener('input', () => {
    desenharCanvas();
});

// Roda do Mouse para dar Zoom
containerCanvas.addEventListener('wheel', (e) => {
    e.preventDefault();
    const slider = document.getElementById('zoomRange');
    let val = parseFloat(slider.value) + (e.deltaY < 0 ? 0.1 : -0.1);
    val = Math.max(1, Math.min(4, val));
    slider.value = val;
    desenharCanvas();
}, { passive: false });

function fecharEditorRecorte() {
    document.getElementById('modalEditorRecorte').classList.add('hidden');
}

function aplicarRecorteCentralizado() {
    // Gera a imagem final recortada em 400x400 JPG com qualidade alta
    const base64Final = canvas.toDataURL('image/jpeg', 0.92);

    if (cropContexto === 'cad') {
        document.getElementById('cadImagemBase64').value = base64Final;
        document.getElementById('imgPreview').src = base64Final;
        document.getElementById('containerPreview').classList.remove('hidden');
        document.getElementById('labelUploadNome').textContent = 'Foto Enquadrada ✓';
    } else {
        document.getElementById('modalEditImagemBase64').value = base64Final;
        document.getElementById('modalImgPreview').src = base64Final;
        document.getElementById('modalContainerPreview').classList.remove('hidden');
        document.getElementById('modalLabelUploadNome').textContent = 'Foto Enquadrada ✓';
    }

    fecharEditorRecorte();
}

function marcarTodosIncompCad(marcar) {
    document.querySelectorAll('input[name="incompativeis[]"]').forEach(chk => {
        chk.checked = marcar;
    });
}

function marcarTodosIncompModal(marcar) {
    document.querySelectorAll('.item-incompativel-check:not(.hidden) .check-incomp-modal').forEach(chk => {
        chk.checked = marcar;
    });
}
</script>

</main>
</body>
</html>
