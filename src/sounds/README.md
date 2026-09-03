# 🎵 Áudios do Sistema e Músicas de Espera (`src/sounds/`)

Este diretório contém os arquivos de áudio gravados e processados para a central telefônica Asterisk.

---

## 📌 Opção do Menu Interativo: **[19] Música de Espera (MOH)**

Ao selecionar a opção **`[19]`** no menu **`ipbx-update`** (`ipbx-menu.sh`), os áudios de espera são instalados em `/var/lib/asterisk/moh/`.

### 📂 Estrutura de Pastas:
- **`moh/`**: Músicas de espera corporativas em alta definição e normalizadas para o padrão de telefonia (mono, 8kHz/16kHz PCM 16-bit WAV / SLN / MP3).
- **`custom/`**: Áudios de apoio para URAs de atendimento, menus interativos, mensagens de fora de horário e pesquisa de satisfação.

### 🔒 Permissões:
- Usuário/Grupo: `asterisk:asterisk`
- Permissões: `644`
