# 🛡️ Relatório de Auditoria de Vulnerabilidades - Google Mantis Framework
**Projeto:** IPBX Issabel 6.0 (Prisma Telecom)  
**Data da Auditoria:** 04/09/2026  
**Metodologia:** Google Mantis (Threat Modeling, Sink Analysis, Adversarial Review & Triage)  
**Escopo Auditado:** `src/modules/`, `src/admin/`, `src/ramais/`, `src/agenda.php`, `scripts/`  

---

## 📊 Resumo Executivo da Triagem

| Severidade | Quantidade | Status de Risco |
| :--- | :---: | :--- |
| 🔴 **CRÍTICA (CVSS 9.0 - 10.0)** | **1** | RCE Remoto e Não-Autenticado |
| 🟠 **ALTA (CVSS 7.0 - 8.9)** | **3** | Exposição de Dados, Credenciais Hardcoded e Injeção de Comandos |
| 🟡 **MÉDIA (CVSS 4.0 - 6.9)** | **1** | Falta de Validação Estrita de Input (FQDN) |
| 🟢 **BAIXA / INFORMATIVA** | **2** | Hardening de Cabeçalhos e Permissões |

---

## 🔍 Detalhamento das Vulnerabilidades Identificadas

### 🔴 1. [CRÍTICA] RCE Remoto Não-Autenticado em `control_panel/libs/utilities.php`
- **Arquivo:** [`src/modules/control_panel/libs/utilities.php`](../../src/modules/control_panel/libs/utilities.php#L3-L11)
- **CVSS v3.1:** **9.8 (Critical)** - `CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H`
- **Causa Raiz:** O script lê o parâmetro `$_POST['cleanQueue']` sem autenticação e o concatena diretamente no `shell_exec()`:
  ```php
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      $cleanQueue = $_POST['cleanQueue'];
      if ($cleanQueue){
          shell_exec("asterisk -rx 'queue reset stats " . $cleanQueue . "'");
      }
  }
  ```
- **Vetor de Ataque:** Qualquer usuário com acesso à porta 80/443 (mesmo sem login no Issabel) pode enviar uma requisição POST:
  ```bash
  curl -X POST -d "cleanQueue=1'; id; #" https://ipbx/modules/control_panel/libs/utilities.php
  ```
  Isso executa comandos arbitrários no sistema com privilégios do usuário `asterisk`.
- **Correção Recomendada:**
  1. Validar rigorosamente se `$cleanQueue` é estritamente numérico ou alfanumérico: `preg_match('/^[0-9a-zA-Z_-]+$/', $cleanQueue)`.
  2. Aplicar `escapeshellarg()`.
  3. Exigir verificação de sessão de usuário autenticado do Issabel antes de processar qualquer POST.

---

### 🟠 2. [ALTA] Enumeração Não-Autenticada de Ramais e Rede em `src/ramais/ami_api.php`
- **Arquivo:** [`src/ramais/ami_api.php`](../../src/ramais/ami_api.php#L1-L10)
- **CVSS v3.1:** **7.5 (High)** - `CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:N/A:N`
- **Causa Raiz:** O script define `header('Access-Control-Allow-Origin: *');` e não possui validação de sessão ou token.
- **Vetor de Ataque:** Um invasor externo pode requisitar `https://ipbx/ramais/ami_api.php` e obter a lista completa de:
  - Todos os ramais cadastrados e nomes dos operadores;
  - Status de registro (OK, Unreachable, In use);
  - Endereços IP internos e portas SIP/PJSIP dos aparelhos;
  - Fabricantes, modelos e endereços MAC dos aparelhos.
- **Correção Recomendada:**
  - Incluir verificação de sessão ativa do Issabel (`$_SESSION['issabel_user']`) ou chave de API (Token).

---

### 🟠 3. [ALTA] Senha de Banco Hardcoded e Vazamento de Agenda em `src/agenda.php`
- **Arquivo:** [`src/agenda.php`](../../src/agenda.php#L8-L13)
- **CVSS v3.1:** **7.5 (High)** - `CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:N/A:N`
- **Causa Raiz:** Senha padrão do MySQL fixada no código (`$db_pass = "ls251289";`) e endpoint acessível publicamente sem autenticação, despejando a lista de usuários e presença em JSON.
- **Correção Recomendada:**
  - Remover senha em texto plano. Obter dinamicamente de `/etc/issabel.conf` e `/etc/amportal.conf`.
  - Exigir autenticação ou restringir o acesso apenas a origens locais/autorizadas.

---

### 🟠 4. [ALTA] Injeção de Comandos em Text-to-Wav (`paloSantoTexttoWav.class.php`)
- **Arquivo:** [`src/modules/text_to_wav/libs/paloSantoTexttoWav.class.php`](../../src/modules/text_to_wav/libs/paloSantoTexttoWav.class.php#L43-L44)
- **CVSS v3.1:** **8.8 (High)** - `CVSS:3.1/AV:N/AC:L/PR:L/UI:N/S:U/C:H/I:H/A:H`
- **Causa Raiz:** A variável `$message` é inserida entre aspas duplas no comando `pico2wave` sem `escapeshellarg()`:
  ```php
  $command = '/usr/bin/pico2wave -l '.$voice.' -w /tmp/'.$filename.' "'.$message.'"';
  $ret = system($command);
  ```
- **Vetor de Ataque:** Um usuário autenticado pode digitar um texto com aspas e comandos concatenados: `teste" && id && "` para executar código no servidor.
- **Correção Recomendada:**
  - Utilizar `escapeshellarg($message)` e `escapeshellarg($voice)`.

---

### 🟡 5. [MÉDIA] Falta de Sanitização Estrita de FQDN em `scripts/auto_dominio.sh`
- **Arquivo:** [`scripts/auto_dominio.sh`](../../scripts/auto_dominio.sh#L72)
- **CVSS v3.1:** **5.3 (Medium)** - `CVSS:3.1/AV:L/AC:L/PR:H/UI:N/S:U/C:L/I:L/A:L`
- **Causa Raiz:** O domínio recebido apenas remove espaços (`tr -d ' '`), sem validar regex de formato RFC de FQDN.
- **Correção Recomendada:**
  - Adicionar validação estrita com regex: `if [[ ! "$DOMINIO" =~ ^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$ ]]; then ...`.

---

## 🎯 Conclusão e Próximos Passos

O teste com o framework Mantis demonstrou que sua aplicação foi **altamente vantajosa**: conseguimos encontrar **1 vulnerabilidade Crítica (RCE)** e **3 Altas**, que estavam dormentes no repositório.

Podemos agora aplicar **correções cirúrgicas e seguras** em cada um desses 5 pontos, respeitando o **Princípio de Não-Regressão (Zero Quebra)** do projeto!
