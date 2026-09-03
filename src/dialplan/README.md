# 📞 Dialplans e Configurações do Asterisk (`src/dialplan/`)

Este diretório contém os arquivos de dialplan customizados e configurações de recursos avançados para o Asterisk (`/etc/asterisk/`).

---

## 📋 Mapeamento de Dialplans x Opções do Menu (`ipbx-update`)

| Arquivo | Opção no Menu | Descrição do Recurso |
| :--- | :---: | :--- |
| **`chanspy.conf`** | **[16]** | **ChanSpy (Escuta e Sussurro):** Dialplan para supervisão de chamadas em tempo real (escuta silenciosa, sussurro com o operador e intrusão na conferência). |
| **`textmessages.conf`** | **[17]** | **Mensagens de Texto (PJSIP MESSAGE):** Roteamento de mensagens de texto SIP/PJSIP instantâneas entre ramais e softphones. |
| **`features_general_custom.conf`** | **[22]** | **Features Asterisk:** Ajusta tempos limites de transferência (`transferdigittimeout=7`, `atxfernoanswertimeout=30`) e ativa BIP de confirmação (`courtesytone=beep`, `xfersound=beep`). |
| **`pjsip_custom.conf`** | **[23]** | **PJSIP Custom:** Ajustes finos de transporte, timers de sessão e cabeçalho `User-Agent`. |

---

## 📂 Destino no Servidor:
- Arquivos instalados em `/etc/asterisk/` e incluídos via `#include` em `extensions_custom.conf` e `features_applicationmap_custom.conf`.

### 🔄 Recarga do Asterisk:
Após qualquer alteração:
```bash
asterisk -rx "dialplan reload"
asterisk -rx "features reload"
```
