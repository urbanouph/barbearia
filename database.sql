-- Criação do Banco de Dados
CREATE DATABASE IF NOT EXISTS barbearia_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE barbearia_db;

-- Tabela de Administradores
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    senha VARCHAR(255) NOT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabela de Clientes
CREATE TABLE IF NOT EXISTS clientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    senha VARCHAR(255) NULL,
    telefone VARCHAR(20) NULL,
    cpf VARCHAR(14) UNIQUE NULL,
    data_nascimento DATE NULL,
    oauth_provider ENUM('local', 'google', 'facebook') DEFAULT 'local',
    oauth_uid VARCHAR(100) NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabela de Profissionais (Barbeiros)
CREATE TABLE IF NOT EXISTS profissionais (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    especialidade VARCHAR(100) DEFAULT 'Barbeiro',
    telefone VARCHAR(20) NULL,
    status ENUM('ativo', 'inativo') DEFAULT 'ativo',
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabela de Serviços
CREATE TABLE IF NOT EXISTS servicos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    descricao TEXT,
    preco DECIMAL(10,2) NOT NULL,
    duracao_minutos INT NOT NULL DEFAULT 30,
    comissao_percentual DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    status ENUM('ativo', 'inativo') DEFAULT 'ativo',
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabela de Vínculo: Quais serviços cada profissional realiza
CREATE TABLE IF NOT EXISTS profissional_servicos (
    profissional_id INT NOT NULL,
    servico_id INT NOT NULL,
    comissao_percentual DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    PRIMARY KEY (profissional_id, servico_id),
    FOREIGN KEY (profissional_id) REFERENCES profissionais(id) ON DELETE CASCADE,
    FOREIGN KEY (servico_id) REFERENCES servicos(id) ON DELETE CASCADE
);

-- Tabela de Turnos/Horários por Data Específica
CREATE TABLE IF NOT EXISTS turnos_atendimento (
    id INT AUTO_INCREMENT PRIMARY KEY,
    profissional_id INT NULL,
    data_atendimento DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fim TIME NOT NULL,
    intervalo_minutos INT NOT NULL DEFAULT 30,
    tipo_atendimento ENUM('horario_marcado', 'ordem_chegada') NOT NULL DEFAULT 'horario_marcado',
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (profissional_id) REFERENCES profissionais(id) ON DELETE CASCADE
);

-- Tabela de Agendamentos
CREATE TABLE IF NOT EXISTS agendamentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NULL,
    profissional_id INT NULL,
    cliente_nome VARCHAR(100) NOT NULL,
    cliente_telefone VARCHAR(20) NOT NULL,
    servico_id INT NOT NULL,
    data_agendamento DATE NOT NULL,
    horario TIME NOT NULL,
    status ENUM('pendente', 'confirmado', 'concluido', 'cancelado') DEFAULT 'pendente',
    observacoes TEXT,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (servico_id) REFERENCES servicos(id) ON DELETE CASCADE,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL,
    FOREIGN KEY (profissional_id) REFERENCES profissionais(id) ON DELETE SET NULL
);

-- Administrador Padrão (senha 123456)
DELETE FROM usuarios WHERE email = 'admin@barbearia.com';
INSERT INTO usuarios (id, nome, email, senha) 
VALUES (1, 'Administrador', 'admin@barbearia.com', '123456');

-- Serviços Padrão
INSERT INTO servicos (id, nome, preco, duracao_minutos) VALUES
(1, 'Corte Tradicional', 35.00, 30),
(2, 'Barba Terapia', 30.00, 30),
(3, 'Combo Corte + Barba', 60.00, 60),
(4, 'Acabamento / Pezinho', 15.00, 15)
ON DUPLICATE KEY UPDATE nome = VALUES(nome);

-- Profissionais Padrão
INSERT INTO profissionais (id, nome, especialidade, telefone) VALUES
(1, 'Lucas Barbeiro', 'Cortes & Barba Clássica', '(11) 98888-1111'),
(2, 'Carlos Mestre da Navalha', 'Degradê & Barboterapia', '(11) 97777-2222')
ON DUPLICATE KEY UPDATE nome = VALUES(nome);

-- Associação de Serviços aos Profissionais
INSERT IGNORE INTO profissional_servicos (profissional_id, servico_id) VALUES
(1, 1), (1, 2), (1, 3), (1, 4),
(2, 1), (2, 2), (2, 3);

-- Exemplo inicial de turnos
INSERT INTO turnos_atendimento (profissional_id, data_atendimento, hora_inicio, hora_fim, intervalo_minutos) VALUES
(1, '2026-10-07', '09:00:00', '13:00:00', 30),
(1, '2026-10-07', '14:00:00', '18:00:00', 30),
(2, '2026-10-07', '10:00:00', '15:00:00', 30),
(2, '2026-10-07', '16:00:00', '20:00:00', 30),
(1, '2026-10-08', '09:00:00', '13:00:00', 30),
(2, '2026-10-08', '14:00:00', '19:00:00', 30);

-- Tabela de Produtos (Pomadas, Óleos, Shampoos, Cervejas, etc.)
CREATE TABLE IF NOT EXISTS produtos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    descricao TEXT NULL,
    preco_custo DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    preco_venda DECIMAL(10,2) NOT NULL,
    comissao_percentual DECIMAL(5,2) NOT NULL DEFAULT 10.00,
    quantidade_estoque INT NOT NULL DEFAULT 0,
    estoque_minimo INT NOT NULL DEFAULT 5,
    status ENUM('ativo', 'inativo') DEFAULT 'ativo',
    mostrar_no_agendamento TINYINT(1) NOT NULL DEFAULT 0,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Mantém compatibilidade ao executar o esquema em instalações existentes
ALTER TABLE produtos ADD COLUMN IF NOT EXISTS mostrar_no_agendamento TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

-- Tabela de Vendas de Produtos (Frente de Caixa / PDV)
CREATE TABLE IF NOT EXISTS vendas_produtos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NULL,
    profissional_id INT NULL,
    forma_pagamento ENUM('dinheiro', 'pix', 'cartao_credito', 'cartao_debito') NOT NULL DEFAULT 'pix',
    total DECIMAL(10,2) NOT NULL,
    data_venda DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL,
    FOREIGN KEY (profissional_id) REFERENCES profissionais(id) ON DELETE SET NULL
);

-- Itens da Venda com Baixa em Estoque
CREATE TABLE IF NOT EXISTS itens_venda_produtos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    venda_id INT NOT NULL,
    produto_id INT NOT NULL,
    quantidade INT NOT NULL,
    preco_unitario DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (venda_id) REFERENCES vendas_produtos(id) ON DELETE CASCADE,
    FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE RESTRICT
);

-- Produtos Padrão Iniciais
INSERT INTO produtos (id, nome, preco_custo, preco_venda, quantidade_estoque, estoque_minimo) VALUES
(1, 'Pomada Modeladora Efeito Matte 80g', 15.00, 35.00, 20, 5),
(2, 'Óleo Fortalecedor para Barba 30ml', 18.00, 40.00, 15, 4),
(3, 'Shampoo Anticaspa Masculino 250ml', 12.00, 28.00, 12, 3),
(4, 'Cerveja Long Neck Artesanal', 5.00, 12.00, 30, 8)
ON DUPLICATE KEY UPDATE nome = VALUES(nome);

-- Adicionar colunas de fechamento em agendamentos
ALTER TABLE agendamentos 
ADD COLUMN IF NOT EXISTS forma_pagamento ENUM('dinheiro', 'pix', 'cartao_credito', 'cartao_debito') NULL,
ADD COLUMN IF NOT EXISTS valor_total DECIMAL(10,2) NULL;

-- Tabela de itens extras da comanda (serviços adicionais ou produtos consumidos)
CREATE TABLE IF NOT EXISTS agendamento_itens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agendamento_id INT NOT NULL,
    tipo ENUM('servico', 'produto') NOT NULL,
    item_id INT NOT NULL,
    nome_item VARCHAR(100) NOT NULL,
    quantidade INT NOT NULL DEFAULT 1,
    preco_unitario DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (agendamento_id) REFERENCES agendamentos(id) ON DELETE CASCADE
);

-- Atualização de comissão percentual nos serviços
ALTER TABLE servicos ADD COLUMN IF NOT EXISTS comissao_percentual DECIMAL(5,2) NOT NULL DEFAULT 50.00;

-- Adicionar coluna comissao_percentual na tabela de ligação profissional_servicos
ALTER TABLE profissional_servicos ADD COLUMN IF NOT EXISTS comissao_percentual DECIMAL(5,2) NOT NULL DEFAULT 50.00;

-- Adicionar coluna comissao_percentual em produtos se não existir
ALTER TABLE produtos ADD COLUMN IF NOT EXISTS comissao_percentual DECIMAL(5,2) NOT NULL DEFAULT 10.00 AFTER preco_venda;

-- Tabela de Pacotes Ativos dos Clientes
CREATE TABLE IF NOT EXISTS cliente_pacotes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    servico_id INT NOT NULL,
    sessoes_total INT NOT NULL DEFAULT 3,
    sessoes_restantes INT NOT NULL DEFAULT 3,
    data_compra DATE NOT NULL,
    data_expiracao DATE NOT NULL,
    status ENUM('ativo', 'esgotado', 'expirado') NOT NULL DEFAULT 'ativo',
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
    FOREIGN KEY (servico_id) REFERENCES servicos(id) ON DELETE CASCADE
);

-- Suporte a pacote na tabela servicos e agendamentos
ALTER TABLE servicos 
ADD COLUMN IF NOT EXISTS eh_pacote TINYINT(1) NOT NULL DEFAULT 0,
ADD COLUMN IF NOT EXISTS sessoes_pacote INT NOT NULL DEFAULT 1,
ADD COLUMN IF NOT EXISTS validade_dias INT NOT NULL DEFAULT 30;

ALTER TABLE agendamentos 
ADD COLUMN IF NOT EXISTS cliente_pacote_id INT NULL,
ADD CONSTRAINT fk_agendamento_pacote FOREIGN KEY (cliente_pacote_id) REFERENCES cliente_pacotes(id) ON DELETE SET NULL;

-- Adiciona suporte a escala semanal fixa
ALTER TABLE turnos_atendimento 
ADD COLUMN IF NOT EXISTS dia_semana TINYINT NULL AFTER profissional_id;

ALTER TABLE turnos_atendimento 
MODIFY COLUMN data_atendimento DATE NULL;

-- Pausa de almoço opcional nos turnos
ALTER TABLE turnos_atendimento 
ADD COLUMN IF NOT EXISTS pausa_inicio TIME NULL AFTER hora_fim,
ADD COLUMN IF NOT EXISTS pausa_fim TIME NULL AFTER pausa_inicio;

-- Tabela de Bloqueios de Dias / Feriados / Folgas
CREATE TABLE IF NOT EXISTS bloqueios_agenda (
    id INT AUTO_INCREMENT PRIMARY KEY,
    data_bloqueio DATE NOT NULL,
    motivo VARCHAR(255) NOT NULL DEFAULT 'Fechado / Folga',
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Colunas de desconto na tabela agendamentos
ALTER TABLE agendamentos 
ADD COLUMN IF NOT EXISTS desconto_tipo ENUM('reais', 'porcentagem') NULL DEFAULT 'reais',
ADD COLUMN IF NOT EXISTS desconto_valor DECIMAL(10,2) NOT NULL DEFAULT 0.00;

-- Suporte a bloqueio/folga por profissional na tabela bloqueios_agenda
ALTER TABLE bloqueios_agenda 
ADD COLUMN IF NOT EXISTS profissional_id INT NULL AFTER id,
ADD CONSTRAINT fk_bloqueio_profissional FOREIGN KEY (profissional_id) REFERENCES profissionais(id) ON DELETE CASCADE;

-- Tabela de Despesas e Ganhos Extras
CREATE TABLE IF NOT EXISTS movimentacoes_financeiras (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo ENUM('receita', 'despesa') NOT NULL,
    categoria VARCHAR(100) NOT NULL,
    descricao VARCHAR(255) NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    data_movimentacao DATE NOT NULL,
    forma_pagamento VARCHAR(50) NULL DEFAULT 'pix',
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Atualização do índice de unicidade para permitir folgas simultâneas de profissionais diferentes
ALTER TABLE bloqueios_agenda DROP INDEX IF EXISTS data_bloqueio;
ALTER TABLE bloqueios_agenda ADD UNIQUE KEY IF NOT EXISTS unique_bloqueio_prof_data (data_bloqueio, profissional_id);

-- Suporte a imagem do serviço
ALTER TABLE servicos ADD COLUMN IF NOT EXISTS imagem VARCHAR(255) NULL AFTER descricao;

-- Suporte a serviços incompatíveis (regras de impedimento de marcação mútua)
ALTER TABLE servicos ADD COLUMN IF NOT EXISTS incompativeis TEXT NULL AFTER eh_pacote;
