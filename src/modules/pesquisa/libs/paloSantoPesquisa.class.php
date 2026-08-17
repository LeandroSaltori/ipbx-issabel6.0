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
  $Id: paloSantoPesquisa.class.php,v 1.3 2026-08-17 Prisma Telecom $ */

class paloSantoPesquisa {
    var $_DB;
    var $errMsg;
    var $_pdo;

    function __construct(&$pDB)
    {
        if (is_object($pDB) && !empty($pDB->connStatus)) {
            $this->_DB = $pDB;
        } else {
            global $arrConf;
            $dbPath = !empty($arrConf['issabel_dbdir']) ? "$arrConf[issabel_dbdir]/pesquisa.db" : "/var/www/db/pesquisa.db";
            $this->_DB = new paloDB("sqlite3:///$dbPath");
            if (!$this->_DB->connStatus) {
                $this->_DB = new paloDB("sqlite3:////var/www/db/pesquisa.db");
            }
        }

        // Fallback direto via PDO SQLite
        $possiblePaths = array(
            "/var/www/db/pesquisa.db",
            "/var/www/html/modules/pesquisa/pesquisa.db"
        );
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                try {
                    $this->_pdo = new PDO("sqlite:$path");
                    $this->_pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                    break;
                } catch (Exception $e) {}
            }
        }
    }

    function paloSantoPesquisa(&$pDB)
    {
        $this->__construct($pDB);
    }

    private function _buildWhere($date_start, $date_end, $operador, $avaliacao, $solucao)
    {
        $where = array();
        $params = array();

        if (!empty($date_start)) {
            $d_br = date('d/m/Y', strtotime($date_start));
            $where[] = "(data >= ? OR data LIKE ?)";
            $params[] = $date_start;
            $params[] = "%$d_br%";
        }
        if (!empty($date_end)) {
            $d_br = date('d/m/Y', strtotime($date_end));
            $where[] = "(data <= ? OR data LIKE ?)";
            $params[] = $date_end;
            $params[] = "%$d_br%";
        }
        if (!empty($operador)) {
            $where[] = "(operador LIKE ? OR ramal LIKE ?)";
            $params[] = "%$operador%";
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
        return array($strWhere, $params);
    }

    function getNumPesquisa($date_start = null, $date_end = null, $operador = null, $avaliacao = null, $solucao = null)
    {
        list($strWhere, $params) = $this->_buildWhere($date_start, $date_end, $operador, $avaliacao, $solucao);
        $query = "SELECT COUNT(*) FROM pesquisa $strWhere";

        if ($this->_pdo) {
            try {
                $stmt = $this->_pdo->prepare($query);
                $stmt->execute($params);
                return (int)$stmt->fetchColumn();
            } catch (Exception $e) {}
        }

        if ($this->_DB) {
            $result = $this->_DB->getFirstRowQuery($query, false, $params);
            if ($result !== false && isset($result[0])) {
                return (int)$result[0];
            }
        }
        return 0;
    }

    function getPesquisa($limit, $offset, $date_start = null, $date_end = null, $operador = null, $avaliacao = null, $solucao = null)
    {
        list($strWhere, $params) = $this->_buildWhere($date_start, $date_end, $operador, $avaliacao, $solucao);
        $query = "SELECT * FROM pesquisa $strWhere ORDER BY rowid DESC LIMIT $limit OFFSET $offset";

        if ($this->_pdo) {
            try {
                $stmt = $this->_pdo->prepare($query);
                $stmt->execute($params);
                $rows = $stmt->fetchAll();
                if (is_array($rows)) return $rows;
            } catch (Exception $e) {}
        }

        if ($this->_DB) {
            $result = $this->_DB->fetchTable($query, true, $params);
            if (is_array($result)) return $result;
        }
        return array();
    }

    function getPesquisaStats($date_start = null, $date_end = null, $operador = null)
    {
        list($strWhere, $params) = $this->_buildWhere($date_start, $date_end, $operador, null, null);

        $query = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN UPPER(avaliacao) IN ('OTIMO', 'ÓTIMO', '5') THEN 1 ELSE 0 END) as otimo,
            SUM(CASE WHEN UPPER(avaliacao) IN ('MUITO BOM', '4') THEN 1 ELSE 0 END) as muito_bom,
            SUM(CASE WHEN UPPER(avaliacao) IN ('MEDIO', 'MÉDIO', '3') THEN 1 ELSE 0 END) as medio,
            SUM(CASE WHEN UPPER(avaliacao) IN ('BOM', '2') THEN 1 ELSE 0 END) as bom,
            SUM(CASE WHEN UPPER(avaliacao) IN ('RUIM', '1') THEN 1 ELSE 0 END) as ruim,
            SUM(CASE WHEN UPPER(solucao) IN ('SIM', '1') THEN 1 ELSE 0 END) as resolvido_sim,
            SUM(CASE WHEN UPPER(solucao) IN ('NAO', 'NÃO', '2') THEN 1 ELSE 0 END) as resolvido_nao
            FROM pesquisa $strWhere";

        $stats = false;
        if ($this->_pdo) {
            try {
                $stmt = $this->_pdo->prepare($query);
                $stmt->execute($params);
                $stats = $stmt->fetch();
            } catch (Exception $e) {}
        }

        if (!$stats && $this->_DB) {
            $stats = $this->_DB->getFirstRowQuery($query, true, $params);
        }

        if (!$stats || empty($stats['total'])) {
            // Se não encontrou registros com filtro, tenta buscar o total geral
            if (!empty($strWhere)) {
                return $this->getPesquisaStats(null, null, null);
            }
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
}
