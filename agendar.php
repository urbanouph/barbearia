<?php
require_once 'config/database.php';

// Bloqueia utilizadores não autenticados
if (!isset($_SESSION['cliente_id'])) {
    header('Location: cliente_login.php');
    exit;
}

$clienteLogadoId = (int)$_SESSION['cliente_id'];
$clienteLogadoNome = $_SESSION['cliente_nome'] ?? '';
$clienteLogadoTelefone = $_SESSION['cliente_telefone'] ?? '';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
// Atualiza status de pacotes vencidos automaticamente
$pdo->query("UPDATE cliente_pacotes SET status = 'expirado' WHERE status = 'ativo' AND data_expiracao < CURDATE()");

// Garante que pacotes com saldo positivo e dentro da validade fiquem ativos
$pdo->query("UPDATE cliente_pacotes SET status = 'ativo' WHERE sessoes_restantes > 0 AND data_expiracao >= CURDATE()");

// Busca pacotes do cliente que ainda possuem sessões disponíveis e não expiraram
$stmtPacotesCliente = $pdo->prepare("
    SELECT cp.*, s.nome as servico_nome, s.duracao_minutos,
           DATEDIFF(cp.data_expiracao, CURDATE()) as dias_restantes
    FROM cliente_pacotes cp 
    JOIN servicos s ON cp.servico_id = s.id 
    WHERE cp.cliente_id = ? 
      AND cp.sessoes_restantes > 0 
      AND cp.data_expiracao >= CURDATE()
    ORDER BY cp.data_expiracao ASC
");
$stmtPacotesCliente->execute([$clienteLogadoId]);
$meusPacotesAtivos = $stmtPacotesCliente->fetchAll();

// Mapa indexado por servico_id para busca instantânea
$mapaPacotePorServico = [];
foreach ($meusPacotesAtivos as $pAtivo) {
    $mapaPacotePorServico[(int)$pAtivo['servico_id']] = $pAtivo;
}

$mensagemSucesso = '';
$mensagemErro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['realizar_agendamento']) && !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
    $mensagemErro = 'Sua sessão expirou. Atualize a página e tente novamente.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['realizar_agendamento'])) {
    $clienteNome = trim($_POST['cliente_nome']);
    $clienteTelefone = trim($_POST['cliente_telefone']);
    $profissionalId = !empty($_POST['profissional_id']) ? (int)$_POST['profissional_id'] : null;
    $dataAgendamento = $_POST['data_agendamento'] ?? '';
    $horario = (string)($_POST['horario'] ?? '');

    // Múltiplos serviços selecionados
    $servicosInput = $_POST['servico_id'] ?? '';
    $servicosIdsSelecionados = array_values(array_unique(array_filter(array_map('intval', explode(',', strval($servicosInput))), static fn($id) => $id > 0)));

    $produtosQuantidadeInput = $_POST['produto_quantidade'] ?? [];
    $produtosSolicitados = [];
    if (is_array($produtosQuantidadeInput)) {
        foreach ($produtosQuantidadeInput as $produtoIdInput => $quantidadeInput) {
            $produtoIdInput = (int)$produtoIdInput;
            $quantidadeInput = (int)$quantidadeInput;
            if ($produtoIdInput > 0 && $quantidadeInput > 0) {
                $produtosSolicitados[$produtoIdInput] = $quantidadeInput;
            }
        }
    }
    $servicoId = !empty($servicosIdsSelecionados) ? $servicosIdsSelecionados[0] : 0;

        $dataValida = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAgendamento) === 1
            && checkdate((int)substr($dataAgendamento, 5, 2), (int)substr($dataAgendamento, 8, 2), (int)substr($dataAgendamento, 0, 4));
        $horaValida = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $horario) === 1;
        if (!$dataValida || !$horaValida) {
        $mensagemErro = 'Por favor, selecione uma data e um horário válido disponível.';
    } elseif (!$profissionalId) {
        $mensagemErro = 'Selecione um profissional para continuar.';
    } elseif (empty($servicosIdsSelecionados)) {
        $mensagemErro = 'Selecione pelo menos um serviço desejado.';
    } else {
        $placeholders = implode(',', array_fill(0, count($servicosIdsSelecionados), '?'));
        $stmtServs = $pdo->prepare("SELECT id, nome, duracao_minutos, preco, eh_pacote, incompativeis FROM servicos WHERE id IN ($placeholders) AND status = 'ativo' AND EXISTS (SELECT 1 FROM profissional_servicos ps JOIN profissionais pr ON pr.id = ps.profissional_id WHERE ps.servico_id = servicos.id AND ps.profissional_id = ? AND pr.status = 'ativo')");
        $stmtServs->execute(array_merge($servicosIdsSelecionados, [$profissionalId]));
        $servicosEscolhidos = $stmtServs->fetchAll();

        if (count($servicosEscolhidos) !== count($servicosIdsSelecionados)) {
            $mensagemErro = 'Um ou mais serviços selecionados são inválidos.';
        } else {
            // Validação de regras de incompatibilidade no backend
            $erroIncompativel = false;
            foreach ($servicosEscolhidos as $sA) {
                $incA = !empty($sA['incompativeis']) ? json_decode($sA['incompativeis'], true) : [];
                if (is_array($incA)) {
                    foreach ($servicosEscolhidos as $sB) {
                        if ($sA['id'] !== $sB['id'] && in_array((int)$sB['id'], $incA)) {
                            $mensagemErro = "Os serviços '{$sA['nome']}' e '{$sB['nome']}' não podem ser marcados juntos!";
                            $erroIncompativel = true;
                            break 2;
                        }
                    }
                }
            }

            if (!$erroIncompativel) {
                $duracao = array_sum(array_column($servicosEscolhidos, 'duracao_minutos'));
                $valorTotalServicos = array_sum(array_column($servicosEscolhidos, 'preco'));
                $novoInicio = strtotime("$dataAgendamento $horario:00");
                $novoFim = $novoInicio + ($duracao * 60);

                $diaSemanaNum = (int)date('w', strtotime($dataAgendamento));
                $sqlValidaTurno = "
                    SELECT * FROM turnos_atendimento
                    WHERE (
                        (dia_semana = ? AND (data_atendimento IS NULL OR data_atendimento = ?)
                            AND (? > CURDATE() OR (? = CURDATE() AND hora_fim >= CURTIME())))
                        OR (data_atendimento = ? AND (data_atendimento > CURDATE() OR (data_atendimento = CURDATE() AND hora_fim >= CURTIME())))
                    )
                ";
                $paramsValidaTurno = [$diaSemanaNum, $dataAgendamento, $dataAgendamento, $dataAgendamento, $dataAgendamento];

                if ($profissionalId) {
                    $sqlValidaTurno .= " AND (profissional_id = ? OR profissional_id IS NULL)";
                    $paramsValidaTurno[] = $profissionalId;
                }

                $stmtTurnos = $pdo->prepare($sqlValidaTurno);
                $stmtTurnos->execute($paramsValidaTurno);
                $turnos = $stmtTurnos->fetchAll();

                $dentroDeTurno = false;
                $turnoOrdemChegada = false;
                foreach ($turnos as $t) {
                    if (($t['tipo_atendimento'] ?? 'horario_marcado') === 'ordem_chegada') {
                        $turnoOrdemChegada = true;
                        continue;
                    }

                    $tIni = strtotime("$dataAgendamento {$t['hora_inicio']}");
                    $tFim = strtotime("$dataAgendamento {$t['hora_fim']}");

                    if ($novoInicio >= $tIni && $novoFim <= $tFim) {
                        if (!empty($t['pausa_inicio']) && !empty($t['pausa_fim'])) {
                            $pIni = strtotime("$dataAgendamento {$t['pausa_inicio']}");
                            $pFim = strtotime("$dataAgendamento {$t['pausa_fim']}");
                            if ($novoInicio < $pFim && $novoFim > $pIni) {
                                continue;
                            }
                        }
                        $dentroDeTurno = true;
                        break;
                    }
                }

                if ($turnoOrdemChegada) {
                    $mensagemErro = 'Nesta data o atendimento é por Ordem de Chegada. Não é possível reservar um horário; compareça à barbearia durante o período informado.';
                } elseif (!$dentroDeTurno) {
                    $mensagemErro = 'O horário escolhido não está compreendido em nenhum turno válido.';
                } else {
                    $sqlConflito = "
                        SELECT a.horario, s.duracao_minutos 
                        FROM agendamentos a 
                        JOIN servicos s ON a.servico_id = s.id 
                        WHERE a.data_agendamento = ? AND a.status != 'cancelado'
                    ";
                    $paramsConflito = [$dataAgendamento];

                    if ($profissionalId) {
                        $sqlConflito .= " AND (a.profissional_id = ? OR a.profissional_id IS NULL)";
                        $paramsConflito[] = $profissionalId;
                    }

                    $stmtOcup = $pdo->prepare($sqlConflito);
                    $stmtOcup->execute($paramsConflito);
                    $marcados = $stmtOcup->fetchAll();

                    $colidiu = false;
                    foreach ($marcados as $m) {
                        $mIni = strtotime("$dataAgendamento {$m['horario']}");
                        $mFim = $mIni + ($m['duracao_minutos'] * 60);
                        if ($novoInicio < $mFim && $novoFim > $mIni) {
                            $colidiu = true;
                            break;
                        }
                    }

                    if ($colidiu) {
                        $mensagemErro = 'Este horário com o profissional escolhido já está ocupado.';
                    } else {
                        $pdo->beginTransaction();
                        try {
                            $pacoteAtivoExistente = $mapaPacotePorServico[(int)$servicoId] ?? null;
                            $pacoteUtilizadoId = null;
                            $servicoCobertoPorPacote = null;
                            $valorServicosCobrado = (float)$valorTotalServicos;
                            $precoServicoPrincipal = 0.0;
                            foreach ($servicosEscolhidos as $servicoAtual) {
                                if ((int)$servicoAtual['id'] === (int)$servicoId) {
                                    $precoServicoPrincipal = (float)$servicoAtual['preco'];
                                    break;
                                }
                            }

                            if ($pacoteAtivoExistente && $pacoteAtivoExistente['sessoes_restantes'] > 0) {
                                $pacoteUtilizadoId = (int)$pacoteAtivoExistente['id'];
                                $servicoCobertoPorPacote = (int)$servicoId;
                                $valorServicosCobrado = max(0, $valorServicosCobrado - $precoServicoPrincipal);
                            }

                            $produtosEscolhidos = [];
                            $totalProdutos = 0.0;
                            $stmtProduto = $pdo->prepare("SELECT id, nome, preco_venda, quantidade_estoque FROM produtos WHERE id = ? AND status = 'ativo' AND mostrar_no_agendamento = 1 FOR UPDATE");
                            foreach ($produtosSolicitados as $produtoId => $quantidadeProduto) {
                                $stmtProduto->execute([$produtoId]);
                                $produto = $stmtProduto->fetch();
                                if (!$produto || (int)$produto['quantidade_estoque'] < $quantidadeProduto) {
                                    throw new RuntimeException('Um produto selecionado não está mais disponível na quantidade pedida. Atualize a página e tente novamente.');
                                }
                                $produto['quantidade_pedida'] = $quantidadeProduto;
                                $produtosEscolhidos[] = $produto;
                                $totalProdutos += (float)$produto['preco_venda'] * $quantidadeProduto;
                            }
                            $valorAgendamento = max(0, $valorServicosCobrado + $totalProdutos);

                            $stmtInsert = $pdo->prepare("
                            INSERT INTO agendamentos (cliente_id, profissional_id, cliente_nome, cliente_telefone, servico_id, data_agendamento, horario, status, cliente_pacote_id, valor_total) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'pendente', ?, ?)
                        ");
                        $stmtInsert->execute([$clienteLogadoId, $profissionalId, $clienteNome, $clienteTelefone, $servicoId, $dataAgendamento, $horario, $pacoteUtilizadoId, $valorAgendamento]);
                        $novoAgendamentoId = (int)$pdo->lastInsertId();

                        $stmtItem = $pdo->prepare("
                            INSERT INTO agendamento_itens (agendamento_id, tipo, item_id, nome_item, quantidade, preco_unitario, subtotal) 
                            VALUES (?, 'servico', ?, ?, 1, ?, ?)
                        ");
                            foreach ($servicosEscolhidos as $sItem) {
                                $precoItem = (int)$sItem['id'] === $servicoCobertoPorPacote ? 0.0 : (float)$sItem['preco'];
                                $stmtItem->execute([$novoAgendamentoId, $sItem['id'], $sItem['nome'], $precoItem, $precoItem]);
                            }

                            $stmtItemProduto = $pdo->prepare("INSERT INTO agendamento_itens (agendamento_id, tipo, item_id, nome_item, quantidade, preco_unitario, subtotal) VALUES (?, 'produto', ?, ?, ?, ?, ?)");
                            $stmtBaixaProduto = $pdo->prepare("UPDATE produtos SET quantidade_estoque = quantidade_estoque - ? WHERE id = ? AND status = 'ativo' AND mostrar_no_agendamento = 1 AND quantidade_estoque >= ?");
                            foreach ($produtosEscolhidos as $produto) {
                                $qtd = (int)$produto['quantidade_pedida'];
                                $preco = (float)$produto['preco_venda'];
                                $stmtBaixaProduto->execute([$qtd, $produto['id'], $qtd]);
                                if ($stmtBaixaProduto->rowCount() !== 1) {
                                    throw new RuntimeException('O estoque de um produto mudou durante a reserva. Atualize a página e tente novamente.');
                                }
                                $stmtItemProduto->execute([$novoAgendamentoId, $produto['id'], $produto['nome'], $qtd, $preco, $preco * $qtd]);
                            }

                            $pdo->commit();
                            $dataFormatada = date('d/m/Y', strtotime($dataAgendamento));
                            $mensagemSucesso = "Agendamento marcado com sucesso para o dia <strong>{$dataFormatada}</strong> às <strong>{$horario}</strong> (" . count($servicosEscolhidos) . " serviços)!";
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            $mensagemErro = $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível concluir o agendamento. Tente novamente.';
                        }
                    }
                }
            }
        }
    }
}

// Buscar profissionais ativos
$profissionais = $pdo->query("SELECT * FROM profissionais WHERE status = 'ativo' ORDER BY nome ASC")->fetchAll();

// Buscar todos os serviços ativos
$todosServicos = $pdo->query("SELECT * FROM servicos WHERE status = 'ativo' ORDER BY nome ASC")->fetchAll();
$produtosAgendamento = $pdo->query("SELECT id, nome, descricao, preco_venda, quantidade_estoque FROM produtos WHERE status = 'ativo' AND mostrar_no_agendamento = 1 AND quantidade_estoque > 0 ORDER BY nome ASC")->fetchAll();

// Buscar mapa de serviços de cada profissional
$mapaServicos = [];
$stmtVincs = $pdo->query("SELECT profissional_id, servico_id FROM profissional_servicos")->fetchAll();
foreach ($stmtVincs as $v) {
    $mapaServicos[$v['profissional_id']][] = (int)$v['servico_id'];
}

// Busca os dias da semana ativos na escala geral
$diasSemanaAtivos = $pdo->query("SELECT DISTINCT dia_semana FROM turnos_atendimento WHERE dia_semana IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);

// Busca datas bloqueadas para não exibir na seleção
$datasBloqueadas = $pdo->query("SELECT data_bloqueio FROM bloqueios_agenda WHERE data_bloqueio >= CURDATE()")->fetchAll(PDO::FETCH_COLUMN);

// Gera os próximos dias que estejam realmente acessíveis com expediente futuro
$diasDisponiveis = [];
$horaAtual = date('H:i:s');
$hojeData = date('Y-m-d');

for ($i = 0; $i < 21; $i++) {
    $dataCalc = date('Y-m-d', strtotime("+$i days"));
    $diaW = (int)date('w', strtotime($dataCalc));

    // Se o dia da semana não faz parte da escala de atendimento, ignora
    if (!empty($diasSemanaAtivos) && !in_array($diaW, $diasSemanaAtivos)) {
        continue;
    }

    // Se a data estiver explicitamente bloqueada ou de folga, ignora
    if (in_array($dataCalc, $datasBloqueadas)) {
        continue;
    }

    // Se a data for hoje, verifica se o expediente ainda não encerrou completamente
    if ($dataCalc === $hojeData) {
        $stmtAindaAberto = $pdo->prepare("
            SELECT COUNT(*) FROM turnos_atendimento 
            WHERE ((dia_semana = ? AND (data_atendimento IS NULL OR data_atendimento = ?)) OR data_atendimento = ?)
              AND hora_fim > ?
        ");
        $stmtAindaAberto->execute([$diaW, $dataCalc, $dataCalc, $horaAtual]);
        $temVagaHoje = (int)$stmtAindaAberto->fetchColumn();

        // Se o expediente de hoje já encerrou (ex: já passou das 18:00), remove o dia de hoje da lista!
        if ($temVagaHoje === 0) {
            continue;
        }
    }

    $diasDisponiveis[] = $dataCalc;
}

$diasSemanaNomes = [0 => 'Dom', 1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb'];
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marcação de Horário - Barbearia</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="assets/logo.png">
    <link rel="apple-touch-icon" href="assets/logo.png">
</head>
<body class="bg-zinc-950 text-zinc-100 min-h-screen flex items-center justify-center p-4">

    <div class="max-w-md w-full bg-zinc-900 border border-zinc-800 rounded-2xl p-6 sm:p-8 shadow-2xl my-8">
        
        <!-- Barra de Estado do Utilizador Conectado -->
        <div class="flex items-center justify-between pb-4 mb-4 border-b border-zinc-800 text-xs">
            <span class="text-zinc-400">Olá, <strong class="text-white"><?= htmlspecialchars($clienteLogadoNome) ?></strong></span>
            <div class="flex items-center gap-2 shrink-0">
                <a href="meus_agendamentos.php" class="text-amber-500 hover:underline font-semibold flex items-center gap-1">
                    <i class="fa-solid fa-list-check"></i> Horários
                </a>
                <span class="text-zinc-700">|</span>
                <a href="perfil.php" class="text-zinc-300 hover:text-white font-medium flex items-center gap-1">
                    <i class="fa-solid fa-user-gear"></i> Perfil
                </a>
                <span class="text-zinc-700">|</span>
                <a href="logout_cliente.php" class="text-zinc-400 hover:text-red-400 transition" title="Terminar Sessão">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i>
                </a>
            </div>
        </div>

        <div class="text-center mb-6">
            <div class="inline-flex p-1 bg-amber-500/10 rounded-full mb-3 shadow-xl border border-amber-500/40">
                <img src="assets/logo.png" alt="Shift Barber" class="w-24 h-24 rounded-full object-cover shadow-2xl">
            </div>
            <h1 class="text-2xl font-extrabold tracking-wider text-white">SHIFT BARBER</h1>
            <p class="text-xs text-zinc-400 mt-1">Escolha o profissional, o serviço e o melhor horário.</p>
        </div>

        <?php if ($mensagemSucesso): ?>
            <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 p-4 rounded-xl text-xs mb-6 text-center">
                <i class="fa-solid fa-circle-check text-base block mb-1"></i>
                <?= $mensagemSucesso ?>
                <a href="meus_agendamentos.php" class="block mt-2 text-xs text-amber-400 underline font-semibold">
                    Ver na minha área de horários &rarr;
                </a>
            </div>
        <?php endif; ?>

        <?php if ($mensagemErro): ?>
            <div class="bg-red-500/10 border border-red-500/30 text-red-400 p-4 rounded-xl text-xs mb-6 text-center">
                <i class="fa-solid fa-triangle-exclamation text-base block mb-1"></i>
                <?= htmlspecialchars($mensagemErro) ?>
            </div>
        <?php endif; ?>


        <?php if (!empty($meusPacotesAtivos)): ?>
            <div class="mb-5 bg-amber-500/10 border-2 border-amber-500/40 p-4 rounded-2xl space-y-2.5 shadow-lg shadow-amber-500/5">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-extrabold text-amber-400 flex items-center gap-2 uppercase tracking-wider">
                        <i class="fa-solid fa-box-archive text-sm"></i> Pacote Ativo em Aberto
                    </span>
                    <span class="bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 text-[10px] font-extrabold px-2 py-0.5 rounded-full uppercase">
                        Sessões Gratuitas (R$ 0,00)
                    </span>
                </div>
                
                <?php foreach ($meusPacotesAtivos as $pac): 
                    $diasFaltam = max(0, (int)$pac['dias_restantes']);
                ?>
                    <div class="bg-zinc-900/90 p-3 rounded-xl border border-zinc-800 flex flex-col sm:flex-row sm:items-center justify-between gap-2.5 text-xs">
                        <div>
                            <strong class="text-white text-sm block"><?= htmlspecialchars($pac['servico_nome']) ?></strong>
                            <div class="flex flex-wrap items-center gap-2 text-[11px] text-zinc-400 mt-0.5">
                                <span>Saldo: <strong class="text-amber-400 font-bold"><?= $pac['sessoes_restantes'] ?> de <?= $pac['sessoes_total'] ?> sessões restantes</strong></span>
                                <span>•</span>
                                <span>Validade: <strong class="text-zinc-200"><?= $diasFaltam ?> dia<?= $diasFaltam === 1 ? '' : 's' ?> para acabar</strong> (Vence em <?= date('d/m/Y', strtotime($pac['data_expiracao'])) ?>)</span>
                            </div>
                        </div>
                        <div class="shrink-0">
                            <span class="inline-flex items-center gap-1.5 text-[11px] font-extrabold text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 px-3 py-1.5 rounded-lg">
                                <i class="fa-solid fa-tag"></i> Próximos Cortes: R$ 0,00
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="formAgendamento" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <div>
                <label class="block text-xs font-semibold text-zinc-400 uppercase tracking-wider mb-1.5">Seu Nome</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-zinc-500">
                        <i class="fa-solid fa-user text-xs"></i>
                    </span>
                    <input type="text" name="cliente_nome" value="<?= htmlspecialchars($clienteLogadoNome) ?>" required 
                        class="w-full bg-zinc-800 border border-zinc-700 rounded-xl py-2 pl-9 pr-3 text-white text-xs focus:outline-none focus:border-amber-500">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-400 uppercase tracking-wider mb-1.5">WhatsApp / Celular</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-zinc-500">
                        <i class="fa-solid fa-phone text-xs"></i>
                    </span>
                    <input type="tel" name="cliente_telefone" value="<?= htmlspecialchars($clienteLogadoTelefone) ?>" placeholder="(11) 99999-9999" required 
                        class="w-full bg-zinc-800 border border-zinc-700 rounded-xl py-2 pl-9 pr-3 text-white text-xs focus:outline-none focus:border-amber-500">
                </div>
            </div>

            <!-- ESCOLHA DO PROFISSIONAL (BARBEIRO) -->
            <div>
                <label class="block text-xs font-semibold text-zinc-400 uppercase tracking-wider mb-1.5">Profissional / Barbeiro</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-zinc-500">
                        <i class="fa-solid fa-user-tie text-xs"></i>
                    </span>
                    <select name="profissional_id" id="campoProfissional" required
                        class="w-full bg-zinc-800 border border-zinc-700 rounded-xl py-2 pl-9 pr-3 text-white text-xs focus:outline-none focus:border-amber-500 appearance-none">
                        <option value="">Selecione o barbeiro...</option>
                        <?php foreach ($profissionais as $p): ?>
                            <option value="<?= $p['id'] ?>">
                                <?= htmlspecialchars($p['nome']) ?> (<?= htmlspecialchars($p['especialidade']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- SERVIÇOS EXECUTADOS POR ESSE PROFISSIONAL -->
            <!-- SELEÇÃO DE SERVIÇO EM CARDS VISUAIS (CONFORME A IMAGEM) -->
            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="block text-xs font-semibold text-zinc-400 uppercase tracking-wider">
                        <i class="fa-solid fa-scissors text-amber-500 mr-1"></i> Serviço Desejado
                    </label>
                    <span id="labelServicoSelecionadoInfo" class="text-[11px] text-amber-400 font-bold hidden"></span>
                </div>

                <!-- Input oculto que armazena o servico_id selecionado -->
                <input type="hidden" name="servico_id" id="campoServico" required>

                <!-- Estado Inicial: Nenhum profissional selecionado -->
                <div id="avisoSelecioneProfissional" class="p-4 rounded-2xl bg-zinc-800/50 border border-zinc-700/60 text-center text-xs text-zinc-400 flex flex-col items-center justify-center gap-1.5 py-6">
                    <i class="fa-solid fa-user-tie text-zinc-500 text-xl mb-0.5"></i>
                    <span>Selecione primeiro o profissional acima para ver os serviços e pacotes.</span>
                </div>

                <!-- Lista de Cards de Serviços -->
                <div id="containerCardsServicos" class="space-y-2.5 max-h-96 overflow-y-auto pr-1 hidden">
                    <!-- Os cards são gerados dinamicamente via JavaScript -->
                </div>
            </div>

            <?php if (!empty($produtosAgendamento)): ?>
                <section aria-labelledby="tituloProdutosAgendamento" class="rounded-2xl border border-zinc-800 bg-zinc-800/40 p-4 space-y-3">
                    <div>
                        <h2 id="tituloProdutosAgendamento" class="text-xs font-bold uppercase tracking-wider text-zinc-200">Produtos disponíveis (opcional)</h2>
                        <p class="mt-1 text-[11px] text-zinc-400">Escolha o que deseja reservar para o atendimento. A quantidade será conferida no estoque.</p>
                    </div>
                    <div class="space-y-2">
                        <?php foreach ($produtosAgendamento as $produto): ?>
                            <label class="flex items-center justify-between gap-3 rounded-xl border border-zinc-700 bg-zinc-900/80 p-3">
                                <span class="min-w-0">
                                    <span class="block text-xs font-semibold text-white"><?= htmlspecialchars($produto['nome'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if (!empty($produto['descricao'])): ?><span class="block text-[11px] text-zinc-400 mt-0.5"><?= htmlspecialchars($produto['descricao'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                    <span class="block text-[11px] text-amber-400 mt-1">R$ <?= number_format((float)$produto['preco_venda'], 2, ',', '.') ?> • <?= (int)$produto['quantidade_estoque'] ?> em estoque</span>
                                </span>
                                <span class="shrink-0 text-right">
                                    <span class="block text-[10px] text-zinc-400 mb-1">Qtd.</span>
                                    <input type="number" name="produto_quantidade[<?= (int)$produto['id'] ?>]" min="0" max="<?= (int)$produto['quantidade_estoque'] ?>" value="0" aria-label="Quantidade de <?= htmlspecialchars($produto['nome'], ENT_QUOTES, 'UTF-8') ?>" class="w-16 rounded-lg border border-zinc-700 bg-zinc-800 p-1.5 text-center text-xs text-white">
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <div>
                <label class="block text-xs font-semibold text-zinc-400 uppercase tracking-wider mb-1.5">Selecione o Dia</label>
                <input type="hidden" name="data_agendamento" id="campoData" required>

                <?php if (empty($diasDisponiveis)): ?>
                    <p class="text-xs text-zinc-500 py-1">Não há turnos cadastrados no momento.</p>
                <?php else: ?>
                    <div class="grid grid-cols-3 sm:grid-cols-4 gap-2 max-h-36 overflow-y-auto p-1">
                        <?php foreach ($diasDisponiveis as $dia): 
                            $timestamp = strtotime($dia);
                            $diaNum = date('d/m', $timestamp);
                            $semana = $diasSemanaNomes[(int)date('w', $timestamp)];
                        ?>
                            <button type="button" 
                                data-data="<?= $dia ?>"
                                class="botao-data py-1.5 px-1 text-center rounded-xl border border-zinc-700 bg-zinc-800 hover:border-amber-500 transition text-xs">
                                <span class="block text-[10px] text-zinc-400 uppercase font-semibold"><?= $semana ?></span>
                                <span class="font-bold text-white"><?= $diaNum ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- HORÁRIOS LIVRES -->
            <div>
                <label class="block text-xs font-semibold text-zinc-400 uppercase tracking-wider mb-1.5">Horário Disponível</label>
                <input type="hidden" name="horario" id="horarioSelecionado" required>
                
                <div id="statusCarregamento" class="text-xs text-zinc-500 py-1">
                    Selecione o profissional, o serviço e clique num dia.
                </div>

                <div id="gradeHorarios" class="grid grid-cols-4 gap-2 hidden max-h-40 overflow-y-auto p-1"></div>
            </div>

            <button type="submit" id="botaoConfirmarAgendamento" name="realizar_agendamento" disabled
                class="w-full mt-2 bg-amber-500 hover:bg-amber-600 text-zinc-950 font-bold py-2.5 px-4 rounded-xl text-xs transition flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                <i class="fa-solid fa-calendar-plus"></i> Confirmar Agendamento
            </button>
        </form>
    </div>

<script>
// Dados de catálogo e mapeamento passados pelo PHP
const todosServicos = <?= json_encode($todosServicos) ?>;
const mapaServicosProf = <?= json_encode($mapaServicos) ?>;
const mapaPacotesAtivos = <?= json_encode($mapaPacotePorServico) ?>;

const campoProfissional = document.getElementById('campoProfissional');
const campoServico = document.getElementById('campoServico');
const campoData = document.getElementById('campoData');
const gradeHorarios = document.getElementById('gradeHorarios');
const statusCarregamento = document.getElementById('statusCarregamento');
const horarioSelecionado = document.getElementById('horarioSelecionado');
const botoesData = document.querySelectorAll('.botao-data');

const escaparHtml = (valor) => String(valor ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

// Ao mudar o profissional, filtra os serviços que ele faz
// Ao mudar o profissional, filtra e renderiza os cards visuais de serviços (Imagem 2)
let servicosSelecionados = [];
let bloqueioOrdemChegada = false;
let requisicaoHorarios = 0;
const botaoConfirmarAgendamento = document.getElementById('botaoConfirmarAgendamento');
function atualizarBotaoConfirmar() {
    const pronto = Boolean(campoProfissional.value && campoServico.value && campoData.value && horarioSelecionado.value && !bloqueioOrdemChegada);
    botaoConfirmarAgendamento.disabled = !pronto;
}

// Ao mudar o profissional, filtra e renderiza os cards visuais de serviços com suporte a seleção múltipla e regras de incompatibilidade
campoProfissional.addEventListener('change', () => {
    const profId = parseInt(campoProfissional.value);
    const container = document.getElementById('containerCardsServicos');
    const aviso = document.getElementById('avisoSelecioneProfissional');
    const labelInfo = document.getElementById('labelServicoSelecionadoInfo');
    
    servicosSelecionados = [];
    campoServico.value = '';
    labelInfo.classList.add('hidden');
    labelInfo.textContent = '';
    container.innerHTML = '';

    if (!profId) {
        aviso.classList.remove('hidden');
        container.classList.add('hidden');
        carregarHorarios();
        return;
    }

    const servicosPermitidos = mapaServicosProf[profId] || [];
    const servicosFiltrados = todosServicos.filter(s => servicosPermitidos.includes(parseInt(s.id)));

    if (servicosFiltrados.length === 0) {
        aviso.classList.remove('hidden');
        aviso.innerHTML = '<i class="fa-solid fa-circle-exclamation text-amber-500 text-lg mb-1"></i><span>Este profissional não possui serviços atribuídos no momento.</span>';
        container.classList.add('hidden');
    } else {
        aviso.classList.add('hidden');
        container.classList.remove('hidden');

        servicosFiltrados.forEach(s => {
            const pac = mapaPacotesAtivos[s.id] || null;
            const ehPacoteAtivo = (pac && pac.sessoes_restantes > 0);
            
            const imagemUrl = (typeof s.imagem === 'string') ? s.imagem.trim() : '';
            const temImagem = imagemUrl !== '' && !/^javascript:/i.test(imagemUrl);
            const imgHtml = temImagem 
                ? `<img src="${escaparHtml(imagemUrl)}" alt="${escaparHtml(s.nome)}" 
                        onerror="this.remove()" 
                        class="w-14 h-14 sm:w-16 sm:h-16 rounded-xl object-cover shrink-0 border border-zinc-700/60 bg-zinc-800 shadow-sm">` 
                : '';

            let descricaoTexto = s.descricao && s.descricao.trim() !== '' 
                ? escaparHtml(s.descricao) 
                : (s.eh_pacote == 1 ? `Pacote com ${s.sessoes_pacote} sessões válidas por ${s.validade_dias} dias.` : 'Atendimento personalizado com padrão de qualidade.');

            const card = document.createElement('div');
            card.className = 'card-servico-item bg-zinc-900/90 hover:bg-zinc-800/80 border border-zinc-800 rounded-2xl p-3 cursor-pointer transition flex items-center justify-between gap-3 shadow group select-none';
            card.setAttribute('data-id', s.id);
            card.setAttribute('data-nome', String(s.nome ?? ''));

            let tagTipo = (s.eh_pacote == 1) 
                ? '<span class="bg-amber-500/10 text-amber-400 border border-amber-500/20 px-2 py-0.5 rounded-md text-[10px] font-bold">🎁 Pacote</span>' 
                : '<span class="bg-zinc-800 text-zinc-400 border border-zinc-700/60 px-2 py-0.5 rounded-md text-[10px] font-medium">💼 Serviço</span>';

            let precoHtml = '';
            if (ehPacoteAtivo) {
                precoHtml = `
                    <div class="text-right">
                        <span class="text-xs font-extrabold text-emerald-400 block">R$ 0,00</span>
                        <span class="text-[10px] font-bold text-amber-400 bg-amber-500/10 px-1.5 py-0.5 rounded border border-amber-500/20 inline-block mt-0.5">
                            Saldo: ${pac.sessoes_restantes}/${pac.sessoes_total}
                        </span>
                    </div>
                `;
            } else {
                precoHtml = `
                    <div class="text-right">
                        <span class="text-xs sm:text-sm font-extrabold text-white block">R$ ${parseFloat(s.preco).toFixed(2).replace('.', ',')}</span>
                    </div>
                `;
            }

            card.innerHTML = `
                <div class="flex items-center gap-3 min-w-0">
                    ${imgHtml}
                    <div class="min-w-0">
                        <h4 class="text-xs sm:text-sm font-bold text-white group-hover:text-amber-400 transition truncate">${escaparHtml(s.nome)}</h4>
                        <p class="text-[11px] text-zinc-400 line-clamp-1 mt-0.5">${descricaoTexto}</p>
                        <div class="flex items-center gap-2 mt-1">
                            <span class="text-[11px] text-zinc-400 flex items-center gap-1 font-medium">
                                <i class="fa-regular fa-clock text-[10px] text-amber-500"></i> ${s.duracao_minutos} min
                            </span>
                            ${tagTipo}
                            <span class="aviso-bloqueio-incompativel text-[10px] text-red-400 hidden font-semibold">
                                <i class="fa-solid fa-ban mr-0.5"></i> Incompatível
                            </span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-3 shrink-0">
                    ${precoHtml}
                    <div class="check-circulo w-5 h-5 rounded-full border border-zinc-700 flex items-center justify-center text-[10px] text-transparent group-hover:border-zinc-500 transition">
                        <i class="fa-solid fa-check"></i>
                    </div>
                </div>
            `;

            // Clique para alternar seleção com validação de incompatibilidade
            card.addEventListener('click', () => {
                const idNum = parseInt(s.id);
                const idx = servicosSelecionados.indexOf(idNum);

                if (idx > -1) {
                    // Já estava selecionado: desmarca
                    servicosSelecionados.splice(idx, 1);
                } else {
                    // Tenta marcar: checar regras de incompatibilidade
                    const incompNovo = s.incompativeis ? (typeof s.incompativeis === 'string' ? JSON.parse(s.incompativeis) : s.incompativeis) : [];

                    for (let idJaMarcado of servicosSelecionados) {
                        const sJaMarcado = todosServicos.find(item => parseInt(item.id) === idJaMarcado);
                        if (!sJaMarcado) continue;

                        const incompJaMarcado = sJaMarcado.incompativeis ? (typeof sJaMarcado.incompativeis === 'string' ? JSON.parse(sJaMarcado.incompativeis) : sJaMarcado.incompativeis) : [];

                        // Se o novo bloqueia o existente OU o existente bloqueia o novo
                        if (incompNovo.includes(idJaMarcado) || incompJaMarcado.includes(idNum)) {
                            alert(`Não é possível combinar "${s.nome}" com "${sJaMarcado.nome}".\n\nRegra: estes serviços não podem ser agendados juntos.`);
                            return;
                        }
                    }

                    servicosSelecionados.push(idNum);
                }

                atualizarEstadoCardsServicos();
                carregarHorarios();
            });

            container.appendChild(card);
        });
    }
    atualizarEstadoCardsServicos();
    carregarHorarios();
});

function atualizarEstadoCardsServicos() {
    const labelInfo = document.getElementById('labelServicoSelecionadoInfo');
    campoServico.value = servicosSelecionados.join(',');

    // Mapear todas as incompatibilidades ativas dos serviços selecionados
    const todosIncompativeisAtivos = new Set();
    servicosSelecionados.forEach(idMarcado => {
        const sObj = todosServicos.find(item => parseInt(item.id) === idMarcado);
        if (sObj && sObj.incompativeis) {
            const arr = (typeof sObj.incompativeis === 'string') ? JSON.parse(sObj.incompativeis) : sObj.incompativeis;
            if (Array.isArray(arr)) {
                arr.forEach(bloqueadoId => todosIncompativeisAtivos.add(parseInt(bloqueadoId)));
            }
        }
    });

    // Atualizar visual de cada card
    let duracaoTotal = 0;
    let precoTotal = 0;

    document.querySelectorAll('.card-servico-item').forEach(c => {
        const idCard = parseInt(c.getAttribute('data-id'));
        const estaMarcado = servicosSelecionados.includes(idCard);
        const sObj = todosServicos.find(item => parseInt(item.id) === idCard);

        const circ = c.querySelector('.check-circulo');
        const avisoIncomp = c.querySelector('.aviso-bloqueio-incompativel');

        if (estaMarcado) {
            c.classList.add('border-amber-500', 'bg-zinc-800', 'ring-1', 'ring-amber-500');
            c.classList.remove('border-zinc-800', 'bg-zinc-900/90', 'opacity-40');
            if (circ) {
                circ.classList.add('bg-amber-500', 'text-zinc-950', 'border-amber-500');
                circ.classList.remove('text-transparent', 'border-zinc-700');
            }
            if (avisoIncomp) avisoIncomp.classList.add('hidden');

            if (sObj) {
                duracaoTotal += parseInt(sObj.duracao_minutos);
                const pac = mapaPacotesAtivos[sObj.id] || null;
                if (!pac || pac.sessoes_restantes <= 0) {
                    precoTotal += parseFloat(sObj.preco);
                }
            }
        } else {
            c.classList.remove('border-amber-500', 'bg-zinc-800', 'ring-1', 'ring-amber-500');
            c.classList.add('border-zinc-800', 'bg-zinc-900/90');
            if (circ) {
                circ.classList.remove('bg-amber-500', 'text-zinc-950', 'border-amber-500');
                circ.classList.add('text-transparent', 'border-zinc-700');
            }

            // Checar se este card é incompatível com algum dos já selecionados
            const ehIncompativel = todosIncompativeisAtivos.has(idCard) || (() => {
                if (!sObj || !sObj.incompativeis) return false;
                const arr = (typeof sObj.incompativeis === 'string') ? JSON.parse(sObj.incompativeis) : sObj.incompativeis;
                return Array.isArray(arr) && servicosSelecionados.some(idM => arr.includes(idM));
            })();

            if (ehIncompativel) {
                c.classList.add('opacity-40');
                if (avisoIncomp) avisoIncomp.classList.remove('hidden');
            } else {
                c.classList.remove('opacity-40');
                if (avisoIncomp) avisoIncomp.classList.add('hidden');
            }
        }
    });

    if (servicosSelecionados.length > 0) {
        labelInfo.classList.remove('hidden');
        labelInfo.innerHTML = `✓ ${servicosSelecionados.length} serviço(s) • ⏱ ${duracaoTotal} min • R$ ${precoTotal.toFixed(2).replace('.', ',')}`;
    } else {
        labelInfo.classList.add('hidden');
        labelInfo.textContent = '';
    }
}

botoesData.forEach(btn => {
    btn.addEventListener('click', () => {
        botoesData.forEach(b => {
            b.classList.remove('bg-amber-500', 'text-zinc-950', 'border-amber-500', 'font-bold');
            b.classList.add('bg-zinc-800', 'border-zinc-700');
            const span = b.querySelector('span:first-child');
            if (span) span.classList.replace('text-zinc-900', 'text-zinc-400');
            const span2 = b.querySelector('span:last-child');
            if (span2) span2.classList.replace('text-zinc-950', 'text-white');
        });

        btn.classList.add('bg-amber-500', 'border-amber-500');
        btn.classList.remove('bg-zinc-800', 'border-zinc-700');
        const span = btn.querySelector('span:first-child');
        if (span) span.classList.replace('text-zinc-400', 'text-zinc-900');
        const span2 = btn.querySelector('span:last-child');
        if (span2) span2.classList.replace('text-white', 'text-zinc-950');

        campoData.value = btn.dataset.data;
        carregarHorarios();
    });
});

async function carregarHorarios() {
    const requisicaoAtual = ++requisicaoHorarios;
    bloqueioOrdemChegada = false;
    horarioSelecionado.value = '';
    atualizarBotaoConfirmar();
    const data = campoData.value;
    const servicoId = campoServico.value;

    if (!data) {
        statusCarregamento.textContent = 'Clique num dos dias disponíveis acima.';
        gradeHorarios.classList.add('hidden');
        atualizarBotaoConfirmar();
        return;
    }

    if (!servicoId) {
        statusCarregamento.textContent = 'Selecione primeiro o serviço desejado.';
        gradeHorarios.classList.add('hidden');
        atualizarBotaoConfirmar();
        return;
    }

    gradeHorarios.innerHTML = '';
    gradeHorarios.classList.add('hidden');
    statusCarregamento.classList.remove('hidden');
    statusCarregamento.textContent = 'A procurar horários livres...';

    try {
        const profId = campoProfissional.value;
        const resposta = await fetch(`horarios_disponiveis.php?data=${encodeURIComponent(data)}&servico_id=${encodeURIComponent(servicoId)}&servicos_ids=${encodeURIComponent(servicoId)}&profissional_id=${encodeURIComponent(profId)}`);
        const info = await resposta.json();
        if (requisicaoAtual !== requisicaoHorarios) return;

        if (info.fechado) {
            horarioSelecionado.value = '';
            atualizarBotaoConfirmar();
            statusCarregamento.textContent = info.mensagem;
            return;
        }

        if (info.ordem_chegada) {
            bloqueioOrdemChegada = true;
            horarioSelecionado.value = '';
            atualizarBotaoConfirmar();
            statusCarregamento.classList.remove('hidden');
            statusCarregamento.innerHTML = `
                <div class="bg-blue-950/40 border border-blue-800/80 p-3 rounded-xl text-blue-300 text-xs flex items-center gap-2">
                    <i class="fa-solid fa-person-walking-arrow-right text-base text-blue-400"></i>
                    <div>
                        <strong class="block text-white">Modalidade: Ordem de Chegada</strong>
                        ${info.mensagem_ordem}. Basta comparecer à barbearia neste dia!
                    </div>
                </div>
            `;
            gradeHorarios.classList.add('hidden');
            return;
        }

        if (!info.horarios || info.horarios.length === 0) {
            statusCarregamento.textContent = 'Sem vagas livres para a duração deste corte nesta data.';
            horarioSelecionado.value = '';
            atualizarBotaoConfirmar();
            return;
        }

        statusCarregamento.classList.add('hidden');
        gradeHorarios.classList.remove('hidden');

        info.horarios.forEach(hora => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = hora;
            btn.className = 'botao-slot py-2 px-1 text-xs font-medium rounded-lg border border-zinc-700 bg-zinc-800 text-zinc-200 hover:border-amber-500 hover:text-amber-400 transition text-center';

            btn.addEventListener('click', () => {
                document.querySelectorAll('.botao-slot').forEach(b => {
                    b.classList.remove('bg-amber-500', 'text-zinc-950', 'border-amber-500', 'font-bold');
                    b.classList.add('bg-zinc-800', 'text-zinc-200', 'border-zinc-700');
                });

                btn.classList.add('bg-amber-500', 'text-zinc-950', 'border-amber-500', 'font-bold');
                btn.classList.remove('bg-zinc-800', 'text-zinc-200', 'border-zinc-700');

                horarioSelecionado.value = hora;
                atualizarBotaoConfirmar();
            });

            gradeHorarios.appendChild(btn);
        });
    } catch (e) {
        if (requisicaoAtual !== requisicaoHorarios) return;
        horarioSelecionado.value = '';
        atualizarBotaoConfirmar();
        statusCarregamento.textContent = 'Erro ao consultar horários disponíveis.';
    }
}

campoServico.addEventListener('change', carregarHorarios);

function aplicarPacoteNoSelect(servId, pacId) {
    if (campoServico) {
        campoServico.value = servId;
        carregarHorarios();
    }
}

</script>

</body>
</html>
