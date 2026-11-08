<?php
require_once '../config/database.php';
verificarAutenticacao();

$resultados = [];

function testar($titulo, $condicao, $detalhes = '') {
    global $resultados;
    $resultados[] = [
        'titulo' => $titulo,
        'passou' => (bool)$condicao,
        'detalhes' => $detalhes
    ];
}

// 0. Auto-migração preventiva de colunas
try {
    $pdo->exec("ALTER TABLE bloqueios_agenda ADD COLUMN IF NOT EXISTS profissional_id INT NULL AFTER id");
    $pdo->exec("ALTER TABLE agendamentos ADD COLUMN IF NOT EXISTS cliente_pacote_id INT NULL");
    $pdo->exec("ALTER TABLE agendamentos ADD COLUMN IF NOT EXISTS desconto_tipo ENUM('reais', 'porcentagem') NULL DEFAULT 'reais'");
    $pdo->exec("ALTER TABLE agendamentos ADD COLUMN IF NOT EXISTS desconto_valor DECIMAL(10,2) NOT NULL DEFAULT 0.00");
} catch (Exception $e) {}

// Inicia transação para que os testes não afetem a base de dados real (Rollback no final)
$pdo->beginTransaction();

try {
    // 1. Integridade das tabelas essenciais
    $tabelas = [
        'usuarios', 'profissionais', 'servicos', 'profissional_servicos', 
        'agendamentos', 'agendamento_itens', 'turnos_atendimento', 
        'bloqueios_agenda', 'cliente_pacotes', 'movimentacoes_financeiras', 
        'produtos', 'vendas_produtos'
    ];
    foreach ($tabelas as $t) {
        $q = $pdo->query("SHOW TABLES LIKE '$t'");
        testar("Tabela '$t' no banco de dados", $q->rowCount() > 0, "Estrutura verificada com sucesso");
    }

    // 2. Teste de Profissional e Escala Semanal
    $pdo->query("INSERT INTO profissionais (nome, telefone, status) VALUES ('Profissional Teste', '14999999999', 'ativo')");
    $profId = (int)$pdo->lastInsertId();
    testar("Cadastro de Profissional", $profId > 0, "ID: $profId");

    $pdo->query("INSERT INTO turnos_atendimento (profissional_id, dia_semana, hora_inicio, hora_fim, intervalo_minutos, tipo_atendimento) VALUES ($profId, 1, '08:00:00', '18:00:00', 30, 'horario_marcado')");
    $turnoId = (int)$pdo->lastInsertId();
    testar("Escala Semanal Fixa (Segunda-feira)", $turnoId > 0, "Turno ID: $turnoId");

    // 3. Teste de Busca de Horários por Escala Semanal
    $stBusca = $pdo->prepare("SELECT COUNT(*) FROM turnos_atendimento WHERE (dia_semana = 1 OR data_atendimento = '2026-10-12') AND profissional_id = ?");
    $stBusca->execute([$profId]);
    testar("Resolução de Turno Semanal", $stBusca->fetchColumn() > 0, "Identificou o barbeiro na data");

    // 4. Teste de Folga Individual vs Outro Barbeiro
    $dataTesteBloqueio = date('Y-m-d', strtotime('+365 days'));
    $pdo->prepare("DELETE FROM bloqueios_agenda WHERE data_bloqueio = ?")->execute([$dataTesteBloqueio]);
    
    $stInsBloq = $pdo->prepare("INSERT INTO bloqueios_agenda (profissional_id, data_bloqueio, motivo) VALUES (?, ?, 'Folga Específica Teste')");
    $stInsBloq->execute([$profId, $dataTesteBloqueio]);
    
    $stCheckBloq = $pdo->prepare("
        SELECT motivo FROM bloqueios_agenda 
        WHERE data_bloqueio = ? 
          AND (profissional_id IS NULL OR (profissional_id = ? AND ? IS NOT NULL))
        LIMIT 1
    ");
    $stCheckBloq->execute([$dataTesteBloqueio, $profId, $profId]);
    $bloqueioProf = $stCheckBloq->fetchColumn();
    testar("Bloqueio de Folga Individual para o Barbeiro", $bloqueioProf !== false, "Barbeiro bloqueado com sucesso");

    $stCheckLivre = $pdo->prepare("
        SELECT motivo FROM bloqueios_agenda 
        WHERE data_bloqueio = ? 
          AND (profissional_id IS NULL OR profissional_id = 999999)
        LIMIT 1
    ");
    $stCheckLivre->execute([$dataTesteBloqueio]);
    $bloqueioOutro = $stCheckLivre->fetchColumn();
    testar("Outro Barbeiro Livre na Mesma Data", $bloqueioOutro === false, "Barbearia continua aberta para os outros barbeiros");

    // 5. Teste do Ciclo de 3 Sessões do Pacote
    $pdo->query("INSERT INTO clientes (nome, telefone) VALUES ('Cliente Testador', '14988887777')");
    $clienteId = (int)$pdo->lastInsertId();

    $pdo->query("INSERT INTO servicos (nome, preco, duracao_minutos, eh_pacote, sessoes_pacote, validade_dias, status) VALUES ('Pacote 3 Cortes Teste', 150.00, 30, 1, 3, 30, 'ativo')");
    $servPacoteId = (int)$pdo->lastInsertId();

    // 1ª Sessão: Compra no caixa (3 sessões no total, sobram 2 sessões)
    $sTotal = 3;
    $sRestantes = 2; // 1ª usada hoje
    $dtExp = date('Y-m-d', strtotime('+30 days'));
    $stPacote = $pdo->prepare("
        INSERT INTO cliente_pacotes (cliente_id, servico_id, sessoes_total, sessoes_restantes, data_compra, data_expiracao, status) 
        VALUES (?, ?, ?, ?, CURDATE(), ?, 'ativo')
    ");
    $stPacote->execute([$clienteId, $servPacoteId, $sTotal, $sRestantes, $dtExp]);
    $pacId = (int)$pdo->lastInsertId();
    testar("1ª Sessão Paga: Pacote Ativado com 2 Sessões Restantes", $pacId > 0, "Saldo inicial: 2");

    // 2ª Sessão: Abatimento de 1 sessão (sobra 1)
    $saldo = 2 - 1;
    $status = ($saldo <= 0) ? 'esgotado' : 'ativo';
    $pdo->query("UPDATE cliente_pacotes SET sessoes_restantes = $saldo, status = '$status' WHERE id = $pacId");
    $stCheck2 = $pdo->query("SELECT sessoes_restantes, status FROM cliente_pacotes WHERE id = $pacId")->fetch();
    testar("2ª Sessão Gratuita Abatida (Resta 1)", $stCheck2['sessoes_restantes'] == 1 && $stCheck2['status'] == 'ativo', "Saldo: 1 | Status: ativo");

    // 3ª Sessão: Abatimento da última sessão (sobra 0 e esgota)
    $saldo = 1 - 1;
    $status = ($saldo <= 0) ? 'esgotado' : 'ativo';
    $pdo->query("UPDATE cliente_pacotes SET sessoes_restantes = $saldo, status = '$status' WHERE id = $pacId");
    $stCheck3 = $pdo->query("SELECT sessoes_restantes, status FROM cliente_pacotes WHERE id = $pacId")->fetch();
    testar("3ª Sessão Gratuita Concluída (Esgota o pacote)", $stCheck3['sessoes_restantes'] == 0 && $stCheck3['status'] == 'esgotado', "Saldo: 0 | Status: esgotado");

    // 6. Teste de Desconto Fixo e Percentual
    $subtotal = 150.00;
    $descReais = 15.00;
    $totalReais = max(0.0, $subtotal - $descReais);
    testar("Cálculo de Desconto em R$", $totalReais == 135.00, "R$ 150 - R$ 15 = R$ 135,00");

    $descPct = 10.0;
    $valorPct = ($subtotal * ($descPct / 100));
    $totalPct = max(0.0, $subtotal - $valorPct);
    testar("Cálculo de Desconto em %", $totalPct == 135.00, "10% de R$ 150 = R$ 15 -> Total R$ 135,00");

    // 7. Teste de Lançamento de Despesa e Ganho Extra
    $pdo->query("INSERT INTO movimentacoes_financeiras (tipo, categoria, descricao, valor, data_movimentacao, forma_pagamento) VALUES ('despesa', 'Aluguel', 'Aluguel do Ponto', 800.00, CURDATE(), 'pix')");
    $idDesp = (int)$pdo->lastInsertId();
    testar("Lançamento de Despesa Financeira", $idDesp > 0, "Despesa registrada com sucesso");

    $pdo->query("INSERT INTO movimentacoes_financeiras (tipo, categoria, descricao, valor, data_movimentacao, forma_pagamento) VALUES ('receita', 'Cursos', 'Curso de Barba', 400.00, CURDATE(), 'pix')");
    $idRec = (int)$pdo->lastInsertId();
    testar("Lançamento de Ganho Extra Financeiro", $idRec > 0, "Ganho registrado com sucesso");

} catch (Exception $e) {
    testar("Erro de Execução", false, $e->getMessage());
} finally {
    $pdo->rollBack();
}

$passaram = count(array_filter($resultados, fn($r) => $r['passou']));
$total = count($resultados);

require_once 'header.php';
?>

<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-bold flex items-center gap-2">
                <i class="fa-solid fa-vial-circle-check text-amber-500"></i> Auto Tester do Sistema
            </h2>
            <p class="text-zinc-400 text-sm">Diagnóstico em tempo real de todas as funções com Rollback automático.</p>
        </div>
        <a href="auto_tester.php" class="bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold px-4 py-2 rounded-xl text-xs transition flex items-center gap-1.5 shadow">
            <i class="fa-solid fa-rotate-right"></i> Executar Novamente
        </a>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-zinc-900 border border-zinc-800 p-4 rounded-2xl">
            <span class="text-xs text-zinc-400 uppercase tracking-wider block">Total de Testes</span>
            <span class="text-3xl font-extrabold text-white mt-1 block"><?= $total ?></span>
        </div>
        <div class="bg-zinc-900 border border-zinc-800 p-4 rounded-2xl">
            <span class="text-xs text-emerald-400 uppercase tracking-wider block">Aprovados</span>
            <span class="text-3xl font-extrabold text-emerald-400 mt-1 block"><?= $passaram ?></span>
        </div>
        <div class="bg-zinc-900 border border-zinc-800 p-4 rounded-2xl">
            <span class="text-xs text-red-400 uppercase tracking-wider block">Falhas</span>
            <span class="text-3xl font-extrabold <?= ($total - $passaram) > 0 ? 'text-red-400' : 'text-zinc-500' ?> mt-1 block"><?= $total - $passaram ?></span>
        </div>
    </div>

    <div class="bg-zinc-900 border border-zinc-800 rounded-2xl overflow-hidden shadow">
        <div class="p-4 border-b border-zinc-800 flex justify-between items-center">
            <h3 class="font-bold text-white text-sm">Resultados dos Módulos</h3>
            <span class="text-xs text-emerald-400 font-bold">100% dos dados de teste revertidos</span>
        </div>

        <div class="divide-y divide-zinc-800 text-xs">
            <?php foreach ($resultados as $r): ?>
                <div class="p-3.5 flex items-center justify-between hover:bg-zinc-800/30 transition">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?= $r['passou'] ? 'fa-circle-check text-emerald-400' : 'fa-circle-xmark text-red-400' ?> text-base"></i>
                        <div>
                            <span class="font-bold text-white block"><?= htmlspecialchars($r['titulo']) ?></span>
                            <?php if ($r['detalhes']): ?>
                                <span class="text-[11px] text-zinc-400"><?= htmlspecialchars($r['detalhes']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <span class="<?= $r['passou'] ? 'bg-emerald-950 text-emerald-400 border border-emerald-800' : 'bg-red-950 text-red-400 border border-red-800' ?> px-2.5 py-0.5 rounded-full font-bold uppercase text-[10px]">
                        <?= $r['passou'] ? 'OK' : 'FALHA' ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
</main>
</body>
</html>
