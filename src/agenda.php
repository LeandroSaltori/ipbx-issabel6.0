<?php
header('Content-Type: application/json; charset=utf-8');

// Desativa exibição direta de erros para não quebrar o formato JSON
error_reporting(0);
ini_set('display_errors', 0);

// Senha definida
$db_pass = "ls251289";

// Conexão MySQL com o banco asterisk
$conn = @new mysqli("localhost", "root", $db_pass, "asterisk");

if ($conn->connect_error) {
    if (file_exists('/etc/issabel.conf')) {
        $issabel_conf = file_get_contents('/etc/issabel.conf');
        if (preg_match('/mysqlrootpwd=(.*)/', $issabel_conf, $matches)) {
            $auto_pass = trim($matches[1]);
            $conn = @new mysqli("localhost", "root", $auto_pass, "asterisk");
        }
    }
}

if ($conn->connect_error) {
    $conn = @new mysqli("localhost", "root", "", "asterisk");
}

if ($conn->connect_error) {
    die(json_encode(["error" => "Erro na conexão MySQL: " . $conn->connect_error]));
}

$conn->set_charset("utf8");
$items = array();

// 1. CONSULTA DE STATUS DE PRESENÇA (ONLINE/OFFLINE)
$presence = array();
$result_presence = $conn->query("
    SELECT name as extension, 
           CASE 
               WHEN status = 'OK' OR status = 'Reachable' THEN 1 
               ELSE 0 
           END as online_status
    FROM sip_peers
    WHERE name REGEXP '^[0-9]{2,4}$'
");

if ($result_presence) {
    while ($row = $result_presence->fetch_assoc()) {
        $presence[$row['extension']] = (int)$row['online_status'];
    }
}

// 2. RAMAIS INTERNOS DO PBX
$result_ramais = $conn->query("
    SELECT extension AS number, name 
    FROM users 
    WHERE extension REGEXP '^[0-9]{2,4}$'
    ORDER BY CAST(extension AS UNSIGNED) ASC, name ASC
");

if ($result_ramais && $result_ramais->num_rows > 0) {
    while ($row = $result_ramais->fetch_assoc()) {
        $name_parts = explode(' ', trim($row['name']), 2);
        $firstname = isset($name_parts[0]) ? $name_parts[0] : '';
        $lastname = isset($name_parts[1]) ? $name_parts[1] : '';
        
        $status = isset($presence[$row['number']]) ? $presence[$row['number']] : 1;
        
        $items[] = array(
            "number"    => (string)$row['number'],
            "name"      => $row['name'] ? $row['name'] : "Ramal " . $row['number'],
            "firstname" => $firstname,
            "lastname"  => $lastname,
            "phone"     => (string)$row['number'],
            "mobile"    => "",
            "email"     => "",
            "address"   => "",
            "city"      => "",
            "state"     => "",
            "zip"       => "",
            "comment"   => "Ramal interno",
            "presence"  => $status,
            "starred"   => 1,
            "info"      => ($status == 1) ? "Ramal online" : "Ramal offline"
        );
    }
}

// 3. CONTATOS EXTERNOS (SQLite address_book.db)
$sqlite_path = '/var/www/db/address_book.db';
if (file_exists($sqlite_path) && class_exists('SQLite3')) {
    try {
        $sqlite = new SQLite3($sqlite_path, SQLITE3_OPEN_READONLY);
        $res_contacts = @$sqlite->query("
            SELECT name, last_name, telefono AS number, cell_phone AS mobile, 
                   email, address, city, province AS state, notes AS comment,
                   company, company_contact, contact_rol, department
            FROM contact 
            WHERE directory = 'external' AND (telefono != '' OR cell_phone != '')
            ORDER BY name ASC
        ");
        
        if ($res_contacts) {
            while ($row = $res_contacts->fetchArray(SQLITE3_ASSOC)) {
                $comment = "";
                if (!empty($row['company'])) $comment .= "Empresa: " . $row['company'] . "\n";
                if (!empty($row['company_contact'])) $comment .= "Contato: " . $row['company_contact'] . "\n";
                if (!empty($row['contact_rol'])) $comment .= "Cargo: " . $row['contact_rol'] . "\n";
                if (!empty($row['department'])) $comment .= "Depto: " . $row['department'];
                if (!empty($row['comment'])) $comment .= "\n" . $row['comment'];
                
                $phone_num = !empty($row['number']) ? $row['number'] : $row['mobile'];
                
                $items[] = array(
                    "number"    => (string)$phone_num,
                    "name"      => trim($row['name'] . ' ' . $row['last_name']),
                    "firstname" => $row['name'],
                    "lastname"  => $row['last_name'],
                    "phone"     => (string)$row['number'],
                    "mobile"    => (string)$row['mobile'],
                    "email"     => (string)$row['email'],
                    "address"   => (string)$row['address'],
                    "city"      => (string)$row['city'],
                    "state"     => (string)$row['state'],
                    "zip"       => "",
                    "comment"   => trim($comment),
                    "presence"  => 0,
                    "starred"   => 0,
                    "info"      => "Contato externo"
                );
            }
            $sqlite->close();
        }
    } catch (Exception $e) {
        // Silencia erro do sqlite
    }
}

// 4. SAÍDA JSON FORMATADA (PADRÃO MICROSIP)
echo json_encode(array(
    "refresh" => 60,
    "items"   => $items
), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

$conn->close();
?>
