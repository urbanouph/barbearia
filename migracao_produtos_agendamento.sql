-- Migração compatível com MariaDB 10.4+; produtos existentes permanecem ocultos no agendamento.
ALTER TABLE produtos
    ADD COLUMN IF NOT EXISTS mostrar_no_agendamento TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

-- As reservas de produtos e a devolução do estoque em cancelamentos usam transações.
ALTER TABLE produtos ENGINE=InnoDB;
ALTER TABLE agendamentos ENGINE=InnoDB;
ALTER TABLE agendamento_itens ENGINE=InnoDB;
