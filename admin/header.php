<?php
require_once '../config/database.php';
verificarAutenticacao();

$paginaAtual = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel da Barbearia</title>
    <link rel="icon" type="image/png" href="../assets/logo.png">
    <link rel="apple-touch-icon" href="../assets/logo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .scroll-suave {
            -webkit-overflow-scrolling: touch;
            scroll-behavior: smooth;
        }
        .scroll-suave::-webkit-scrollbar {
            height: 6px;
        }
        .scroll-suave::-webkit-scrollbar-track {
            background: #18181b;
        }
        .scroll-suave::-webkit-scrollbar-thumb {
            background: #27272a;
            border-radius: 9999px;
        }
        .scroll-suave::-webkit-scrollbar-thumb:hover {
            background: #f59e0b;
        }
    </style>
</head>
<body class="bg-zinc-950 text-zinc-100 min-h-screen flex flex-col md:flex-row antialiased">

    <!-- Barra Superior para Mobile (Aparece apenas em telas pequenas) -->
    <header class="md:hidden bg-zinc-900 border-b border-zinc-800 px-4 py-3.5 flex items-center justify-between sticky top-0 z-40">
        <div class="flex items-center gap-2.5">
            <img src="../assets/logo.png" alt="Shift Barber" class="w-9 h-9 rounded-full object-cover border border-amber-500/40 shadow">
            <span class="font-extrabold text-lg tracking-wider text-white">SHIFT BARBER</span>
        </div>
        <button id="btnMenuMobile" type="button" class="text-zinc-300 hover:text-white p-2 rounded-lg bg-zinc-800 focus:outline-none">
            <i id="iconeMenu" class="fa-solid fa-bars text-lg"></i>
        </button>
    </header>

    <!-- Fundo Escuro Overlay (Apenas Mobile quando o menu abre) -->
    <div id="menuOverlay" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-40 hidden md:hidden transition-opacity"></div>

    <!-- Menu Lateral (Desktop fixo / Mobile como gaveta deslizante) -->
    <aside id="sidebarMenu" class="
        fixed md:sticky top-0 left-0 bottom-0 z-50
        w-64 bg-zinc-900 border-r border-zinc-800 p-6 
        flex flex-col justify-between shrink-0
        transform -translate-x-full md:translate-x-0 transition-transform duration-300 ease-in-out
        h-full md:h-screen overflow-y-auto
    ">
        <div>
            <!-- Topo do Menu -->
            <div class="flex items-center justify-between mb-8">
                <div class="flex items-center gap-3">
                    <img src="../assets/logo.png" alt="Shift Barber" class="w-11 h-11 rounded-full object-cover border-2 border-amber-500/60 shadow-lg">
                    <div>
                        <h1 class="text-base font-extrabold tracking-wider text-white leading-tight">SHIFT BARBER</h1>
                        <span class="text-[10px] text-amber-400 uppercase tracking-widest font-bold">Painel Admin</span>
                    </div>
                </div>
                <!-- Botão fechar (Mobile) -->
                <button id="btnFecharMobile" class="md:hidden text-zinc-400 hover:text-white p-1 text-lg">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- Links de Navegação -->
                        <!-- Links de Navegação Organizados por Blocos -->
                        <!-- Links de Navegação Organizados por Blocos de Ação -->
            <nav class="space-y-4">
                <!-- BLOCO 1: OPERAÇÕES & LANÇAMENTOS DO DIA A DIA -->
                <div>
                    <span class="text-[10px] font-extrabold uppercase tracking-widest text-zinc-500 px-3 block mb-1.5 flex items-center gap-1.5">
                        <i class="fa-solid fa-bolt text-amber-500"></i> Lançar & Atender
                    </span>
                    <div class="space-y-1">
                        <a href="index.php" class="flex items-center gap-3 px-3 py-2 rounded-xl transition text-xs font-semibold <?= $paginaAtual === 'index.php' ? 'bg-amber-500/10 text-amber-400 border border-amber-500/30' : 'text-zinc-300 hover:bg-zinc-800 hover:text-white' ?>">
                            <i class="fa-solid fa-chart-line w-4 text-amber-500"></i> Dashboard
                        </a>
                        <a href="agendamentos.php" class="flex items-center gap-3 px-3 py-2 rounded-xl transition text-xs font-semibold <?= $paginaAtual === 'agendamentos.php' ? 'bg-amber-500/10 text-amber-400 border border-amber-500/30' : 'text-zinc-300 hover:bg-zinc-800 hover:text-white' ?>">
                            <i class="fa-solid fa-calendar-check w-4 text-amber-500"></i> Agendamentos & Caixa
                        </a>
                        <a href="despesas_ganhos.php" class="flex items-center gap-3 px-3 py-2 rounded-xl transition text-xs font-semibold <?= $paginaAtual === 'despesas_ganhos.php' ? 'bg-amber-500/10 text-amber-400 border border-amber-500/30' : 'text-zinc-300 hover:bg-zinc-800 hover:text-white' ?>">
                            <i class="fa-solid fa-money-bill-transfer w-4 text-amber-500"></i> Lançar Despesa / Ganho
                        </a>
                    </div>
                </div>

                <!-- BLOCO 2: EQUIPE & PROFISSIONAIS -->
                <div>
                    <span class="text-[10px] font-extrabold uppercase tracking-widest text-zinc-500 px-3 block mb-1.5 flex items-center gap-1.5">
                        <i class="fa-solid fa-users text-amber-500"></i> Equipe & Barbeiros
                    </span>
                    <div class="space-y-1">
                        <a href="profissionais.php" class="flex items-center gap-3 px-3 py-2 rounded-xl transition text-xs font-semibold <?= $paginaAtual === 'profissionais.php' ? 'bg-amber-500/10 text-amber-400 border border-amber-500/30' : 'text-zinc-300 hover:bg-zinc-800 hover:text-white' ?>">
                            <i class="fa-solid fa-user-tie w-4 text-amber-500"></i> Cadastrar Barbeiros
                        </a>
                        <a href="equipe_servicos.php" class="flex items-center gap-3 px-3 py-2 rounded-xl transition text-xs font-semibold <?= $paginaAtual === 'equipe_servicos.php' ? 'bg-amber-500/10 text-amber-400 border border-amber-500/30' : 'text-zinc-300 hover:bg-zinc-800 hover:text-white' ?>">
                            <i class="fa-solid fa-user-gear w-4 text-amber-500"></i> Serviços & Comissões
                        </a>
                        <a href="bloqueios.php" class="flex items-center gap-3 px-3 py-2 rounded-xl transition text-xs font-semibold <?= $paginaAtual === 'bloqueios.php' ? 'bg-amber-500/10 text-amber-400 border border-amber-500/30' : 'text-zinc-300 hover:bg-zinc-800 hover:text-white' ?>">
                            <i class="fa-solid fa-calendar-xmark w-4 text-amber-500"></i> Folgas & Bloqueios
                        </a>
                    </div>
                </div>

                <!-- BLOCO 3: GESTÃO & CONFIGURAÇÕES (EDITAR / ALTERAR) -->
                <div>
                    <span class="text-[10px] font-extrabold uppercase tracking-widest text-zinc-500 px-3 block mb-1.5 flex items-center gap-1.5">
                        <i class="fa-solid fa-sliders text-amber-500"></i> Gestão & Configurações
                    </span>
                    <div class="space-y-1">
                        <a href="escalas.php" class="flex items-center gap-3 px-3 py-2 rounded-xl transition text-xs font-semibold <?= $paginaAtual === 'escalas.php' ? 'bg-amber-500/10 text-amber-400 border border-amber-500/30' : 'text-zinc-300 hover:bg-zinc-800 hover:text-white' ?>">
                            <i class="fa-solid fa-calendar-days w-4 text-amber-500"></i> Escalas de Atendimento
                        </a>
                        <a href="servicos.php" class="flex items-center gap-3 px-3 py-2 rounded-xl transition text-xs font-semibold <?= $paginaAtual === 'servicos.php' ? 'bg-amber-500/10 text-amber-400 border border-amber-500/30' : 'text-zinc-300 hover:bg-zinc-800 hover:text-white' ?>">
                            <i class="fa-solid fa-list-check w-4 text-amber-500"></i> Serviços & Pacotes
                        </a>
                        <a href="produtos.php" class="flex items-center gap-3 px-3 py-2 rounded-xl transition text-xs font-semibold <?= $paginaAtual === 'produtos.php' ? 'bg-amber-500/10 text-amber-400 border border-amber-500/30' : 'text-zinc-300 hover:bg-zinc-800 hover:text-white' ?>">
                            <i class="fa-solid fa-boxes-stacked w-4 text-amber-500"></i> Produtos & Estoque
                        </a>
                    </div>
                </div>

                <!-- BLOCO 4: RELATÓRIOS & SISTEMA -->
                <div>
                    <span class="text-[10px] font-extrabold uppercase tracking-widest text-zinc-500 px-3 block mb-1.5 flex items-center gap-1.5">
                        <i class="fa-solid fa-chart-pie text-amber-500"></i> Relatórios & Sistema
                    </span>
                    <div class="space-y-1">
                        <a href="financeiro.php" class="flex items-center gap-3 px-3 py-2 rounded-xl transition text-xs font-semibold <?= $paginaAtual === 'financeiro.php' ? 'bg-amber-500/10 text-amber-400 border border-amber-500/30' : 'text-zinc-300 hover:bg-zinc-800 hover:text-white' ?>">
                            <i class="fa-solid fa-wallet w-4 text-amber-500"></i> Financeiro do Mês
                        </a>
                        <a href="auto_tester.php" class="flex items-center gap-3 px-3 py-2 rounded-xl transition text-xs font-semibold <?= $paginaAtual === 'auto_tester.php' ? 'bg-amber-500/10 text-amber-400 border border-amber-500/30' : 'text-zinc-300 hover:bg-zinc-800 hover:text-white' ?>">
                            <i class="fa-solid fa-vial-circle-check w-4 text-amber-500"></i> Auto Tester
                        </a>
                    </div>
                </div>
            </nav>
        </div>

                        <a href="auto_tester.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition text-sm font-medium <?= $paginaAtual === 'auto_tester.php' ? 'bg-amber-500/10 text-amber-400 border border-amber-500/30' : 'text-zinc-300 hover:bg-zinc-800 hover:text-white' ?>">
                    <i class="fa-solid fa-vial-circle-check w-5 text-amber-500"></i> Auto Tester
                </a>
            </nav>
        </div>

        <!-- Rodapé do Menu (Usuário e Logout) -->
        <div class="border-t border-zinc-800 pt-4 mt-6">
            <p class="text-[11px] uppercase tracking-wider text-zinc-500 mb-1">Conectado como:</p>
            <p class="text-sm font-semibold truncate text-white mb-3"><?= htmlspecialchars($_SESSION['usuario_nome'] ?? 'Admin') ?></p>
            <a href="logout.php" class="flex items-center gap-2 text-xs text-red-400 hover:text-red-300 transition">
                <i class="fa-solid fa-arrow-right-from-bracket"></i> Encerrar Sessão
            </a>
        </div>
    </aside>

    <!-- JavaScript para Toggle do Menu Mobile -->
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const btnMenuMobile = document.getElementById('btnMenuMobile');
        const btnFecharMobile = document.getElementById('btnFecharMobile');
        const sidebarMenu = document.getElementById('sidebarMenu');
        const menuOverlay = document.getElementById('menuOverlay');

        function abrirMenu() {
            sidebarMenu.classList.remove('-translate-x-full');
            menuOverlay.classList.remove('hidden');
        }

        function fecharMenu() {
            sidebarMenu.classList.add('-translate-x-full');
            menuOverlay.classList.add('hidden');
        }

        if (btnMenuMobile) btnMenuMobile.addEventListener('click', abrirMenu);
        if (btnFecharMobile) btnFecharMobile.addEventListener('click', fecharMenu);
        if (menuOverlay) menuOverlay.addEventListener('click', fecharMenu);
    });
    </script>

    <!-- Área de Conteúdo Principal -->
    <main class="flex-1 p-4 sm:p-6 md:p-8 overflow-y-auto w-full max-w-full">
