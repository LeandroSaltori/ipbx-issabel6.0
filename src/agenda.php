<?php
header('Content-Type: application/json; charset=utf-8');

// Senha definida pelo usuário
$db_pass = "ls251289";

// Conexão com o banco de dados com fallback seguro
$conn = @new mysqli("localhost", "root", $db_pass, "asterisk");

if ($conn->connect_error) {
    // Tenta ler senha do /etc/issabel.conf
    if (file_exists('/etc/issabel.conf')) {
        $issabel_conf = file_get_contents('/etc/issabel.conf');
        if (preg_match('/mysqlrootpwd=(.*)/', $issabel_conf, $matches)) {
            $auto_pass = trim($matches[1]);
            $conn = @new mysqli("localhost", "root", $auto_pass, "asterisk");
        }
    }
}

if ($conn->connect_error) {
    // Tenta sem senha
    $conn = @new mysqli("localhost", "root", "", "asterisk");
}

if ($conn->connect_error) {
    die(json_encode(["error" => "Erro na conexão com o banco de dados: " . $conn->connect_error]));
}

// 1. CONSULTA DE STATUS COM FALLBACK ROBUSTO
$presence = [];
$tables = ['sip_peers', 'ast_sip_peers', 'ps_endpoints'];

foreach ($tables as $table) {
    $check_table = $conn->query("SHOW TABLES LIKE '$table'");
    if ($check_table && $check_table->num_rows > 0) {
        $sql = "SELECT 
                    id as extension, 
                    CASE 
                        WHEN status IN ('OK', '5', 'Reachable') THEN 1 
                        ELSE 0 
                    END as online_status
                FROM $table 
                WHERE id REGEXP '^[0-9]{2,4}$'";
        
        $result = $conn->query($sql);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $presence[$row['extension']] = $row['online_status'];
            }
            break;
        }
    }
}

// 2. CONSULTA DE RAMAIS COM PRESENÇA
$items = [];
$result = $conn->query("
    SELECT extension AS number, name 
    FROM users 
    WHERE extension REGEXP '^[0-9]{2,4}$'
    ORDER BY extension ASC
");

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $is_online = isset($presence[$row['number']]) ? $presence[$row['number']] : 0;
        
        $items[] = [
            "number" => $row["number"],
            "name" => $row["name"] ?: "Ramal " . $row["number"],
            "phone" => $row["number"],
            "presence" => $is_online,
            "starred" => 1,
            "info" => "Ramal do sistema"
        ];
    }
}

// 3. SAÍDA JSON
try {
    echo json_encode([
        "refresh" => 300,
        "items" => $items
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Exception $e) {
    die(json_encode([
        "error" => "Erro ao gerar JSON",
        "details" => $e->getMessage()
    ]));
}

$conn->close();
?>
