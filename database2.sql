-- --------------------------------------------------------
-- Servidor:                     127.0.0.1
-- Versão do servidor:           10.4.32-MariaDB - mariadb.org binary distribution
-- OS do Servidor:               Win64
-- HeidiSQL Versão:              12.17.0.7270
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

-- Copiando estrutura para tabela barbearia_db.agendamento_itens
DROP TABLE IF EXISTS `agendamento_itens`;
CREATE TABLE IF NOT EXISTS `agendamento_itens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `agendamento_id` int(11) NOT NULL,
  `tipo` enum('servico','produto') NOT NULL,
  `item_id` int(11) NOT NULL,
  `nome_item` varchar(100) NOT NULL,
  `quantidade` int(11) NOT NULL DEFAULT 1,
  `preco_unitario` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `agendamento_id` (`agendamento_id`),
  CONSTRAINT `agendamento_itens_ibfk_1` FOREIGN KEY (`agendamento_id`) REFERENCES `agendamentos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copiando dados para a tabela barbearia_db.agendamento_itens: ~4 rows (aproximadamente)
INSERT IGNORE INTO `agendamento_itens` (`id`, `agendamento_id`, `tipo`, `item_id`, `nome_item`, `quantidade`, `preco_unitario`, `subtotal`, `criado_em`) VALUES
	(6, 9, 'servico', 8, 'Mão', 1, 30.00, 30.00, '2026-10-07 19:04:07'),
	(7, 9, 'servico', 5, 'Sobrancelha', 1, 15.00, 15.00, '2026-10-07 19:04:13'),
	(8, 9, 'produto', 4, 'cerveja', 1, 10.00, 10.00, '2026-10-07 19:04:15'),
	(11, 10, 'produto', 4, 'cerveja', 5, 10.00, 50.00, '2026-10-07 19:06:48');

-- Copiando estrutura para tabela barbearia_db.agendamentos
DROP TABLE IF EXISTS `agendamentos`;
CREATE TABLE IF NOT EXISTS `agendamentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cliente_id` int(11) DEFAULT NULL,
  `profissional_id` int(11) DEFAULT NULL,
  `cliente_nome` varchar(100) NOT NULL,
  `cliente_telefone` varchar(20) NOT NULL,
  `servico_id` int(11) NOT NULL,
  `data_agendamento` date NOT NULL,
  `horario` time NOT NULL,
  `status` enum('pendente','confirmado','concluido','cancelado') DEFAULT 'pendente',
  `observacoes` text DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  `forma_pagamento` enum('dinheiro','pix','cartao_credito','cartao_debito') DEFAULT NULL,
  `valor_total` decimal(10,2) DEFAULT NULL,
  `cliente_pacote_id` int(11) DEFAULT NULL,
  `desconto_tipo` enum('reais','porcentagem') DEFAULT 'reais',
  `desconto_valor` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `servico_id` (`servico_id`),
  KEY `fk_agendamentos_cliente` (`cliente_id`),
  KEY `fk_agendamentos_profissional` (`profissional_id`),
  KEY `fk_agendamento_pacote` (`cliente_pacote_id`),
  CONSTRAINT `agendamentos_ibfk_1` FOREIGN KEY (`servico_id`) REFERENCES `servicos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_agendamento_pacote` FOREIGN KEY (`cliente_pacote_id`) REFERENCES `cliente_pacotes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_agendamentos_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_agendamentos_profissional` FOREIGN KEY (`profissional_id`) REFERENCES `profissionais` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copiando dados para a tabela barbearia_db.agendamentos: ~10 rows (aproximadamente)
INSERT IGNORE INTO `agendamentos` (`id`, `cliente_id`, `profissional_id`, `cliente_nome`, `cliente_telefone`, `servico_id`, `data_agendamento`, `horario`, `status`, `observacoes`, `criado_em`, `forma_pagamento`, `valor_total`, `cliente_pacote_id`, `desconto_tipo`, `desconto_valor`) VALUES
	(7, 1, 2, 'Urbano Neto', '14997672412', 8, '2026-10-07', '13:00:00', 'concluido', NULL, '2026-10-07 17:39:02', 'pix', 30.00, NULL, 'reais', 0.00),
	(8, 1, 1, 'Urbano Neto', '14997672412', 3, '2026-10-10', '13:00:00', 'concluido', NULL, '2026-10-07 18:42:24', 'pix', 60.00, NULL, 'reais', 0.00),
	(9, 1, 1, 'Urbano Neto', '14997672412', 2, '2026-10-07', '17:30:00', 'concluido', NULL, '2026-10-07 19:01:09', 'pix', 85.00, NULL, 'reais', 0.00),
	(10, 1, 1, 'Urbano Neto', '14997672412', 2, '2026-10-07', '16:30:00', 'concluido', NULL, '2026-10-07 19:06:03', 'pix', 80.00, NULL, 'reais', 0.00),
	(11, 1, 1, 'Urbano Neto', '14997672412', 3, '2026-10-10', '09:00:00', 'concluido', NULL, '2026-10-07 19:38:39', 'pix', 60.00, NULL, 'reais', 0.00),
	(12, 1, 1, 'Urbano Neto', '14997672412', 12, '2026-10-10', '10:00:00', 'cancelado', NULL, '2026-10-08 00:26:26', NULL, NULL, NULL, 'reais', 0.00),
	(13, 1, 1, 'Urbano Neto', '14997672412', 3, '2026-10-09', '18:30:00', 'pendente', NULL, '2026-10-08 02:04:29', NULL, NULL, NULL, 'reais', 0.00),
	(14, 1, 1, 'Urbano Neto', '14997672412', 12, '2026-10-08', '18:00:00', 'concluido', NULL, '2026-10-08 02:04:58', 'pix', 150.00, 2, 'reais', 0.00),
	(15, 1, 1, 'Urbano Neto', '14997672412', 12, '2026-10-13', '18:30:00', 'concluido', NULL, '2026-10-09 22:23:52', 'pix', 150.00, 1, 'reais', 0.00),
	(16, 1, 1, 'Urbano Neto', '14997672412', 12, '2026-10-15', '19:00:00', 'concluido', NULL, '2026-10-09 22:25:58', 'pix', 0.00, 1, 'reais', 0.00);

-- Copiando estrutura para tabela barbearia_db.bloqueios_agenda
DROP TABLE IF EXISTS `bloqueios_agenda`;
CREATE TABLE IF NOT EXISTS `bloqueios_agenda` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `profissional_id` int(11) DEFAULT NULL,
  `data_bloqueio` date NOT NULL,
  `motivo` varchar(255) NOT NULL DEFAULT 'Fechado / Folga Geral',
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `data_bloqueio` (`data_bloqueio`),
  KEY `fk_bloqueio_profissional` (`profissional_id`),
  CONSTRAINT `fk_bloqueio_profissional` FOREIGN KEY (`profissional_id`) REFERENCES `profissionais` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copiando dados para a tabela barbearia_db.bloqueios_agenda: ~1 rows (aproximadamente)
INSERT IGNORE INTO `bloqueios_agenda` (`id`, `profissional_id`, `data_bloqueio`, `motivo`, `criado_em`) VALUES
	(1, NULL, '2026-10-12', 'folga', '2026-10-09 21:59:32'),
	(2, NULL, '2026-10-14', 'folga', '2026-10-09 22:00:01');

-- Copiando estrutura para tabela barbearia_db.cliente_pacotes
DROP TABLE IF EXISTS `cliente_pacotes`;
CREATE TABLE IF NOT EXISTS `cliente_pacotes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cliente_id` int(11) NOT NULL,
  `servico_id` int(11) NOT NULL,
  `sessoes_total` int(11) NOT NULL DEFAULT 3,
  `sessoes_restantes` int(11) NOT NULL DEFAULT 3,
  `data_compra` date NOT NULL,
  `data_expiracao` date NOT NULL,
  `status` enum('ativo','esgotado','expirado') NOT NULL DEFAULT 'ativo',
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `cliente_id` (`cliente_id`),
  KEY `servico_id` (`servico_id`),
  CONSTRAINT `cliente_pacotes_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cliente_pacotes_ibfk_2` FOREIGN KEY (`servico_id`) REFERENCES `servicos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copiando dados para a tabela barbearia_db.cliente_pacotes: ~2 rows (aproximadamente)
INSERT IGNORE INTO `cliente_pacotes` (`id`, `cliente_id`, `servico_id`, `sessoes_total`, `sessoes_restantes`, `data_compra`, `data_expiracao`, `status`, `criado_em`) VALUES
	(1, 1, 12, 3, 1, '2026-10-09', '2026-11-08', 'ativo', '2026-10-09 22:24:56'),
	(2, 1, 12, 3, 2, '2026-10-09', '2026-11-08', 'ativo', '2026-10-09 23:46:43');

-- Copiando estrutura para tabela barbearia_db.clientes
DROP TABLE IF EXISTS `clientes`;
CREATE TABLE IF NOT EXISTS `clientes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `senha` varchar(255) DEFAULT NULL,
  `telefone` varchar(20) DEFAULT NULL,
  `cpf` varchar(14) DEFAULT NULL,
  `data_nascimento` date DEFAULT NULL,
  `oauth_provider` enum('local','google','facebook') DEFAULT 'local',
  `oauth_uid` varchar(100) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `cpf` (`cpf`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copiando dados para a tabela barbearia_db.clientes: ~1 rows (aproximadamente)
INSERT IGNORE INTO `clientes` (`id`, `nome`, `email`, `senha`, `telefone`, `cpf`, `data_nascimento`, `oauth_provider`, `oauth_uid`, `criado_em`) VALUES
	(1, 'Urbano Neto', 'urbano.uph@gmail.com', 'Henrique@64', '14997672412', '38451507859', '1991-02-16', 'local', NULL, '2026-10-07 16:20:39');

-- Copiando estrutura para tabela barbearia_db.itens_venda_produtos
DROP TABLE IF EXISTS `itens_venda_produtos`;
CREATE TABLE IF NOT EXISTS `itens_venda_produtos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venda_id` int(11) NOT NULL,
  `produto_id` int(11) NOT NULL,
  `quantidade` int(11) NOT NULL,
  `preco_unitario` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `venda_id` (`venda_id`),
  KEY `produto_id` (`produto_id`),
  CONSTRAINT `itens_venda_produtos_ibfk_1` FOREIGN KEY (`venda_id`) REFERENCES `vendas_produtos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `itens_venda_produtos_ibfk_2` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copiando dados para a tabela barbearia_db.itens_venda_produtos: ~3 rows (aproximadamente)
INSERT IGNORE INTO `itens_venda_produtos` (`id`, `venda_id`, `produto_id`, `quantidade`, `preco_unitario`, `subtotal`) VALUES
	(1, 1, 1, 1, 45.00, 45.00),
	(2, 2, 5, 1, 5.00, 5.00),
	(3, 3, 5, 1, 5.00, 5.00);

-- Copiando estrutura para tabela barbearia_db.movimentacoes_financeiras
DROP TABLE IF EXISTS `movimentacoes_financeiras`;
CREATE TABLE IF NOT EXISTS `movimentacoes_financeiras` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tipo` enum('receita','despesa') NOT NULL,
  `categoria` varchar(100) NOT NULL,
  `descricao` varchar(255) NOT NULL,
  `valor` decimal(10,2) NOT NULL,
  `data_movimentacao` date NOT NULL,
  `forma_pagamento` varchar(50) DEFAULT 'pix',
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copiando dados para a tabela barbearia_db.movimentacoes_financeiras: ~0 rows (aproximadamente)

-- Copiando estrutura para tabela barbearia_db.notificacoes_inscritos
DROP TABLE IF EXISTS `notificacoes_inscritos`;
CREATE TABLE IF NOT EXISTS `notificacoes_inscritos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `endpoint` text NOT NULL,
  `p256dh` varchar(255) NOT NULL,
  `auth` varchar(255) NOT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copiando dados para a tabela barbearia_db.notificacoes_inscritos: ~0 rows (aproximadamente)

-- Copiando estrutura para tabela barbearia_db.produtos
DROP TABLE IF EXISTS `produtos`;
CREATE TABLE IF NOT EXISTS `produtos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `descricao` text DEFAULT NULL,
  `preco_custo` decimal(10,2) NOT NULL DEFAULT 0.00,
  `preco_venda` decimal(10,2) NOT NULL,
  `comissao_percentual` decimal(5,2) NOT NULL DEFAULT 10.00,
  `quantidade_estoque` int(11) NOT NULL DEFAULT 0,
  `estoque_minimo` int(11) NOT NULL DEFAULT 5,
  `status` enum('ativo','inativo') DEFAULT 'ativo',
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copiando dados para a tabela barbearia_db.produtos: ~4 rows (aproximadamente)
INSERT IGNORE INTO `produtos` (`id`, `nome`, `descricao`, `preco_custo`, `preco_venda`, `comissao_percentual`, `quantidade_estoque`, `estoque_minimo`, `status`, `criado_em`) VALUES
	(1, 'Pomada', NULL, 13.00, 45.00, 10.00, 8, 5, 'ativo', '2026-10-07 16:57:24'),
	(2, 'Pomada', NULL, 13.00, 45.00, 10.00, 10, 5, 'inativo', '2026-10-07 17:01:11'),
	(3, 'cerveja', NULL, 5.00, 10.00, 10.00, 10, 5, 'inativo', '2026-10-07 17:31:02'),
	(4, 'cerveja', NULL, 5.00, 10.00, 10.00, 8, 5, 'ativo', '2026-10-07 17:55:40'),
	(5, 'Coca_cola', NULL, 2.00, 5.00, 10.00, 8, 5, 'ativo', '2026-10-07 17:55:57');

-- Copiando estrutura para tabela barbearia_db.profissionais
DROP TABLE IF EXISTS `profissionais`;
CREATE TABLE IF NOT EXISTS `profissionais` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `especialidade` varchar(100) DEFAULT 'Barbeiro',
  `telefone` varchar(20) DEFAULT NULL,
  `status` enum('ativo','inativo') DEFAULT 'ativo',
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copiando dados para a tabela barbearia_db.profissionais: ~3 rows (aproximadamente)
INSERT IGNORE INTO `profissionais` (`id`, `nome`, `especialidade`, `telefone`, `status`, `criado_em`) VALUES
	(1, 'MIguel Martins', 'Miguel', '14977515463', 'ativo', '2026-10-07 16:51:37'),
	(2, 'Jane Martins', 'Jane', '149976724154', 'ativo', '2026-10-07 17:21:24'),
	(3, 'Vandemil', 'Van', '14975841474', 'ativo', '2026-10-07 19:22:51');

-- Copiando estrutura para tabela barbearia_db.profissional_servicos
DROP TABLE IF EXISTS `profissional_servicos`;
CREATE TABLE IF NOT EXISTS `profissional_servicos` (
  `profissional_id` int(11) NOT NULL,
  `servico_id` int(11) NOT NULL,
  `comissao_percentual` decimal(5,2) NOT NULL DEFAULT 50.00,
  PRIMARY KEY (`profissional_id`,`servico_id`),
  KEY `servico_id` (`servico_id`),
  CONSTRAINT `profissional_servicos_ibfk_1` FOREIGN KEY (`profissional_id`) REFERENCES `profissionais` (`id`) ON DELETE CASCADE,
  CONSTRAINT `profissional_servicos_ibfk_2` FOREIGN KEY (`servico_id`) REFERENCES `servicos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copiando dados para a tabela barbearia_db.profissional_servicos: ~13 rows (aproximadamente)
INSERT IGNORE INTO `profissional_servicos` (`profissional_id`, `servico_id`, `comissao_percentual`) VALUES
	(1, 1, 50.00),
	(1, 2, 50.00),
	(1, 3, 50.00),
	(1, 5, 50.00),
	(1, 11, 80.00),
	(1, 12, 50.00),
	(2, 5, 50.00),
	(2, 7, 50.00),
	(2, 8, 50.00),
	(2, 9, 50.00),
	(3, 1, 50.00),
	(3, 2, 75.00),
	(3, 3, 60.00);

-- Copiando estrutura para tabela barbearia_db.servicos
DROP TABLE IF EXISTS `servicos`;
CREATE TABLE IF NOT EXISTS `servicos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `descricao` text DEFAULT NULL,
  `preco` decimal(10,2) NOT NULL,
  `duracao_minutos` int(11) NOT NULL DEFAULT 30,
  `comissao_percentual` decimal(5,2) NOT NULL DEFAULT 50.00,
  `status` enum('ativo','inativo') DEFAULT 'ativo',
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  `eh_pacote` tinyint(1) NOT NULL DEFAULT 0,
  `sessoes_pacote` int(11) NOT NULL DEFAULT 1,
  `validade_dias` int(11) NOT NULL DEFAULT 30,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copiando dados para a tabela barbearia_db.servicos: ~9 rows (aproximadamente)
INSERT IGNORE INTO `servicos` (`id`, `nome`, `descricao`, `preco`, `duracao_minutos`, `comissao_percentual`, `status`, `criado_em`, `eh_pacote`, `sessoes_pacote`, `validade_dias`) VALUES
	(1, 'Corte Tradicional', NULL, 35.00, 30, 50.00, 'ativo', '2026-10-07 01:07:30', 0, 1, 30),
	(2, 'Barba Terapia', NULL, 30.00, 30, 50.00, 'ativo', '2026-10-07 01:07:30', 0, 1, 30),
	(3, 'Combo Corte + Barba', NULL, 60.00, 60, 50.00, 'ativo', '2026-10-07 01:07:30', 0, 1, 30),
	(5, 'Sobrancelha', NULL, 15.00, 15, 50.00, 'ativo', '2026-10-07 16:49:25', 0, 1, 30),
	(7, 'Pé', NULL, 20.00, 30, 50.00, 'ativo', '2026-10-07 17:21:40', 0, 1, 30),
	(8, 'Mão', NULL, 30.00, 30, 50.00, 'ativo', '2026-10-07 17:21:47', 0, 1, 30),
	(9, 'Pé+Mão', NULL, 45.00, 60, 50.00, 'ativo', '2026-10-07 17:22:06', 0, 1, 30),
	(11, 'Tudo', NULL, 500.00, 90, 60.00, 'ativo', '2026-10-07 19:03:05', 0, 1, 30),
	(12, 'pacote corte mes', NULL, 150.00, 30, 50.00, 'ativo', '2026-10-07 22:41:00', 1, 3, 30);

-- Copiando estrutura para tabela barbearia_db.turnos_atendimento
DROP TABLE IF EXISTS `turnos_atendimento`;
CREATE TABLE IF NOT EXISTS `turnos_atendimento` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `profissional_id` int(11) DEFAULT NULL,
  `dia_semana` tinyint(4) DEFAULT NULL,
  `data_atendimento` date DEFAULT NULL,
  `hora_inicio` time NOT NULL,
  `hora_fim` time NOT NULL,
  `pausa_inicio` time DEFAULT NULL,
  `pausa_fim` time DEFAULT NULL,
  `intervalo_minutos` int(11) NOT NULL DEFAULT 30,
  `tipo_atendimento` enum('horario_marcado','ordem_chegada') NOT NULL DEFAULT 'horario_marcado',
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_turnos_profissional` (`profissional_id`),
  CONSTRAINT `fk_turnos_profissional` FOREIGN KEY (`profissional_id`) REFERENCES `profissionais` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copiando dados para a tabela barbearia_db.turnos_atendimento: ~6 rows (aproximadamente)
INSERT IGNORE INTO `turnos_atendimento` (`id`, `profissional_id`, `dia_semana`, `data_atendimento`, `hora_inicio`, `hora_fim`, `pausa_inicio`, `pausa_fim`, `intervalo_minutos`, `tipo_atendimento`, `criado_em`) VALUES
	(29, 1, 6, NULL, '09:00:00', '18:00:00', NULL, NULL, 30, 'ordem_chegada', '2026-10-08 01:03:50'),
	(30, 1, 1, NULL, '18:00:00', '22:00:00', NULL, NULL, 30, 'horario_marcado', '2026-10-08 01:07:33'),
	(31, 1, 2, NULL, '18:00:00', '22:00:00', NULL, NULL, 30, 'horario_marcado', '2026-10-08 01:07:33'),
	(32, 1, 3, NULL, '18:00:00', '22:00:00', NULL, NULL, 30, 'horario_marcado', '2026-10-08 01:07:33'),
	(33, 1, 4, NULL, '18:00:00', '22:00:00', NULL, NULL, 30, 'horario_marcado', '2026-10-08 01:07:33'),
	(34, 1, 5, NULL, '18:00:00', '22:00:00', NULL, NULL, 30, 'horario_marcado', '2026-10-08 01:07:33');

-- Copiando estrutura para tabela barbearia_db.usuarios
DROP TABLE IF EXISTS `usuarios`;
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `senha` varchar(255) NOT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copiando dados para a tabela barbearia_db.usuarios: ~1 rows (aproximadamente)
INSERT IGNORE INTO `usuarios` (`id`, `nome`, `email`, `senha`, `criado_em`) VALUES
	(1, 'Administrador', 'admin@barbearia.com', '123456', '2026-10-07 01:07:30');

-- Copiando estrutura para tabela barbearia_db.vendas_produtos
DROP TABLE IF EXISTS `vendas_produtos`;
CREATE TABLE IF NOT EXISTS `vendas_produtos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cliente_id` int(11) DEFAULT NULL,
  `profissional_id` int(11) DEFAULT NULL,
  `forma_pagamento` enum('dinheiro','pix','cartao_credito','cartao_debito') NOT NULL DEFAULT 'pix',
  `total` decimal(10,2) NOT NULL,
  `data_venda` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `cliente_id` (`cliente_id`),
  KEY `profissional_id` (`profissional_id`),
  CONSTRAINT `vendas_produtos_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vendas_produtos_ibfk_2` FOREIGN KEY (`profissional_id`) REFERENCES `profissionais` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copiando dados para a tabela barbearia_db.vendas_produtos: ~3 rows (aproximadamente)
INSERT IGNORE INTO `vendas_produtos` (`id`, `cliente_id`, `profissional_id`, `forma_pagamento`, `total`, `data_venda`) VALUES
	(1, 1, 1, 'pix', 45.00, '2026-10-07 14:02:13'),
	(2, NULL, 2, 'pix', 5.00, '2026-10-07 16:09:37'),
	(3, NULL, 1, 'pix', 5.00, '2026-10-07 16:10:39');

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
