<?php
/**
 * IPbx Prisma - índice de apoio da tabela de chamadas (cdr).
 * O filtro "Ocultar chamadas repetidas" procura, para cada chamada, outras do mesmo número em +-60 s. Sem um índice por
 * (origem, data) isso vira uma varredura da tabela inteira para cada linha: com 10 mil chamadas, um relatório levava ~20 s.
 * Só ACRESCENTA um índice (não altera nem apaga nenhum dado) e é seguro rodar quantas vezes for preciso.
 * Retorna uma frase curta com o que foi feito.
 */
function ensureCdrIndex($c) {
    if (!($c instanceof PDO)) return 'Sem acesso ao banco de chamadas.';
    try {
        $has = $c->query("SHOW INDEX FROM cdr WHERE Key_name = 'idx_prisma_src_calldate'")->fetch();
        if ($has) return 'Índice das chamadas já existe.';
        $info = $c->query("SELECT ENGINE, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cdr'")->fetch(PDO::FETCH_ASSOC) ?: [];
        $rows = (int)($info['TABLE_ROWS'] ?? 0);
        $eng  = strtoupper((string)($info['ENGINE'] ?? ''));
        $sql  = "ALTER TABLE cdr ADD INDEX idx_prisma_src_calldate (src, calldate)";
        // InnoDB moderno cria o índice sem bloquear as gravações; se não houver suporte, só segue em tabelas pequenas
        try {
            if ($eng === 'INNODB') { $c->exec($sql . ", ALGORITHM=INPLACE, LOCK=NONE"); return 'Índice das chamadas criado (sem bloquear a central).'; }
        } catch (Exception $e) { /* tenta o modo comum abaixo */ }
        if ($rows > 300000) return 'Índice das chamadas NÃO criado: a tabela é grande (' . $rows . ' linhas) e este servidor bloquearia as gravações durante a criação. Crie fora do horário de pico.';
        $c->exec($sql);
        return 'Índice das chamadas criado.';
    } catch (Exception $e) {
        return 'Índice das chamadas não criado: ' . substr($e->getMessage(), 0, 120);
    }
}
