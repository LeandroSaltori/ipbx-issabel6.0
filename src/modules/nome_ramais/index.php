<?php
// IPBX Prisma Telecom - Módulo de Gerenciamento e Alteração de Nome de Ramais
// Configurações do Banco de Dados
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'asterisk';

// Carrega automaticamente a senha do MySQL a partir do /etc/issabel.conf se existir
if (file_exists('/etc/issabel.conf')) {
    $issabel_conf = parse_ini_file('/etc/issabel.conf');
    if (isset($issabel_conf['mysqlrootpwd'])) {
        $db_pass = trim($issabel_conf['mysqlrootpwd']);
    }
}

try {
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) { 
    try {
        // Tentativa de fallback com senha de instalação padrão
        $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", 'root', 'ls251289');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e2) {
        die('Erro de Conexão com o Banco de Dados Asterisk: ' . $e2->getMessage()); 
    }
}

// Processamento AJAX (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $exten = filter_input(INPUT_POST, 'exten', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $newName = filter_input(INPUT_POST, 'new_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    if ($exten && $newName) {
        try {
            // 1. Atualiza tabela USERS
            $stmt1 = $pdo->prepare("UPDATE users SET name = :name WHERE extension = :exten");
            $stmt1->execute([':name' => $newName, ':exten' => $exten]);

            // 2. Atualiza tabela DEVICES
            $stmt2 = $pdo->prepare("UPDATE devices SET description = :name WHERE id = :exten");
            $stmt2->execute([':name' => $newName, ':exten' => $exten]);

            // 3. Aplica as alterações no Asterisk / Issabel
            @shell_exec('sudo /var/lib/asterisk/bin/retrieve_conf 2>&1');
            @shell_exec('sudo /usr/sbin/fwconsole reload 2>&1 || sudo asterisk -rx "core reload" 2>&1 || sudo asterisk -rx "module reload" 2>&1');

            echo json_encode(['status' => 'success', 'message' => 'Ramal ' . $exten . ' atualizado para "' . $newName . '" com sucesso!']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Erro ao salvar no banco: ' . $e->getMessage()]);
        }
        exit;
    }
    echo json_encode(['status' => 'error', 'message' => 'Dados inválidos inseridos.']);
    exit;
}

// Busca a lista de ramais
$stmt = $pdo->query("SELECT extension, name FROM users ORDER BY CAST(extension AS UNSIGNED) ASC");
$ramais = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalRamais = count($ramais);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prisma Telecom - Gerenciador de Ramais</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6d1a7c;
            --primary-hover: #561363;
            --bg-color: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --success: #10b981;
            --success-hover: #059669;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { background-color: var(--bg-color); color: var(--text-main); padding: 30px 15px; }

        .container { max-width: 950px; margin: 0 auto; }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header h1 { font-size: 1.5rem; color: var(--primary); font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .badge-count { background: #f1f5f9; border: 1px solid var(--border-color); color: var(--primary); font-size: 0.85rem; padding: 4px 14px; border-radius: 20px; font-weight: 600; }

        .search-box {
            position: relative;
            margin-bottom: 20px;
        }

        .search-box input {
            width: 100%;
            padding: 12px 16px 12px 42px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            font-size: 0.95rem;
            outline: none;
            transition: all 0.2s;
        }

        .search-box input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(109, 26, 124, 0.15);
        }

        .search-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
        }

        .card {
            background: var(--card-bg);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
            overflow: hidden;
        }

        table { width: 100%; border-collapse: collapse; text-align: left; }
        th { background: #f8fafc; color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; padding: 14px 20px; border-bottom: 1px solid var(--border-color); }
        td { padding: 14px 20px; border-bottom: 1px solid var(--border-color); font-size: 0.95rem; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover { background-color: #faf5ff; }

        .ramal-number { font-weight: 700; color: var(--primary); font-size: 1rem; }
        
        .input-name {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.9rem;
            outline: none;
            transition: border 0.2s;
        }

        .input-name:focus { border-color: var(--primary); }

        .btn-save {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 8px 18px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-width: 80px;
        }

        .btn-save:hover { background-color: var(--primary-hover); }
        .btn-save:disabled { opacity: 0.6; cursor: not-allowed; }

        /* Toast Alert */
        #toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 12px 20px;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            font-size: 0.9rem;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
            display: none;
            z-index: 1000;
        }
        #toast.success { background-color: var(--success); }
        #toast.error { background-color: #ef4444; }

        @media (max-width: 640px) {
            th:nth-child(2), td:nth-child(2) { display: none; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                Alterar Nomes dos Ramais
            </h1>
            <span class="badge-count">Total: <?= $totalRamais ?> Ramais</span>
        </div>

        <div class="search-box">
            <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="searchInput" placeholder="Pesquisar por ramal ou nome..." onkeyup="filtrarRamais()">
        </div>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Ramal</th>
                        <th>Nome Atual</th>
                        <th>Novo Nome</th>
                        <th style="width: 120px; text-align: center;">Ação</th>
                    </tr>
                </thead>
                <tbody id="ramaisTable">
                    <?php foreach ($ramais as $r): ?>
                    <tr id="row_<?= $r['extension'] ?>">
                        <td class="ramal-number"><?= htmlspecialchars($r['extension']) ?></td>
                        <td id="name_<?= $r['extension'] ?>"><?= htmlspecialchars($r['name']) ?></td>
                        <td>
                            <input type="text" class="input-name" id="in_<?= $r['extension'] ?>" placeholder="Digite o novo nome...">
                        </td>
                        <td style="text-align: center;">
                            <button class="btn-save" id="btn_<?= $r['extension'] ?>" onclick="salvar('<?= $r['extension'] ?>')">
                                Salvar
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="toast"></div>

    <script>
    function showToast(message, type = 'success') {
        const toast = document.getElementById('toast');
        toast.innerText = message;
        toast.className = type;
        toast.style.display = 'block';
        setTimeout(() => { toast.style.display = 'none'; }, 4000);
    }

    function filtrarRamais() {
        const input = document.getElementById('searchInput').value.toLowerCase();
        const rows = document.querySelectorAll('#ramaisTable tr');

        rows.forEach(row => {
            const exten = row.cells[0].innerText.toLowerCase();
            const name = row.cells[1].innerText.toLowerCase();
            if (exten.includes(input) || name.includes(input)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    function salvar(exten) {
        const nameInput = document.getElementById('in_' + exten);
        const name = nameInput.value.trim();
        const btn = document.getElementById('btn_' + exten);

        if(!name) {
            showToast('Por favor, digite um novo nome!', 'error');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = 'Salvando...';

        const fd = new URLSearchParams();
        fd.append('exten', exten);
        fd.append('new_name', name);

        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if(data.status === 'success') {
                showToast(data.message, 'success');
                document.getElementById('name_' + exten).innerText = name;
                nameInput.value = '';
            } else {
                showToast(data.message || 'Erro ao atualizar.', 'error');
            }
        })
        .catch(() => {
            showToast('Erro de conexão ou falha nas permissões sudo.', 'error');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = 'Salvar';
        });
    }
    </script>
</body>
</html>
