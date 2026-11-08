<?php
require_once 'config/database.php';

// Se o cliente já tiver sessão ativa, segue diretamente para a marcação
if (isset($_SESSION['cliente_id'])) {
    header('Location: agendar.php');
    exit;
}

// Caso contrário, apresenta a página de login / registo
header('Location: cliente_login.php');
exit;
