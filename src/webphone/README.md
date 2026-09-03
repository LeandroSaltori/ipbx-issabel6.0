# 📞 Webphone WebRTC Integrado (`src/webphone/`)

Este diretório contém a aplicação web do **Webphone WebRTC**, permitindo que qualquer operador utilize um ramal diretamente pelo navegador sem necessidade de instalar aplicativos externos.

---

## 📌 Opção do Menu Interativo: **[7] Webphone WebRTC**

Ao selecionar a opção **`[7]`** no menu **`ipbx-update`** (`ipbx-menu.sh`), os arquivos são instalados em `/var/www/html/webphone/` e as configurações de transporte WSS do Asterisk são ativadas.

### 🎯 Funcionalidades:
1. **Comunicação WebRTC Pura:** Conexão criptografada via WebSocket Seguro (WSS na porta `8089` do Asterisk) e DTLS-SRTP.
2. **Design Responsivo e Temas:** Suporte a tema escuro (`phone.dark.css`) e claro (`phone.light.css`).
3. **PWA e Service Worker:** Arquivos `manifest.json` e `sw.js` para instalação como aplicativo no Chrome/Edge.
4. **Click-to-Dial Integrado:** Arquivo `click-to-dial.html` e `popup.html` para integração com discadores externos.

### 📂 Destino no Servidor:
- `/var/www/html/webphone/`

### 🔑 Requisito de Certificado SSL:
O WebRTC exige conexão HTTPS válida no navegador. Utilize a opção **`[26]`** do menu (`scripts/auto_dominio.sh`) para gerar um certificado Let's Encrypt e sincronizar as chaves com o Asterisk.
