<?php
/**
 * IPbx Prisma - Diagnóstico do banco de dados em linguagem simples (sem expor senhas).
 * Usado em Configurações > Status (botões "Tentar consertar" e "Copiar relatório") e no fim do instalador.
 */

/** Verifica o MySQL e as senhas que o painel sabe tentar. Devolve ['ok'=>bool,'linhas'=>[...],'dica'=>string,'origem'=>string]. */
function dbDiagnose() {
    $linhas = []; $origem = ''; $dica = '';
    // 1. MySQL no ar?
    $up = false;
    foreach ([['127.0.0.1', 3306], ['localhost', 3306]] as $h) { $s = @fsockopen($h[0], $h[1], $en, $es, 2); if ($s) { fclose($s); $up = true; break; } }
    $linhas[] = ($up ? '[OK] ' : '[FALHA] ') . 'MySQL ' . ($up ? 'respondendo na porta 3306.' : 'não responde na porta 3306.');
    if (!$up) return ['ok' => false, 'linhas' => $linhas, 'origem' => '', 'dica' => 'O MySQL (MariaDB) está parado. No servidor, rode: systemctl start mariadb (ou: service mysqld start) e use "Tentar consertar" de novo.'];
    // 2. Arquivos de configuração do sistema
    $achou = false;
    foreach (['/etc/issabel.conf', '/etc/elastix.conf', '/etc/amportal.conf'] as $f) {
        if (!file_exists($f)) continue;
        if (!is_readable($f)) { $linhas[] = "[ATENÇÃO] $f existe, mas o painel não consegue ler (permissão)."; continue; }
        $achou = true; $linhas[] = "[OK] $f lido.";
    }
    if (!$achou) $linhas[] = '[ATENÇÃO] Nenhum arquivo de configuração do sistema foi lido; usando as senhas padrão conhecidas.';
    $cfg = function_exists('getPABXSystemConfig') ? getPABXSystemConfig() : [];
    $linhas[] = !empty($cfg['mysqlrootpwd']) ? '[OK] Senha do MySQL encontrada no sistema (não é exibida).' : '[ATENÇÃO] Senha do MySQL não encontrada no sistema.';
    // 3. Alguma senha entra?
    $cdr = function_exists('getAsteriskPdoConnection') ? getAsteriskPdoConnection('asteriskcdrdb') : null;
    $ast = function_exists('getAsteriskPdoConnection') ? getAsteriskPdoConnection('asterisk') : null;
    $linhas[] = ($cdr ? '[OK] ' : '[FALHA] ') . 'Banco de chamadas (asteriskcdrdb): ' . ($cdr ? 'conectado.' : 'sem acesso.');
    $linhas[] = ($ast ? '[OK] ' : '[FALHA] ') . 'Banco da central (asterisk): ' . ($ast ? 'conectado.' : 'sem acesso.');
    if ($cdr) { try { $u = (string)$cdr->query('SELECT CURRENT_USER()')->fetchColumn(); if (trim($u, '@') !== '') $linhas[] = '[OK] Usuário MySQL em uso: ' . $u . '.'; } catch (Throwable $e) {} }
    $ok = (bool)($cdr && $ast);
    if (!$ok) {
        $dica = ($cdr && !$ast)
            ? 'O banco de chamadas está acessível, mas o banco da central (asterisk) não. Use "Tentar consertar"; se persistir, envie o relatório ao suporte.'
            : ($achou
            ? 'O MySQL está no ar, mas nenhuma senha conhecida entrou. Se a senha do MySQL foi trocada, atualize mysqlrootpwd em /etc/issabel.conf e use "Tentar consertar" de novo.'
            : 'O painel não consegue ler os arquivos de configuração do sistema. Rode o instalador novamente (ele ajusta as permissões) e use "Tentar consertar".');
    }
    return ['ok' => $ok, 'linhas' => $linhas, 'origem' => $achou ? 'sistema' : 'padrão', 'dica' => $dica];
}

/** Tenta corrigir o que o painel pode corrigir sozinho. Devolve linhas explicando o que foi feito. */
function dbRepair() {
    global $db;
    $feito = [];
    // Ajuste manual do banco (Configurações): nunca é apagado pelo painel; só avisamos se existir e o acesso estiver falhando
    $antes = dbDiagnose();
    if (!$antes['ok'] && trim((string)getSetting('asterisk_db_host') . (string)getSetting('asterisk_db_user')) !== '') $feito[] = 'Existe um ajuste manual do banco salvo. Se ele estiver errado, corrija ou apague em Configurações; não mexi nele.';
    // Permissões de gravação do banco do painel e da pasta de configurações
    $dir = __DIR__ . '/../config';
    if (!is_dir($dir)) { if (@mkdir($dir, 0775, true)) $feito[] = 'Criei a pasta de configurações.'; }
    if (is_dir($dir) && !is_writable($dir)) { if (@chmod($dir, 0775)) $feito[] = 'Ajustei a permissão da pasta de configurações.'; else $feito[] = 'A pasta de configurações não permite gravar: rode o instalador novamente.'; }
    $depois = dbDiagnose();
    $feito[] = $depois['ok'] ? 'Banco de dados: tudo certo.' : ('Ainda há um problema: ' . $depois['dica']);
    return ['ok' => $depois['ok'], 'feito' => $feito, 'diagnostico' => $depois];
}

/** Relatório de suporte em texto, sem senhas, tokens ou chaves. */
function supportReportText() {
    $out = ["Relatório IPbx Prisma - " . date('d/m/Y H:i'), 'Versão: ' . (is_file(__DIR__ . '/../../../../VERSION') ? trim((string)file_get_contents(__DIR__ . '/../../../../VERSION')) : 'Painel IPbx Prisma v6.1'), 'PHP: ' . PHP_VERSION, ''];
    $d = dbDiagnose(); $out[] = '== Banco de dados =='; foreach ($d['linhas'] as $l) $out[] = $l; if ($d['dica']) $out[] = 'Dica: ' . $d['dica'];
    $out[] = ''; $out[] = '== Verificações =='; $nm = ['ok' => 'OK', 'warn' => 'ATENÇÃO', 'fail' => 'FALHA', 'off' => 'DESATIVADO'];
    try { foreach (integrationStatusChecks() as $c) $out[] = '[' . ($nm[$c['estado']] ?? $c['estado']) . '] ' . $c['grupo'] . ' > ' . $c['nome'] . ': ' . preg_replace('/(token|senha|password|key)\s*[:=]\s*\S+/i', '$1=***', (string)$c['detalhe']); } catch (Throwable $e) { $out[] = 'Falha ao montar as verificações: ' . $e->getMessage(); }
    return implode("\n", $out) . "\n";
}
