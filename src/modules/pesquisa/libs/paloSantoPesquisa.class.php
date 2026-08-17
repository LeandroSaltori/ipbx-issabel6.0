<?php
  /* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version {ISSBEL_VERSION}                                               |
  | http://www.issabel.org                                               |
  +----------------------------------------------------------------------+
  | Copyright (c) 2017 Issabel Foundation                                |
  | Copyright (c) 2006 Palosanto Solutions S. A.                         |
  +----------------------------------------------------------------------+
  | The contents of this file are subject to the General Public License  |
  | (GPL) Version 2 (the "License"); you may not use this file except in |
  | compliance with the License. You may obtain a copy of the License at |
  | http://www.opensource.org/licenses/gpl-license.php                   |
  |                                                                      |
  | Software distributed under the License is distributed on an "AS IS"  |
  | basis, WITHOUT WARRANTY OF ANY KIND, either express or implied. See  |
  | the License for the specific language governing rights and           |
  | limitations under the License.                                       |
  +----------------------------------------------------------------------+
  | The Initial Developer of the Original Code is PaloSanto Solutions    |
  +----------------------------------------------------------------------+
  $Id: paloSantoPesquisa.class.php,v 1.1 2025-07-12 11:07:03 Prisma suporte@prismatelecom.com Exp $ */
class paloSantoPesquisa{
    var $_DB;
    var $errMsg;

    function paloSantoPesquisa(&$pDB)
    {
        // Se recibe como parámetro una referencia a una conexión paloDB
        if (is_object($pDB)) {
            $this->_DB =& $pDB;
            $this->errMsg = $this->_DB->errMsg;
        } else {
            $dsn = (string)$pDB;
            $this->_DB = new paloDB($dsn);

            if (!$this->_DB->connStatus) {
                $this->errMsg = $this->_DB->errMsg;
                // debo llenar alguna variable de error
            } else {
                // debo llenar alguna variable de error
            }
        }
    }

    function getNumPesquisa($date_start = null, $date_end = null, $operador = null, $avaliacao = null, $solucao = null)
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

        $result = $this->_DB->getFirstRowQuery($query, false, $params);
        if ($result === false) {
            $this->errMsg = $this->_DB->errMsg;
            return 0;
        }
        return (int)$result[0];
    }

    function getPesquisa($limit, $offset, $date_start = null, $date_end = null, $operador = null, $avaliacao = null, $solucao = null)
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
        $query = "SELECT * FROM pesquisa $strWhere ORDER BY rowid DESC LIMIT $limit OFFSET $offset";

        $result = $this->_DB->fetchTable($query, true, $params);
        if ($result === false) {
            $this->errMsg = $this->_DB->errMsg;
            return array();
        }
        return $result;
    }

    function getPesquisaStats($date_start = null, $date_end = null, $operador = null)
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
            $where[] = "operador LIKE ?";
            $params[] = "%$operador%";
        }

        $strWhere = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

        // Total e contagem de notas
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

        $stats = $this->_DB->getFirstRowQuery($query, true, $params);
        if (!$stats || empty($stats['total'])) {
            return array(
                'total' => 0,
                'otimo' => 0,
                'muito_bom' => 0,
                'medio' => 0,
                'bom' => 0,
                'ruim' => 0,
                'resolvido_sim' => 0,
                'resolvido_nao' => 0,
                'media_estrelas' => 0,
                'taxa_resolucao' => 0,
                'taxa_satisfacao' => 0,
                'operadores' => array()
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

        // Peso das notas: Ótimo=5, Muito Bom=4, Médio=3, Bom=2, Ruim=1
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
