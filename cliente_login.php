<?php
require_once 'config/database.php';

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao_registro'])) {
    $nome = trim($_POST['nome']);
    $email = trim($_POST['email']);
    $senha = $_POST['senha'];
    $telefone = trim($_POST['telefone']);
    $cpf = preg_replace('/\D/', '', $_POST['cpf']);
    $data_nascimento = $_POST['data_nascimento'];

    $stmtCheck = $pdo->prepare("SELECT id FROM clientes WHERE email = ? OR cpf = ?");
    $stmtCheck->execute([$email, $cpf]);
    
    if ($stmtCheck->fetch()) {
        $erro = "E-mail ou CPF já cadastrado no sistema.";
    } else {
        $stmtInsert = $pdo->prepare("
            INSERT INTO clientes (nome, email, senha, telefone, cpf, data_nascimento, oauth_provider)
            VALUES (?, ?, ?, ?, ?, ?, 'local')
        ");
        $stmtInsert->execute([$nome, $email, $senha, $telefone, $cpf, $data_nascimento]);
        $sucesso = "Cadastro realizado com sucesso! Faça login para continuar.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao_login'])) {
    $email = trim($_POST['email']);
    $senha = $_POST['senha'];

    $stmtUser = $pdo->prepare("SELECT * FROM clientes WHERE email = ? AND oauth_provider = 'local'");
    $stmtUser->execute([$email]);
    $cliente = $stmtUser->fetch();

    if ($cliente && $cliente['senha'] === $senha) {
        $_SESSION['cliente_id'] = $cliente['id'];
        $_SESSION['cliente_nome'] = $cliente['nome'];
        $_SESSION['cliente_telefone'] = $cliente['telefone'];
        header('Location: agendar.php');
        exit;
    } else {
        $erro = "E-mail ou senha inválidos.";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Área do Cliente - Shift Barber</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="assets/logo.png">
    <link rel="apple-touch-icon" href="assets/logo.png">
</head>
<body class="bg-zinc-950 text-zinc-100 min-h-screen flex items-center justify-center p-4">

    <div class="max-w-md w-full bg-zinc-900 border border-zinc-800 p-6 sm:p-8 rounded-2xl shadow-xl">
        
        <div class="text-center mb-6">
            <i class="fa-solid fa-scissors text-amber-500 text-3xl mb-2"></i>
            <h2 class="text-2xl font-bold">Área do Cliente</h2>
            <p class="text-xs text-zinc-400 mt-1">Acesse sua conta para agendar e gerenciar seus horários.</p>
        </div>

        <?php if ($erro): ?>
            <div class="bg-red-500/10 border border-red-500/30 text-red-400 text-xs p-3 rounded-lg mb-4 text-center">
                <?= htmlspecialchars($erro) ?>
            </div>
        <?php endif; ?>

        <?php if ($sucesso): ?>
            <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs p-3 rounded-lg mb-4 text-center">
                <?= htmlspecialchars($sucesso) ?>
            </div>
        <?php endif; ?>

        <div class="space-y-2 mb-6">
            <a href="auth_social.php?provider=google" class="w-full flex items-center justify-center gap-3 bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 py-2.5 px-4 rounded-xl text-xs font-semibold transition">
                <i class="fa-brands fa-google text-red-400 text-sm"></i> Continuar com o Google
            </a>
            <a href="auth_social.php?provider=facebook" class="w-full flex items-center justify-center gap-3 bg-[#1877F2]/10 hover:bg-[#1877F2]/20 border border-[#1877F2]/30 text-[#1877F2] py-2.5 px-4 rounded-xl text-xs font-semibold transition">
                <i class="fa-brands fa-facebook text-sm"></i> Continuar com o Facebook
            </a>
        </div>

        <div class="relative flex py-2 items-center mb-6">
            <div class="flex-grow border-t border-zinc-800"></div>
            <span class="flex-shrink mx-3 text-zinc-500 text-[11px] uppercase tracking-wider">ou utilize seu e-mail</span>
            <div class="flex-grow border-t border-zinc-800"></div>
        </div>

        <div class="flex border-b border-zinc-800 mb-6">
            <button id="tabLoginBtn" type="button" class="w-1/2 py-2 text-xs font-bold text-amber-500 border-b-2 border-amber-500">Entrar</button>
            <button id="tabRegBtn" type="button" class="w-1/2 py-2 text-xs font-bold text-zinc-400">Criar Conta</button>
        </div>

        <form id="formLogin" method="POST" class="space-y-4">
            <input type="hidden" name="acao_login" value="1">
            <div>
                <label class="block text-xs text-zinc-400 mb-1">E-mail</label>
                <input type="email" name="email" required class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
            </div>
            <div>
                <label class="block text-xs text-zinc-400 mb-1">Senha</label>
                <input type="password" name="senha" required class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
            </div>
            <button type="submit" class="w-full bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold py-2.5 rounded-xl text-xs transition">Entrar</button>
        </form>

        <form id="formRegistro" method="POST" class="space-y-3 hidden">
            <input type="hidden" name="acao_registro" value="1">
            <div>
                <label class="block text-[11px] text-zinc-400 mb-1">Nome Completo</label>
                <input type="text" name="nome" required class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2 text-xs text-white focus:outline-none focus:border-amber-500">
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-[11px] text-zinc-400 mb-1">Data de Nascimento</label>
                    <input type="date" name="data_nascimento" required class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2 text-xs text-white focus:outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block text-[11px] text-zinc-400 mb-1">CPF</label>
                    <input type="text" name="cpf" placeholder="000.000.000-00" required class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2 text-xs text-white focus:outline-none focus:border-amber-500">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-[11px] text-zinc-400 mb-1">WhatsApp / Celular</label>
                    <input type="text" name="telefone" placeholder="(11) 99999-9999" required class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2 text-xs text-white focus:outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block text-[11px] text-zinc-400 mb-1">E-mail</label>
                    <input type="email" name="email" required class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2 text-xs text-white focus:outline-none focus:border-amber-500">
                </div>
            </div>
            <div>
                <label class="block text-[11px] text-zinc-400 mb-1">Senha</label>
                <input type="password" name="senha" required class="w-full bg-zinc-800 border border-zinc-700 rounded-xl p-2 text-xs text-white focus:outline-none focus:border-amber-500">
            </div>
            <button type="submit" class="w-full bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold py-2.5 rounded-xl text-xs transition mt-2">Criar Conta</button>
        </form>

    </div>

    <script>
    const tabLoginBtn = document.getElementById('tabLoginBtn');
    const tabRegBtn = document.getElementById('tabRegBtn');
    const formLogin = document.getElementById('formLogin');
    const formRegistro = document.getElementById('formRegistro');

    tabLoginBtn.addEventListener('click', () => {
        tabLoginBtn.className = "w-1/2 py-2 text-xs font-bold text-amber-500 border-b-2 border-amber-500";
        tabRegBtn.className = "w-1/2 py-2 text-xs font-bold text-zinc-400";
        formLogin.classList.remove('hidden');
        formRegistro.classList.add('hidden');
    });

    tabRegBtn.addEventListener('click', () => {
        tabRegBtn.className = "w-1/2 py-2 text-xs font-bold text-amber-500 border-b-2 border-amber-500";
        tabLoginBtn.className = "w-1/2 py-2 text-xs font-bold text-zinc-400";
        formRegistro.classList.remove('hidden');
        formLogin.classList.add('hidden');
    });
    </script>
</body>
</html>
