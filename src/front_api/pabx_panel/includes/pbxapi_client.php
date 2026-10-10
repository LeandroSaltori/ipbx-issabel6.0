<?php
/**
 * IPbx Prisma - Cliente REST API (pbxapi PABX)
 * Responsável pela comunicação autenticada via JWT com o PABX IP.
 */

class PbxApiClient {
    private $baseUrl;
    private $username;
    private $password;
    private $db;
    private $token;

    public function __construct($db = null) {
        $this->db = $db;
        $this->loadSettings();
    }

    /**
     * Carrega as configurações de acesso à API do banco SQLite ou tenta fallbacks automáticos do PABX
     */
    public function loadSettings() {
        $this->baseUrl  = 'https://127.0.0.1/pbxapi';
        $this->username = 'admin';
        $this->password = '';

        if ($this->db) {
            try {
                $stmt = $this->db->query("SELECT key_name, value FROM settings WHERE key_name LIKE 'pbxapi_%'");
                if ($stmt) {
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        if ($row['key_name'] === 'pbxapi_url' && !empty($row['value'])) {
                            $this->baseUrl = rtrim($row['value'], '/');
                        }
                        if ($row['key_name'] === 'pbxapi_user' && !empty($row['value'])) {
                            $this->username = $row['value'];
                        }
                        if ($row['key_name'] === 'pbxapi_pass' && !empty($row['value'])) {
                            $this->password = $row['value'];
                        }
                        if ($row['key_name'] === 'pbxapi_token' && !empty($row['value'])) {
                            $this->token = $row['value'];
                        }
                    }
                }
            } catch (Exception $e) {}
        }

        // Tentar obter a senha do sistema PABX caso ainda não esteja salva no painel
        if (empty($this->password) && function_exists('getPABXSystemConfig')) {
            $sysConf = getPABXSystemConfig();
            if (!empty($sysConf['mysqlrootpwd'])) {
                $this->password = $sysConf['mysqlrootpwd'];
            }
        }
    }

    /**
     * Salva as configurações de acesso no banco SQLite
     */
    public function saveSettings($url, $user, $pass, $token = '') {
        if (!$this->db) return false;
        $url = rtrim($url, '/');
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS settings (key_name TEXT PRIMARY KEY, value TEXT)");
            $stmt = $this->db->prepare("INSERT OR REPLACE INTO settings (key_name, value) VALUES (:k, :v)");
            $stmt->execute([':k' => 'pbxapi_url', ':v' => $url]);
            $stmt->execute([':k' => 'pbxapi_user', ':v' => $user]);
            $stmt->execute([':k' => 'pbxapi_pass', ':v' => $pass]);
            if (!empty($token)) {
                $stmt->execute([':k' => 'pbxapi_token', ':v' => $token]);
            }
        } catch (Exception $e) {}
        
        $this->baseUrl  = $url;
        $this->username = $user;
        $this->password = $pass;
        if (!empty($token)) {
            $this->token = $token;
        }
        return true;
    }

    /**
     * Autentica no PABX via POST /pbxapi/authenticate e obtém o token JWT
     */
    public function authenticate() {
        $passwordsToTry = array_filter([
            $this->password,
            'palosanto',
            'issabel',
            'elastix',
            'admin',
            '123456'
        ]);

        $lastMessage = 'Usuário ou senha da API do PABX não configurados. Acesse Configurações > Conexão REST API PABX.';

        foreach ($passwordsToTry as $pass) {
            $authUrl = $this->baseUrl . '/authenticate';
            $postFields = http_build_query([
                'user'     => $this->username ?: 'admin',
                'password' => $pass
            ]);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $authUrl,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $postFields,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT        => 6
            ]);

            $response = curl_exec($ch);
            $errNo    = curl_errno($ch);
            $errMsg   = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errNo) {
                $lastMessage = "Erro de conexão cURL ($errNo): $errMsg";
                continue;
            }

            $json = json_decode($response, true);
            if ($httpCode === 200 && !empty($json['access_token'])) {
                $this->password = $pass;
                $this->token    = $json['access_token'];
                if ($this->db) {
                    try {
                        $this->saveSettings($this->baseUrl, $this->username ?: 'admin', $pass, $this->token);
                    } catch (Exception $e) {}
                }
                return [
                    'success'      => true,
                    'token'        => $this->token,
                    'expires_in'   => $json['expires_in'] ?? 86400,
                    'refresh_token'=> $json['refresh_token'] ?? ''
                ];
            }

            $lastMessage = $json['message'] ?? $json['error'] ?? "Código HTTP $httpCode - Verifique se a pbxapi está ativa e configurada em /etc/httpd/conf.d/issabel-htaccess.conf.";
        }

        return ['success' => false, 'message' => "Falha na autenticação da API PABX: $lastMessage"];
    }

    /**
     * Executa requisição HTTP autenticada
     */
    public function request($method, $endpoint, $data = null) {
        if (empty($this->token)) {
            $authRes = $this->authenticate();
            if (!$authRes['success']) {
                return $authRes;
            }
        }

        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($data !== null) {
            $jsonBody = is_string($data) ? $data : json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errNo    = curl_errno($ch);
        $errMsg   = curl_error($ch);
        curl_close($ch);

        if ($errNo) {
            if (function_exists('pabx_log')) {
                pabx_log('api_rest', 'ERROR', "Erro cURL #{$errNo} em {$method} /{$endpoint}: {$errMsg}", ['url' => $url]);
            }
            return ['success' => false, 'message' => "Erro ao conectar ($errNo): $errMsg"];
        }

        // Se o token expirou, re-autenticar uma vez
        if ($httpCode === 401) {
            if (function_exists('pabx_log')) {
                pabx_log('api_rest', 'WARNING', "Token JWT expirado (HTTP 401) em {$method} /{$endpoint}. Tentando re-autenticação.");
            }
            $reauth = $this->authenticate();
            if ($reauth['success']) {
                return $this->request($method, $endpoint, $data);
            }
            return ['success' => false, 'message' => 'Sessão expirada. Falha ao renovar Token JWT.'];
        }

        $json = json_decode($response, true);
        $isOk = ($httpCode >= 200 && $httpCode < 300);

        if (function_exists('pabx_log')) {
            $logLevel = $isOk ? 'INFO' : 'ERROR';
            pabx_log('api_rest', $logLevel, "{$method} /{$endpoint} -> HTTP {$httpCode}", [
                'http_code' => $httpCode,
                'endpoint'  => $endpoint
            ]);
        }

        return [
            'success'   => $isOk,
            'http_code' => $httpCode,
            'data'      => $json ?? $response
        ];
    }

    // ─── CLICK TO CALL VIA REST API ──────────────────────────────────────────
    public function originateCall($fromExt, $destNum) {
        $fromExtClean = preg_replace('/\D/', '', $fromExt);
        $destNumClean = preg_replace('/[^0-9*#]/', '', $destNum);
        if (empty($fromExtClean) || empty($destNumClean)) {
            return ['success' => false, 'message' => 'Ramal de origem ou destino inválidos.'];
        }

        $tech = 'PJSIP';
        if ($this->db) {
            try {
                $stmt = $this->db->prepare("SELECT tech FROM extensions_config WHERE extension = :e LIMIT 1");
                $stmt->execute([':e' => $fromExtClean]);
                $t = strtoupper($stmt->fetchColumn() ?: '');
                if ($t) $tech = $t;
            } catch (Exception $e) {}
        }

        $channel = ($tech === 'SIP') ? "SIP/$fromExtClean" : (($tech === 'PJSIP') ? "PJSIP/$fromExtClean" : "Local/$fromExtClean@from-internal");
        $endpoint = "manager/originate?channel=" . urlencode($channel) . "&exten=" . urlencode($destNumClean) . "&context=from-internal&priority=1";
        return $this->request('GET', $endpoint);
    }

    // ─── ENDPOINTS DE RAMAIS (/pbxapi/extensions) ──────────────────────────────
    public function getExtensions() {
        return $this->request('GET', 'extensions');
    }

    public function getExtension($ext) {
        return $this->request('GET', "extensions/$ext");
    }

    public function createExtension($extData) {
        return $this->request('POST', 'extensions', $extData);
    }

    public function updateExtension($ext, $extData) {
        return $this->request('PUT', "extensions/$ext", $extData);
    }

    public function deleteExtension($ext) {
        return $this->request('DELETE', "extensions/$ext");
    }

    // ─── ENDPOINTS DE FILAS (/pbxapi/queues) ──────────────────────────────────
    public function getQueues() {
        return $this->request('GET', 'queues');
    }

    public function getQueue($queue) {
        return $this->request('GET', "queues/$queue");
    }

    public function updateQueue($queue, $queueData) {
        return $this->request('PUT', "queues/$queue", $queueData);
    }

    // ─── ENDPOINTS DE GRUPOS DE CHAMADA (/pbxapi/ringgroups) ───────────────────
    public function getRingGroups() {
        return $this->request('GET', 'ringgroups');
    }

    // ─── ENDPOINTS DE BLACKLIST (/pbxapi/blacklist) ──────────────────────────
    public function getBlacklist() {
        return $this->request('GET', 'blacklist');
    }

    public function addToBlacklist($number, $description = '') {
        return $this->request('POST', 'blacklist', [
            'number'      => $number,
            'description' => $description
        ]);
    }

    public function deleteFromBlacklist($number) {
        return $this->request('DELETE', "blacklist/$number", '{}');
    }
}
