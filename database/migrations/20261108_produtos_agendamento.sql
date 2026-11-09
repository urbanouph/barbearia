-- Executar uma única vez no banco atual (barbearia_db).
-- Produtos existentes permanecem ocultos no agendamento por segurança.
ALTER TABLE produtos
    ADD COLUMN exibir_agendamento TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Permite oferecer este produto como adicional no agendamento público';
