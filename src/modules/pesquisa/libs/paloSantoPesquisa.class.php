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
  $Id: paloSantoPesquisa.class.php,v 1.5 2026-08-17 Prisma Telecom $ */

class paloSantoPesquisa {
    var $_DB;
    var $errMsg;

    function __construct(&$pDB)
    {
        if (is_object($pDB) && isset($pDB->connStatus) && $pDB->connStatus) {
            $this->_DB = $pDB;
        } else {
            $dsn = "sqlite3:////var/www/db/pesquisa.db";
            $this->_DB = new paloDB($dsn);
        }
    }

    function paloSantoPesquisa(&$pDB)
    {
        $this->__construct($pDB);
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
            $where[] = "(data >= ? OR data LIKE ?)";
            $params[] = $date_start;
            $params[] = "%" . date('d/m/Y', strtotime($date_start)) . "%";
        }
        if (!empty($date_end)) {
            $where[] = "(data <= ? OR data LIKE ?)";
            $params[] = $date_end;
            $params[] = "%" . date('d/m/Y', strtotime($date_end)) . "%";
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
        $query = "SELECT COUNT(*) FROM pesquisa $strWhere";

        if ($this->_DB) {
            $result = $this->_DB->getFirstRowQuery($query, false, $params);
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
            $where[] = "(data >= ? OR data LIKE ?)";
            $params[] = $date_start;
            $params[] = "%" . date('d/m/Y', strtotime($date_start)) . "%";
        }
        if (!empty($date_end)) {
            $where[] = "(data <= ? OR data LIKE ?)";
            $params[] = $date_end;
            $params[] = "%" . date('d/m/Y', strtotime($date_end)) . "%";
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
        $query = "SELECT * FROM pesquisa $strWhere ORDER BY rowid DESC LIMIT $limit OFFSET $offset";

        if ($this->_DB) {
            $result = $this->_DB->fetchTable($query, true, $params);
            if (is_array($result)) return $result;
        }
        return array();
    }

    function getPesquisaStats($date_start = '', $date_end = '', $operador = '')
    {
        $where = array();
        $params = array();

        if (!empty($date_start)) {
            $where[] = "(data >= ? OR data LIKE ?)";
            $params[] = $date_start;
            $params[] = "%" . date('d/m/Y', strtotime($date_start)) . "%";
        }
        if (!empty($date_end)) {
            $where[] = "(data <= ? OR data LIKE ?)";
            $params[] = $date_end;
            $params[] = "%" . date('d/m/Y', strtotime($date_end)) . "%";
        }
        if (!empty($operador)) {
            $where[] = "(operador LIKE ? OR ramal LIKE ?)";
            $params[] = "%$operador%";
            $params[] = "%$operador%";
        }

        $strWhere = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

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
        if ($this->_DB) {
            $stats = $this->_DB->getFirstRowQuery($query, true, $params);
        }

        if (!$stats || empty($stats['total'])) {
            // Se filtrou mas não veio nada, tenta calcular total geral
            if (!empty($strWhere)) {
                $queryAll = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN UPPER(avaliacao) IN ('OTIMO', 'ÓTIMO', '5') THEN 1 ELSE 0 END) as otimo,
                    SUM(CASE WHEN UPPER(avaliacao) IN ('MUITO BOM', '4') THEN 1 ELSE 0 END) as muito_bom,
                    SUM(CASE WHEN UPPER(avaliacao) IN ('MEDIO', 'MÉDIO', '3') THEN 1 ELSE 0 END) as medio,
                    SUM(CASE WHEN UPPER(avaliacao) IN ('BOM', '2') THEN 1 ELSE 0 END) as bom,
                    SUM(CASE WHEN UPPER(avaliacao) IN ('RUIM', '1') THEN 1 ELSE 0 END) as ruim,
                    SUM(CASE WHEN UPPER(solucao) IN ('SIM', '1') THEN 1 ELSE 0 END) as resolvido_sim,
                    SUM(CASE WHEN UPPER(solucao) IN ('NAO', 'NÃO', '2') THEN 1 ELSE 0 END) as resolvido_nao
                    FROM pesquisa";
                $stats = $this->_DB->getFirstRowQuery($queryAll, true, array());
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
