# Shift Barber — atualização do sistema

## Antes de instalar
1. Faça uma cópia de segurança dos arquivos atuais e exporte o banco `barbearia_db` pelo phpMyAdmin.
2. Ao substituir os arquivos do site, preserve a sua configuração atual em `config/database.php` (ela contém os dados de conexão do seu ambiente).
3. Não importe `database.sql` sobre uma instalação existente: ele é o esquema-base para uma instalação nova.

## Atualização do banco existente
No phpMyAdmin, selecione `barbearia_db` e importe **uma vez** `migracao_produtos_agendamento.sql`. A migração cria o campo de visibilidade dos produtos e configura as tabelas de reserva/estoque para transações. Requer MariaDB 10.4 ou superior.

Produtos existentes começam **desmarcados** para não aparecerem sem autorização. No painel, em Produtos/Estoque, edite o nome, marque ou desmarque “Mostrar no agendamento” e salve. Só aparecem ao cliente produtos ativos com estoque positivo; a quantidade escolhida fica reservada, entra na comanda e volta ao estoque se a reserva pendente for cancelada.

## Agendamentos
Quando a modalidade do dia for **Ordem de Chegada**, a tela pública não libera o botão de confirmação. O servidor também rejeita uma tentativa de reserva enviada diretamente, e a tela de remarcação aplica o mesmo bloqueio.

## Senhas e segurança
Novas senhas de clientes são armazenadas com hash. Senhas antigas em texto puro são convertidas automaticamente em hash após um login válido. Use HTTPS com certificado válido antes de receber senhas ou dados pessoais; a instalação/renovação do SSL precisa ser feita no painel da hospedagem, não apenas por alteração do código.

## Conteúdo do pacote
O arquivo `database2.sql` foi deixado de fora porque é um despejo de banco com dados de pessoas. Não o publique nem compartilhe; para a atualização, use somente a migração indicada acima.

## Teste após instalar
- Cadastre/edite um produto e teste a opção de exibição no agendamento.
- Faça uma reserva selecionando um produto, confira o total na comanda e cancele a reserva para confirmar a devolução ao estoque.
- Configure uma data como Ordem de Chegada e confirme que não é possível reservar nem remarcar um horário nesse dia.
- Teste login de cliente antigo e novo; confirme que a senha antiga continua funcionando e passa a ser armazenada com hash.
