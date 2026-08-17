<?php
header('Content-Type: application/json; charset=utf-8');

// Conexão com o banco de dados
$conn = new mysqli("localhost", "root", "ls251289", "asterisk");
if ($conn->connect_error) {
    die(json_encode(["error" => "Erro na conexão: " . $conn->connect_error]));
}

// 1. CONSULTA DE STATUS COM FALLBACK ROBUSTO
$presence = [];
$tables = ['sip_peers', 'ast_sip_peers', 'ps_endpoints']; // Tabelas possíveis

foreach ($tables as $table) {
    $check_table = $conn->query("SHOW TABLES LIKE '$table'");
    if ($check_table->num_rows > 0) {
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
            break; // Usa a primeira tabela válida encontrada
        }
    }
}

// 2. CONSULTA DE RAMAIS COM PRESENÇA GARANTIDA
$items = [];
$result = $conn->query("
    SELECT extension AS number, name 
    FROM users 
    WHERE extension REGEXP '^[0-9]{2,4}$'
    ORDER BY extension ASC
");

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // Garante presença = 1 para ramais ativos
        $is_online = isset($presence[$row['number']]) ? $presence[$row['number']] : 0;
        
        // Força presença = 1 para teste (remova depois de confirmar)
        $is_online = 1; // REMOVA ESTA LINHA DEPOIS DE TESTAR
        
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

// 3. SAÍDA COM CONTROLE DE ERROS
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