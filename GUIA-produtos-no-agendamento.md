# Produtos opcionais no agendamento

## O que muda

- No painel, em **Frente de Caixa & Controle de Estoque**, cada produto pode ser marcado para aparecer como adicional no agendamento. Produtos novos ficam ocultos por padrão.
- Na reserva pública, a pessoa pode escolher produtos e quantidades. A lista mostra somente produtos ativos, publicados e com estoque disponível.
- O servidor valida novamente a seleção, a quantidade e o estoque. A baixa é feita em transação para evitar reservar unidades acima do estoque quando duas pessoas confirmam ao mesmo tempo.
- Os produtos entram na comanda pelo preço vigente no momento da reserva. O cancelamento de uma reserva em aberto devolve os itens reservados ao estoque.
- Os produtos são opcionais e não alteram a duração do serviço nem os horários disponíveis.

## Implementação passo a passo

1. **Faça backup** dos arquivos do site e do banco MariaDB antes de atualizar.
2. **Envie os arquivos do pacote** para a hospedagem, preservando `config/database.php` e qualquer configuração local. Não substitua suas credenciais.
3. **Atualize o banco uma única vez:** abra o phpMyAdmin da Locaweb, selecione a base usada pelo sistema (por exemplo, `barbearia_db`), abra a aba SQL e execute o conteúdo de `database/migrations/20261108_produtos_agendamento.sql`. Não execute novamente depois que a coluna existir.
4. **Entre no painel** e abra **Frente de Caixa & Controle de Estoque**. Na coluna “No agendamento”, marque os produtos que deseja oferecer e clique em “Salvar”. Para produto novo, use a caixa “Oferecer este produto como adicional no agendamento público”.
5. **Teste como cliente:** abra o agendamento público com uma conta de teste, escolha profissional e serviço e confira a seção “Adicionais opcionais”. Marque um item, ajuste a quantidade e confirme a reserva.
6. **Confira a comanda e o estoque:** no painel, abra o atendimento e valide que produto, quantidade e valor aparecem na comanda; confira que o estoque caiu na quantidade reservada.
7. **Teste o cancelamento:** cancele uma reserva pendente de teste e confirme que os itens voltaram ao estoque. Não use uma venda real para esse teste.
8. **Reenvie a verificação de branding do Google** somente após confirmar que a página pública e a política estão acessíveis por HTTPS, caso essa verificação ainda esteja pendente.

## Verificações incluídas

- Identificador de produto inteiro, seleção sem duplicatas e limite de 20 tipos de produto por reserva.
- Quantidade inteira entre 1 e 50, além da conferência contra o estoque atual.
- Produto precisa continuar ativo e marcado para exibição no instante da confirmação.
- Preço e subtotal vêm do banco, não do navegador.
- Estoque, itens da comanda e agendamento são gravados juntos; se uma verificação falhar, a transação é revertida.
- Cancelamento em aberto libera o estoque apenas uma vez; reservas canceladas deixam de aparecer na comanda.
- Produto esgotado ou ocultado deixa de ser oferecido automaticamente.

## Requisitos técnicos e possíveis desafios

- O banco deve usar MariaDB/MySQL e as tabelas envolvidas devem suportar transações (preferencialmente InnoDB). Isso é importante para que uma falha não deixe o agendamento sem os respectivos itens ou com baixa parcial do estoque.
- A alteração do banco é necessária. Se o painel de agendamento mostrar erro de coluna inexistente, confira se o SQL foi executado na base correta e apenas uma vez.
- A baixa acontece ao criar a reserva para impedir excesso de vendas. Ao cancelar uma reserva pendente/confirmada pelo painel ou pela área do cliente, o estoque é restaurado. A remarcação mantém a reserva.
- Produtos ocultos, inativos ou sem estoque não aparecem. Se um item não surgir, confira a coluna “No agendamento”, o status e a quantidade.
- Este recurso foi conectado ao fluxo de reserva do cliente. Agendamentos manuais feitos pelo painel continuam sem seleção de produtos; os produtos podem ser incluídos depois na comanda, como já era possível.
- A sintaxe PHP e o comportamento real precisam ser testados na hospedagem/XAMPP, pois este ambiente não está conectado ao MariaDB nem ao servidor Locaweb.
