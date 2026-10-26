ALTER TABLE usuarios
    ADD COLUMN identificador_fiscal VARCHAR(20) NULL AFTER email,
    ADD UNIQUE KEY uq_usuarios_identificador_fiscal (identificador_fiscal);
