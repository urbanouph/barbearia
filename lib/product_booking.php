<?php
/**
 * Regras de reserva de produtos selecionados no agendamento público.
 * Requer que o chamador controle a transação ao reservar ou liberar estoque.
 */
function normalizarProdutosSelecionados($selecionados, $quantidades): array
{
    if ($selecionados === null || $selecionados === '') {
        return [];
    }
    if (!is_array($selecionados) || !is_array($quantidades)) {
        throw new InvalidArgumentException('A seleção de produtos enviada é inválida. Atualize a página e tente novamente.');
    }
    if (count($selecionados) > 20) {
        throw new InvalidArgumentException('Selecione no máximo 20 tipos de produto por agendamento.');
    }

    $resultado = [];
    foreach ($selecionados as $produtoIdRaw) {
        $produtoId = filter_var($produtoIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($produtoId === false || isset($resultado[$produtoId])) {
            throw new InvalidArgumentException('A seleção contém um produto inválido ou repetido.');
        }

        $quantidadeRaw = $quantidades[$produtoId] ?? null;
        $quantidade = filter_var($quantidadeRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);
        if ($quantidade === false) {
            throw new InvalidArgumentException('Informe uma quantidade entre 1 e 50 para cada produto selecionado.');
        }
        $resultado[(int)$produtoId] = (int)$quantidade;
    }

    return $resultado;
}

/**
 * Confere novamente elegibilidade e estoque com bloqueio de linha e registra
 * os produtos na comanda, baixando o estoque dentro da mesma transação.
 */
function reservarProdutosNoAgendamento(PDO $pdo, int $agendamentoId, array $produtos): float
{
    if (!$produtos) {
        return 0.0;
    }

    $consulta = $pdo->prepare("SELECT id, nome, preco_venda, quantidade_estoque, status, exibir_agendamento FROM produtos WHERE id = ? FOR UPDATE");
    $baixa = $pdo->prepare("UPDATE produtos SET quantidade_estoque = quantidade_estoque - ? WHERE id = ? AND status = 'ativo' AND exibir_agendamento = 1 AND quantidade_estoque >= ?");
    $insere = $pdo->prepare("INSERT INTO agendamento_itens (agendamento_id, tipo, item_id, nome_item, quantidade, preco_unitario, subtotal) VALUES (?, 'produto', ?, ?, ?, ?, ?)");

    $total = 0.0;
    foreach ($produtos as $produtoId => $quantidade) {
        $consulta->execute([(int)$produtoId]);
        $produto = $consulta->fetch(PDO::FETCH_ASSOC);
        if (!$produto || $produto['status'] !== 'ativo' || (int)$produto['exibir_agendamento'] !== 1) {
            throw new RuntimeException('Um dos produtos selecionados não está mais disponível para agendamento. Revise a seleção.');
        }
        if ((int)$produto['quantidade_estoque'] < (int)$quantidade) {
            throw new RuntimeException('Estoque insuficiente para ' . $produto['nome'] . '. Ajuste a quantidade ou escolha outro produto.');
        }

        $baixa->execute([(int)$quantidade, (int)$produtoId, (int)$quantidade]);
        if ($baixa->rowCount() !== 1) {
            throw new RuntimeException('O estoque de ' . $produto['nome'] . ' foi alterado durante a reserva. Atualize a página e tente novamente.');
        }

        $preco = (float)$produto['preco_venda'];
        $subtotal = round($preco * (int)$quantidade, 2);
        $insere->execute([$agendamentoId, (int)$produtoId, $produto['nome'], (int)$quantidade, $preco, $subtotal]);
        $total += $subtotal;
    }

    return round($total, 2);
}

/** Devolve ao estoque os produtos reservados e remove-os da comanda cancelada. */
function liberarProdutosDoAgendamento(PDO $pdo, int $agendamentoId): void
{
    $stmt = $pdo->prepare("SELECT item_id, quantidade FROM agendamento_itens WHERE agendamento_id = ? AND tipo = 'produto'");
    $stmt->execute([$agendamentoId]);
    $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $devolver = $pdo->prepare('UPDATE produtos SET quantidade_estoque = quantidade_estoque + ? WHERE id = ?');
    foreach ($itens as $item) {
        $devolver->execute([(int)$item['quantidade'], (int)$item['item_id']]);
    }

    if ($itens) {
        $del = $pdo->prepare("DELETE FROM agendamento_itens WHERE agendamento_id = ? AND tipo = 'produto'");
        $del->execute([$agendamentoId]);
    }
}
