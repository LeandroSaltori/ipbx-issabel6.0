<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version {ISSBEL_VERSION}                                     |
  | http://www.issabel.org                                               |
  +----------------------------------------------------------------------+
  | Copyright (c) 2017 Issabel Foundation                                |
  | Copyright (c) 2006 Palosanto Solutions S. A.                         |
  +----------------------------------------------------------------------+
  | The Initial Developer of the Original Code is PaloSanto Solutions    |
  +----------------------------------------------------------------------+
  $Id: paloSantoPesquisa.class.php,v 1.9 2026-08-17 Prisma Telecom $ */

@include_once "libs/paloSantoDB.class.php";
@include_once "libs/paloSantoConfig.class.php";
@include_once "libs/misc.lib.php";

class paloSantoPesquisa {
    var $_DB;
    var $errMsg;

    function __construct(&$pDB)
    {
        if (is_object($pDB) && isset($pDB->connStatus) && $pDB->connStatus) {
            $this->_DB = $pDB;
        } else {
            $this->_DB = $this->connectBestDatabase();
        }
    }

    function paloSantoPesquisa(&$pDB)
    {
        $this->__construct($pDB);
    }

    function connectBestDatabase()
    {
        @include_once "/var/www/html/libs/paloSantoConfig.class.php";
        @include_once "/var/www/html/libs/misc.lib.php";

        // 1. Tenta conexao nativa do Issabel framework
        if (function_exists('generarDSNSistema')) {
            $dsn = @generarDSNSistema('asteriskuser', 'asteriskcdrdb');
            if (!empty($dsn)) {
                $pDB = new paloDB($dsn);
                if ($pDB && $pDB->connStatus) {
                    $test = $pDB->getFirstRowQuery("SELECT count(*) FROM pesquisa", false);
                    if ($test !== false) return $pDB;
                }
            }
        }

        // 2. Extrai senhas do amportal.conf e issabel.conf
        $passwords = array('', 'asterisk', 'asteriskuser');
        if (file_exists('/etc/issabel.conf')) {
            $c = @file_get_contents('/etc/issabel.conf');
            if (preg_match('/mysqlrootpwd\s*=\s*(.*)/i', $c, $m)) $passwords[] = trim($m[1]);
        }
        if (file_exists('/etc/amportal.conf')) {
            $c = @file_get_contents('/etc/amportal.conf');
            if (preg_match('/AMPDBPASS\s*=\s*(.*)/i', $c, $m)) $passwords[] = trim($m[1]);
            if (preg_match('/CDRDBPASS\s*=\s*(.*)/i', $c, $m)) $passwords[] = trim($m[1]);
        }
        if (file_exists('/etc/asterisk/cdr_mysql.conf')) {
            $c = @file_get_contents('/etc/asterisk/cdr_mysql.conf');
            if (preg_match('/password\s*=\s*(.*)/i', $c, $m)) $passwords[] = trim($m[1]);
        }

        $users = array('root', 'asteriskuser', 'asterisk');
        foreach ($users as $u) {
            foreach ($passwords as $p) {
                $dsn = "mysql://$u:$p@localhost/asteriskcdrdb";
                $pDB = new paloDB($dsn);
                if ($pDB && $pDB->connStatus) {
                    $test = $pDB->getFirstRowQuery("SELECT count(*) FROM pesquisa", false);
                    if ($test !== false) return $pDB;
                }
            }
        }

        // 3. Fallback SQLite
        return new paloDB("sqlite3:////var/www/db/pesquisa.db");
    }

    function getNumPesquisa($filter_field = '', $filter_value = '', $date_start = '', $date_end = '', $operador = '', $avaliacao = '', $solucao = '')
    {
        $where = array();
        $params = array();

        if (!empty($filter_field) && !empty($filter_value)) {
            $where[] = "$filter_field LIKE ?";
            $params[] = "%$filter_value%";
        }
        if (!empty($date_start)) {
            $where[] = "data >= ?";
            $params[] = $date_start;
        }
        if (!empty($date_end)) {
            $where[] = "data <= ?";
            $params[] = $date_end;
        }
        if (!empty($operador)) {
            $where[] = "operador LIKE ?";
            $params[] = "%$operador%";
        }
        if (!empty($avaliacao)) {
            $where[] = "(UPPER(avaliacao) = ? OR avaliacao = ?)";
            $params[] = strtoupper($avaliacao);
            $params[] = $avaliacao;
        }
        if (!empty($solucao)) {
            $where[] = "(UPPER(solucao) = ? OR solucao = ?)";
            $params[] = strtoupper($solucao);
            $params[] = $solucao;
        }

        $strWhere = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        $query = "SELECT COUNT(*) FROM pesquisa $strWhere";

        if ($this->_DB) {
            $arrParam = !empty($params) ? $params : false;
            $result = $this->_DB->getFirstRowQuery($query, false, $arrParam);
            if ($result !== false && isset($result[0])) {
                return (int)$result[0];
            }
        }
        return 0;
    }

    function getPesquisa($limit, $offset, $filter_field = '', $filter_value = '', $date_start = '', $date_end = '', $operador = '', $avaliacao = '', $solucao = '')
    {
        $where = array();
        $params = array();

        if (!empty($filter_field) && !empty($filter_value)) {
            $where[] = "$filter_field LIKE ?";
            $params[] = "%$filter_value%";
        }
        if (!empty($date_start)) {
            $where[] = "data >= ?";
            $params[] = $date_start;
        }
        if (!empty($date_end)) {
            $where[] = "data <= ?";
            $params[] = $date_end;
        }
        if (!empty($operador)) {
            $where[] = "operador LIKE ?";
            $params[] = "%$operador%";
        }
        if (!empty($avaliacao)) {
            $where[] = "(UPPER(avaliacao) = ? OR avaliacao = ?)";
            $params[] = strtoupper($avaliacao);
            $params[] = $avaliacao;
        }
        if (!empty($solucao)) {
            $where[] = "(UPPER(solucao) = ? OR solucao = ?)";
            $params[] = strtoupper($solucao);
            $params[] = $solucao;
        }

        $strWhere = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        $query = "SELECT * FROM pesquisa $strWhere ORDER BY data DESC, hora DESC LIMIT $limit OFFSET $offset";

        if ($this->_DB) {
            $arrParam = !empty($params) ? $params : false;
            $result = $this->_DB->fetchTable($query, true, $arrParam);
            if (is_array($result)) return $result;
        }
        return array();
    }

    function getPesquisaStats($date_start = '', $date_end = '', $operador = '')
    {
        $where = array();
        $params = array();

        if (!empty($date_start)) {
            $where[] = "data >= ?";
            $params[] = $date_start;
        }
        if (!empty($date_end)) {
            $where[] = "data <= ?";
            $params[] = $date_end;
        }
        if (!empty($operador)) {
            $where[] = "operador LIKE ?";
            $params[] = "%$operador%";
        }

        $strWhere = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

        $query = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN UPPER(avaliacao) IN ('EXCELENTE', 'OTIMO', 'ÓTIMO', '5') THEN 1 ELSE 0 END) as otimo,
            SUM(CASE WHEN UPPER(avaliacao) IN ('MUITO BOM', '4') THEN 1 ELSE 0 END) as muito_bom,
            SUM(CASE WHEN UPPER(avaliacao) IN ('BOM', 'MEDIO', 'MÉDIO', 'REGULAR', '3') THEN 1 ELSE 0 END) as medio,
            SUM(CASE WHEN UPPER(avaliacao) IN ('RUIM', '2') THEN 1 ELSE 0 END) as bom,
            SUM(CASE WHEN UPPER(avaliacao) IN ('PESSIMO', 'PÉSSIMO', '1') THEN 1 ELSE 0 END) as ruim,
            SUM(CASE WHEN UPPER(solucao) IN ('SIM', '1') THEN 1 ELSE 0 END) as resolvido_sim,
            SUM(CASE WHEN UPPER(solucao) IN ('NAO', 'NÃO', '2') THEN 1 ELSE 0 END) as resolvido_nao
            FROM pesquisa $strWhere";

        $stats = false;
        if ($this->_DB) {
            $arrParam = !empty($params) ? $params : false;
            $stats = $this->_DB->getFirstRowQuery($query, true, $arrParam);
        }

        if (!$stats || empty($stats['total'])) {
            if (!empty($strWhere)) {
                $queryAll = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN UPPER(avaliacao) IN ('EXCELENTE', 'OTIMO', 'ÓTIMO', '5') THEN 1 ELSE 0 END) as otimo,
                    SUM(CASE WHEN UPPER(avaliacao) IN ('MUITO BOM', '4') THEN 1 ELSE 0 END) as muito_bom,
                    SUM(CASE WHEN UPPER(avaliacao) IN ('BOM', 'MEDIO', 'MÉDIO', 'REGULAR', '3') THEN 1 ELSE 0 END) as medio,
                    SUM(CASE WHEN UPPER(avaliacao) IN ('RUIM', '2') THEN 1 ELSE 0 END) as bom,
                    SUM(CASE WHEN UPPER(avaliacao) IN ('PESSIMO', 'PÉSSIMO', '1') THEN 1 ELSE 0 END) as ruim,
                    SUM(CASE WHEN UPPER(solucao) IN ('SIM', '1') THEN 1 ELSE 0 END) as resolvido_sim,
                    SUM(CASE WHEN UPPER(solucao) IN ('NAO', 'NÃO', '2') THEN 1 ELSE 0 END) as resolvido_nao
                    FROM pesquisa";
                $stats = $this->_DB->getFirstRowQuery($queryAll, true, false);
            }
        }

        if (!$stats || empty($stats['total'])) {
            return array(
                'total' => 0, 'otimo' => 0, 'muito_bom' => 0, 'medio' => 0, 'bom' => 0, 'ruim' => 0,
                'resolvido_sim' => 0, 'resolvido_nao' => 0, 'media_estrelas' => 0,
                'taxa_resolucao' => 0, 'taxa_satisfacao' => 0
            );
        }

        $total = (int)$stats['total'];
        $otimo = (int)$stats['otimo'];
        $muito_bom = (int)$stats['muito_bom'];
        $medio = (int)$stats['medio'];
        $bom = (int)$stats['bom'];
        $ruim = (int)$stats['ruim'];
        $sim = (int)$stats['resolvido_sim'];
        $nao = (int)$stats['resolvido_nao'];

        $somaPontos = ($otimo * 5) + ($muito_bom * 4) + ($medio * 3) + ($bom * 2) + ($ruim * 1);
        $mediaEstrelas = $total > 0 ? round($somaPontos / $total, 1) : 0;
        $taxaResolucao = ($sim + $nao) > 0 ? round(($sim / ($sim + $nao)) * 100, 1) : 0;
        $taxaSatisfacao = $total > 0 ? round((($otimo + $muito_bom) / $total) * 100, 1) : 0;

        return array(
            'total' => $total,
            'otimo' => $otimo,
            'muito_bom' => $muito_bom,
            'medio' => $medio,
            'bom' => $bom,
            'ruim' => $ruim,
            'resolvido_sim' => $sim,
            'resolvido_nao' => $nao,
            'media_estrelas' => $mediaEstrelas,
            'taxa_resolucao' => $taxaResolucao,
            'taxa_satisfacao' => $taxaSatisfacao
        );
    }

    function getPesquisaById($id)
    {
        $query = "SELECT * FROM pesquisa WHERE id=?";
        $result = $this->_DB->getFirstRowQuery($query, true, array("$id"));
        if ($result === false) {
            $this->errMsg = $this->_DB->errMsg;
            return null;
        }
        return $result;
    }
}
