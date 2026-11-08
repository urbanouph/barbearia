<?php
require_once 'config/database.php';

$provider = $_GET['provider'] ?? '';

// Novas credenciais atualizadas geradas na Google Cloud
$googleClientId     = '14083264326-0jr8oc2usu1mls03f7006a4cm2522sn0.apps.googleusercontent.com';
$googleClientSecret = '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';
$googleRedirectUri  = 'http://localhost/barbearia/auth_social.php?provider=google';

if ($provider === 'google') {
    if (!isset($_GET['code'])) {
        $url = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
            'client_id' => $googleClientId,
            'redirect_uri' => $googleRedirectUri,
            'response_type' => 'code',
            'scope' => 'email profile',
            'access_type' => 'online'
        ]);
        header("Location: $url");
        exit;
    } else {
        $tokenPost = [
            'code' => $_GET['code'],
            'client_id' => $googleClientId,
            'client_secret' => $googleClientSecret,
            'redirect_uri' => $googleRedirectUri,
            'grant_type' => 'authorization_code'
        ];
        
        $ch = curl_init("https://oauth2.googleapis.com/token");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $tokenPost);
        $res = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (!empty($res['access_token'])) {
            $info = json_decode(file_get_contents("https://www.googleapis.com/oauth2/v2/userinfo?access_token=" . $res['access_token']), true);
            
            $oauthUid = $info['id'];
            $email = $info['email'];
            $nome = $info['name'];

            $stmt = $pdo->prepare("SELECT id, nome, telefone FROM clientes WHERE oauth_provider = 'google' AND oauth_uid = ?");
            $stmt->execute([$oauthUid]);
            $cliente = $stmt->fetch();

            if (!$cliente) {
                $stmtInsert = $pdo->prepare("INSERT INTO clientes (nome, email, oauth_provider, oauth_uid) VALUES (?, ?, 'google', ?)");
                $stmtInsert->execute([$nome, $email, $oauthUid]);
                $clienteId = $pdo->lastInsertId();
                $clienteTelefone = '';
            } else {
                $clienteId = $cliente['id'];
                $clienteTelefone = $cliente['telefone'];
            }

            $_SESSION['cliente_id'] = $clienteId;
            $_SESSION['cliente_nome'] = $nome;
            $_SESSION['cliente_telefone'] = $clienteTelefone;
            header('Location: agendar.php');
            exit;
        }
    }
} else {
    header('Location: cliente_login.php');
    exit;
}
