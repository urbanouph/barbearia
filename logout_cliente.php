<?php
session_start();
unset($_SESSION['cliente_id']);
unset($_SESSION['cliente_nome']);
unset($_SESSION['cliente_telefone']);
header('Location: cliente_login.php');
exit;
