<?php
require_once '../config/database.php';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $senha = $_POST['senha'];

    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    $senhaValida = false;
    if ($user && !empty($user['senha'])) {
        $senhaValida = password_verify($senha, $user['senha']);
        // Atualiza automaticamente senhas antigas em texto puro no primeiro login.
        if (!$senhaValida && hash_equals((string)$user['senha'], (string)$senha)) {
            $hashAtualizado = password_hash($senha, PASSWORD_DEFAULT);
            $stmtHash = $pdo->prepare("UPDATE usuarios SET senha = ? WHERE id = ?");
            $stmtHash->execute([$hashAtualizado, $user['id']]);
            $senhaValida = true;
        }
    }

    if ($senhaValida) {
        session_regenerate_id(true);
        $_SESSION['usuario_id'] = $user['id'];
        $_SESSION['usuario_nome'] = $user['nome'];
        header('Location: index.php');
        exit;
    } else {
        $erro = 'Credenciais inválidas.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Barbearia</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/png" href="../assets/logo.png">
    <link rel="apple-touch-icon" href="../assets/logo.png">
</head>
<body class="bg-zinc-950 flex items-center justify-center min-h-screen text-white p-4">
    <div class="bg-zinc-900 border border-zinc-800 p-8 rounded-xl max-w-sm w-full">
        <h2 class="text-xl font-bold mb-6 text-center text-amber-500">Acesso Administrativo</h2>
        <?php if ($erro): ?>
            <div class="bg-red-500/10 border border-red-500 text-red-400 p-2 text-sm rounded mb-4 text-center"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-sm text-zinc-400 mb-1">E-mail</label>
                <input type="email" name="email"  required class="w-full bg-zinc-800 border border-zinc-700 rounded p-2.5 text-white focus:outline-none focus:border-amber-500">
            </div>
            <div>
                <label class="block text-sm text-zinc-400 mb-1">Senha</label>
                <input type="password" name="senha" autocomplete="current-password" required class="w-full bg-zinc-800 border border-zinc-700 rounded p-2.5 text-white focus:outline-none focus:border-amber-500">
            </div>
            <button type="submit" class="w-full bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold py-2.5 rounded transition">Entrar</button>
        </form>
    </div>
</body>
</html>