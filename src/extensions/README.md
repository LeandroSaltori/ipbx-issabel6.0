# 🖱️ Extensão Click-to-Dial para Navegadores (`src/extensions/`)

Este diretório armazena o código-fonte da extensão de navegador (Google Chrome, Microsoft Edge, Brave) para discagem rápida com um clique (**Click-to-Dial**).

---

## 📌 Opção do Menu Interativo: **[8] Click-to-Dial**

Ao selecionar a opção **`[8]`** no menu **`ipbx-update`** (`ipbx-menu.sh`), os arquivos de integração web e a API de discagem (`/var/www/html/call.php` ou `/var/www/html/modules/clicktodial/`) são configurados.

### 🎯 Como Funciona:
1. A extensão identifica números de telefone em qualquer página web (CRM, ERP, WhatsApp Web, planilhas).
2. Ao clicar no número (ou no ícone do telefone ao lado), a extensão envia um comando via HTTP/AMI para o PBX.
3. O aparelho ou webphone do operador toca primeiro; assim que o operador atende, o Asterisk disca imediatamente para o número clicado.

### 📂 Conteúdo:
- `chrome-click-to-dial/`: Manifesto Manifest V3, scripts de injeção de conteúdo (content scripts), popup de configuração de IP/ramal e ícones.
