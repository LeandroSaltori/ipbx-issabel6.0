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
  $Id: paloSantoPesquisa.class.php,v 2.3 2026-08-17 Prisma Telecom $ */

class paloSantoPesquisa {
    var $_DB;
    var $pdo;
    var $errMsg;

    function __construct(&$pDB = null)
    {
        $this->connectDatabase();
    }

    function paloSantoPesquisa(&$pDB = null)
    {
        $this->__construct($pDB);
    }

    function formatDateForSql($d)
    {
        if (empty($d)) return '';
        $d = trim($d);
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $d, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return $d;
        }
        $ts = strtotime($d);
        return $ts ? date('Y-m-d', $ts) : '';
    }

    function connectDatabase()
    {
        $passwords = array();

        if (file_exists('/etc/issabel.conf')) {
            $lines = @file('/etc/issabel.conf');
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $parts = explode('=', $line, 2);
                    if (count($parts) == 2) {
                        $key = strtolower(trim($parts[0]));
                        $val = trim(trim($parts[1]), " '\"\r\n");
                        if (in_array($key, array('mysqlrootpwd', 'mysqlrootpass', 'amiadminpwd'))) {
                            if ($val !== '') $passwords[] = $val;
                        }
                    }
                }
            }
        }

        if (file_exists('/etc/amportal.conf')) {
            $lines = @file('/etc/amportal.conf');
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $parts = explode('=', $line, 2);
                    if (count($parts) == 2) {
                        $key = strtolower(trim($parts[0]));
                        $val = trim(trim($parts[1]), " '\"\r\n");
                        if (in_array($key, array('ampdbpass', 'cdrdbpass'))) {
                            if ($val !== '') $passwords[] = $val;
                        }
                    }
                }
            }
        }

        $passwords[] = '';
        $passwords[] = 'asterisk';
        $passwords[] = 'asteriskuser';
        $passwords = array_unique($passwords);

        $users = array('root', 'asteriskuser', 'asterisk');

        foreach ($users as $u) {
            foreach ($passwords as $p) {
                try {
                    $pdo = new PDO("mysql:host=localhost;dbname=asteriskcdrdb;charset=utf8", $u, $p, array(
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                    ));
                    $stmt = $pdo->query("SELECT COUNT(*) FROM pesquisa");
                    if ($stmt !== false) {
                        $this->pdo = $pdo;
                        return;
                    }
                } catch (Exception $e) {}
            }
        }

        try {
            $this->pdo = new PDO("sqlite:/var/www/db/pesquisa.db", null, null, array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ));
        } catch (Exception $e) {}
    }

    function findRecordingForCall($telefone, $data, $hora, $operador)
    {
        if (!$this->pdo) return '';

        $dt = trim($data) . ' ' . trim($hora);
        $ts = strtotime($dt);

        if ($ts) {
            $start = date('Y-m-d H:i:s', $ts - 1200); // 20 min antes
            $end   = date('Y-m-d H:i:s', $ts + 1200); // 20 min depois

            $telClean = preg_replace('/[^0-9]/', '', $telefone);
            if (strlen($telClean) > 4) {
                $telClean = substr($telClean, -8);
            }

            try {
                $sql = "SELECT recordingfile FROM cdr 
                        WHERE calldate BETWEEN ? AND ? 
                        AND (src LIKE ? OR dst LIKE ? OR channel LIKE ? OR dstchannel LIKE ?) 
                        AND recordingfile IS NOT NULL AND recordingfile != '' 
                        ORDER BY ABS(TIMESTAMPDIFF(SECOND, calldate, ?)) ASC LIMIT 1";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(array($start, $end, "%$telClean%", "%$telClean%", "%$telClean%", "%$telClean%", $dt));
                $row = $stmt->fetch();
                if (!empty($row['recordingfile'])) {
                    return $row['recordingfile'];
                }
            } catch (Exception $e) {}
        }

        // Fallback no sistema de arquivos do monitor do Asterisk
        if (!empty($data) && !empty($telefone)) {
            $yearMonthDay = str_replace('-', '/', $data);
            $telClean = preg_replace('/[^0-9]/', '', $telefone);
            if (strlen($telClean) > 4) $telClean = substr($telClean, -8);

            $dir = "/var/spool/asterisk/monitor/" . $yearMonthDay;
            if (is_dir($dir)) {
                $files = glob("$dir/*$telClean*");
                if (!empty($files)) {
                    return basename($files[0]);
                }
            }
        }

        return '';
    }

    function getNumPesquisa($filter_field = '', $filter_value = '', $date_start = '', $date_end = '', $operador = '', $avaliacao = '', $solucao = '')
    {
        if (!$this->pdo) return 0;

        $where = array();
        $params = array();

        $dsSql = $this->formatDateForSql($date_start);
        $deSql = $this->formatDateForSql($date_end);

        if (!empty($filter_field) && !empty($filter_value)) {
            $where[] = "$filter_field LIKE ?";
            $params[] = "%$filter_value%";
        }
        if (!empty($dsSql)) {
            $where[] = "data >= ?";
            $params[] = $dsSql;
        }
        if (!empty($deSql)) {
            $where[] = "data <= ?";
            $params[] = $deSql;
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
        $sql = "SELECT COUNT(*) FROM pesquisa $strWhere";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    function getPesquisa($limit, $offset, $filter_field = '', $filter_value = '', $date_start = '', $date_end = '', $operador = '', $avaliacao = '', $solucao = '')
    {
        if (!$this->pdo) return array();

        $where = array();
        $params = array();

        $dsSql = $this->formatDateForSql($date_start);
        $deSql = $this->formatDateForSql($date_end);

        if (!empty($filter_field) && !empty($filter_value)) {
            $where[] = "$filter_field LIKE ?";
            $params[] = "%$filter_value%";
        }
        if (!empty($dsSql)) {
            $where[] = "data >= ?";
            $params[] = $dsSql;
        }
        if (!empty($deSql)) {
            $where[] = "data <= ?";
            $params[] = $deSql;
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
        $sql = "SELECT * FROM pesquisa $strWhere ORDER BY data DESC, hora DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : array();
        } catch (Exception $e) {
            return array();
        }
    }

    function getPesquisaStats($date_start = '', $date_end = '', $operador = '')
    {
        if (!$this->pdo) {
            return array(
                'total' => 0, 'otimo' => 0, 'muito_bom' => 0, 'medio' => 0, 'bom' => 0, 'ruim' => 0,
                'resolvido_sim' => 0, 'resolvido_nao' => 0, 'media_estrelas' => 0,
                'taxa_resolucao' => 0, 'taxa_satisfacao' => 0
            );
        }

        $where = array();
        $params = array();

        $dsSql = $this->formatDateForSql($date_start);
        $deSql = $this->formatDateForSql($date_end);

        if (!empty($dsSql)) {
            $where[] = "data >= ?";
            $params[] = $dsSql;
        }
        if (!empty($deSql)) {
            $where[] = "data <= ?";
            $params[] = $deSql;
        }
        if (!empty($operador)) {
            $where[] = "operador LIKE ?";
            $params[] = "%$operador%";
        }

        $strWhere = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

        $sql = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN UPPER(avaliacao) IN ('EXCELENTE', 'OTIMO', 'ÓTIMO', '5') THEN 1 ELSE 0 END) as otimo,
            SUM(CASE WHEN UPPER(avaliacao) IN ('MUITO BOM', '4') THEN 1 ELSE 0 END) as muito_bom,
            SUM(CASE WHEN UPPER(avaliacao) IN ('BOM', 'MEDIO', 'MÉDIO', 'REGULAR', '3') THEN 1 ELSE 0 END) as medio,
            SUM(CASE WHEN UPPER(avaliacao) IN ('RUIM', '2') THEN 1 ELSE 0 END) as bom,
            SUM(CASE WHEN UPPER(avaliacao) IN ('PESSIMO', 'PÉSSIMO', '1') THEN 1 ELSE 0 END) as ruim,
            SUM(CASE WHEN UPPER(solucao) IN ('SIM', '1') THEN 1 ELSE 0 END) as resolvido_sim,
            SUM(CASE WHEN UPPER(solucao) IN ('NAO', 'NÃO', '2') THEN 1 ELSE 0 END) as resolvido_nao
            FROM pesquisa $strWhere";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $stats = $stmt->fetch();
        } catch (Exception $e) {
            $stats = false;
        }

        if (!$stats || empty($stats['total'])) {
            try {
                $stmtAll = $this->pdo->query("SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN UPPER(avaliacao) IN ('EXCELENTE', 'OTIMO', 'ÓTIMO', '5') THEN 1 ELSE 0 END) as otimo,
                    SUM(CASE WHEN UPPER(avaliacao) IN ('MUITO BOM', '4') THEN 1 ELSE 0 END) as muito_bom,
                    SUM(CASE WHEN UPPER(avaliacao) IN ('BOM', 'MEDIO', 'MÉDIO', 'REGULAR', '3') THEN 1 ELSE 0 END) as medio,
                    SUM(CASE WHEN UPPER(avaliacao) IN ('RUIM', '2') THEN 1 ELSE 0 END) as bom,
                    SUM(CASE WHEN UPPER(avaliacao) IN ('PESSIMO', 'PÉSSIMO', '1') THEN 1 ELSE 0 END) as ruim,
                    SUM(CASE WHEN UPPER(solucao) IN ('SIM', '1') THEN 1 ELSE 0 END) as resolvido_sim,
                    SUM(CASE WHEN UPPER(solucao) IN ('NAO', 'NÃO', '2') THEN 1 ELSE 0 END) as resolvido_nao
                    FROM pesquisa");
                $stats = $stmtAll->fetch();
            } catch (Exception $e) {}
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
        if (!$this->pdo) return null;
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM pesquisa WHERE id=?");
            $stmt->execute(array($id));
            return $stmt->fetch();
        } catch (Exception $e) {
            return null;
        }
    }
}
