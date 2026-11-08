<?php
require_once 'config/database.php';

if (!isset($_SESSION['cliente_id'])) {
    header('Location: cliente_login.php');
    exit;
}

$clienteId = (int)$_SESSION['cliente_id'];
$mensagemSucesso = '';
$mensagemErro = '';

// Consulta dados atuais do cliente
$stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
$stmt->execute([$clienteId]);
$cliente = $stmt->fetch();

if (!$cliente) {
    header('Location: logout_cliente.php');
    exit;
}

// Alteração de Palavra-passe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['alterar_senha'])) {
    $senhaAtual = $_POST['senha_atual'] ?? '';
    $novaSenha = $_POST['nova_senha'] ?? '';
    $confirmaSenha = $_POST['confirma_senha'] ?? '';

    // Se a conta for criada via login social (sem palavra-passe prévia), permite definir diretamente
    $temSenhaAntiga = !empty($cliente['senha']);

    if ($temSenhaAntiga && $senhaAtual !== $cliente['senha']) {
        $mensagemErro = 'A palavra-passe atual indicada está incorreta.';
    } elseif (strlen($novaSenha) < 6) {
        $mensagemErro = 'A nova palavra-passe deve ter pelo menos 6 caracteres.';
    } elseif ($novaSenha !== $confirmaSenha) {
        $mensagemErro = 'A confirmação não coincide com a nova palavra-passe.';
    } else {
        $stmtUpdate = $pdo->prepare("UPDATE clientes SET senha = ? WHERE id = ?");
        $stmtUpdate->execute([$novaSenha, $clienteId]);
        $mensagemSucesso = 'Palavra-passe alterada com sucesso!';
        $cliente['senha'] = $novaSenha;
    }
}

// Atualização de Dados (Telefone / Nome)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['atualizar_dados'])) {
    $nome = trim($_POST['nome']);
    $telefone = trim($_POST['telefone']);

    if (empty($nome)) {
        $mensagemErro = 'O nome não pode ficar vazio.';
    } else {
        $stmtUpdate = $pdo->prepare("UPDATE clientes SET nome = ?, telefone = ? WHERE id = ?");
        $stmtUpdate->execute([$nome, $telefone, $clienteId]);
        $_SESSION['cliente_nome'] = $nome;
        $_SESSION['cliente_telefone'] = $telefone;
        $cliente['nome'] = $nome;
        $cliente['telefone'] = $telefone;
        $mensagemSucesso = 'Dados atualizados com sucesso!';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>O Meu Perfil - Barbearia</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="assets/logo.png">
    <link rel="apple-touch-icon" href="assets/logo.png">
</head>
<body class="bg-zinc-950 text-zinc-100 min-h-screen flex items-center justify-center p-4">

    <!-- Container padronizado com max-w-md w-full para manter o mesmo tamanho das restantes páginas -->
    <div class="max-w-md w-full bg-zinc-900 border border-zinc-800 rounded-2xl p-6 sm:p-8 shadow-2xl my-8 space-y-6">
        
        <!-- Cabeçalho -->
        <div class="flex items-center justify-between pb-4 border-b border-zinc-800">
            <div>
                <h1 class="text-xl font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-user-gear text-amber-500"></i> O Meu Perfil
                </h1>
                <p class="text-xs text-zinc-400 mt-0.5">Gerir dados da conta e palavra-passe.</p>
            </div>
            <a href="meus_agendamentos.php" class="text-xs text-zinc-400 hover:text-white transition" title="Voltar">
                <i class="fa-solid fa-xmark text-lg"></i>
            </a>
        </div>

        <?php if ($mensagemSucesso): ?>
            <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs p-3 rounded-xl text-center">
                <i class="fa-solid fa-circle-check mr-1"></i> <?= htmlspecialchars($mensagemSucesso) ?>
            </div>
        <?php endif; ?>

        <?php if ($mensagemErro): ?>
            <div class="bg-red-500/10 border border-red-500/30 text-red-400 text-xs p-3 rounded-xl text-center">
                <i class="fa-solid fa-triangle-exclamation mr-1"></i> <?= htmlspecialchars($mensagemErro) ?>
            </div>
        <?php endif; ?>

        <!-- Formulário: Atualizar Dados Básicos -->
        <form method="POST" class="space-y-4">
            <h2 class="text-xs font-semibold text-zinc-400 uppercase tracking-wider">Dados Pessoais</h2>
            <div>
                <label class="block text-xs text-zinc-400 mb-1">Nome Completo</label>
                <input type="text" name="nome" value="<?= htmlspecialchars($cliente['nome']) ?>" required 
                    class="w-full bg-zinc-800 border border-zinc-700 rounded-xl py-2.5 px-3 text-white text-xs focus:outline-none focus:border-amber-500">
            </div>
            <div>
                <label class="block text-xs text-zinc-400 mb-1">Telemóvel / WhatsApp</label>
                <input type="tel" name="telefone" value="<?= htmlspecialchars($cliente['telefone'] ?? '') ?>" placeholder="(11) 99999-9999" required 
                    class="w-full bg-zinc-800 border border-zinc-700 rounded-xl py-2.5 px-3 text-white text-xs focus:outline-none focus:border-amber-500">
            </div>
            <div>
                <label class="block text-xs text-zinc-400 mb-1">E-mail (Registo)</label>
                <input type="email" value="<?= htmlspecialchars($cliente['email']) ?>" disabled 
                    class="w-full bg-zinc-800/50 border border-zinc-800 rounded-xl py-2.5 px-3 text-zinc-500 text-xs cursor-not-allowed">
            </div>
            <button type="submit" name="atualizar_dados" 
                class="w-full bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 text-white font-semibold py-2.5 rounded-xl text-xs transition">
                Guardar Dados
            </button>
        </form>

        <div class="border-t border-zinc-800 pt-4"></div>

        <!-- Formulário: Trocar Palavra-passe -->
        <form method="POST" class="space-y-4">
            <h2 class="text-xs font-semibold text-zinc-400 uppercase tracking-wider">Segurança & Palavra-passe</h2>
            
            <?php if (!empty($cliente['senha'])): ?>
                <div>
                    <label class="block text-xs text-zinc-400 mb-1">Palavra-passe Atual</label>
                    <input type="password" name="senha_atual" placeholder="Introduza a sua palavra-passe atual" required 
                        class="w-full bg-zinc-800 border border-zinc-700 rounded-xl py-2.5 px-3 text-white text-xs focus:outline-none focus:border-amber-500">
                </div>
            <?php else: ?>
                <p class="text-[11px] text-amber-500/90 bg-amber-500/10 p-2.5 rounded-lg border border-amber-500/20">
                    A sua conta foi criada através de login social. Pode definir agora uma palavra-passe própria.
                </p>
            <?php endif; ?>

            <div>
                <label class="block text-xs text-zinc-400 mb-1">Nova Palavra-passe</label>
                <input type="password" id="novaSenha" name="nova_senha" placeholder="Mínimo 6 caracteres" required 
                    class="w-full bg-zinc-800 border border-zinc-700 rounded-xl py-2.5 px-3 text-white text-xs focus:outline-none focus:border-amber-500">
                
                <!-- Barra Medidora de Força da Palavra-passe -->
                <div class="mt-2 space-y-1">
                    <div class="w-full bg-zinc-800 h-1.5 rounded-full overflow-hidden">
                        <div id="barraForca" class="h-full w-0 transition-all duration-300 rounded-full bg-zinc-700"></div>
                    </div>
                    <div class="flex justify-between items-center text-[10px]">
                        <span id="textoForca" class="text-zinc-500 font-medium">Digite uma senha...</span>
                        <span id="requisitosSenha" class="text-zinc-500">Mínimo 6 dígitos</span>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-xs text-zinc-400 mb-1">Confirmar Nova Palavra-passe</label>
                <input type="password" name="confirma_senha" placeholder="Repita a nova palavra-passe" required 
                    class="w-full bg-zinc-800 border border-zinc-700 rounded-xl py-2.5 px-3 text-white text-xs focus:outline-none focus:border-amber-500">
            </div>

            <button type="submit" name="alterar_senha" 
                class="w-full bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold py-2.5 rounded-xl text-xs transition flex items-center justify-center gap-1.5">
                <i class="fa-solid fa-key"></i> Atualizar Palavra-passe
            </button>
        </form>

        <!-- Barra inferior de navegação -->
        <div class="pt-2 border-t border-zinc-800 flex justify-between text-xs">
            <a href="meus_agendamentos.php" class="text-zinc-400 hover:text-white flex items-center gap-1">
                <i class="fa-solid fa-arrow-left"></i> As minhas marcações
            </a>
            <a href="agendar.php" class="text-amber-500 hover:underline font-semibold flex items-center gap-1">
                Marcar corte <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

    </div>

    <!-- Script de Avaliação Dinâmica de Força da Senha -->
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const inputSenha = document.getElementById('novaSenha');
        const barra = document.getElementById('barraForca');
        const texto = document.getElementById('textoForca');

        if (!inputSenha) return;

        inputSenha.addEventListener('input', () => {
            const valor = inputSenha.value;
            let pontuacao = 0;

            if (valor.length === 0) {
                barra.style.width = '0%';
                barra.className = 'h-full transition-all duration-300 rounded-full bg-zinc-700';
                texto.textContent = 'Digite uma senha...';
                texto.className = 'text-zinc-500 font-medium';
                return;
            }

            // Critérios de avaliação
            if (valor.length >= 6) pontuacao++;
            if (valor.length >= 10) pontuacao++;
            if (/[A-Z]/.test(valor)) pontuacao++;
            if (/[0-9]/.test(valor)) pontuacao++;
            if (/[^A-Za-z0-9]/.test(valor)) pontuacao++;

            // Atualização visual em tempo real
            if (pontuacao <= 2) {
                barra.style.width = '33%';
                barra.className = 'h-full transition-all duration-300 rounded-full bg-red-500';
                texto.textContent = 'Fraca (adicione números ou maiúsculas)';
                texto.className = 'text-red-400 font-medium';
            } else if (pontuacao <= 3) {
                barra.style.width = '66%';
                barra.className = 'h-full transition-all duration-300 rounded-full bg-amber-500';
                texto.textContent = 'Média (boa, adicione símbolos especiais)';
                texto.className = 'text-amber-400 font-medium';
            } else {
                barra.style.width = '100%';
                barra.className = 'h-full transition-all duration-300 rounded-full bg-emerald-500';
                texto.textContent = 'Forte (excelente proteção)';
                texto.className = 'text-emerald-400 font-medium';
            }
        });
    });
    </script>
</body>
</html>
