# 🌐 Dicionários e Traduções do Sistema (`src/lang/`)

Este diretório contém os arquivos de localização de idiomas para a interface gráfica do Issabel PBX, com foco principal na tradução e aprimoramento em **Português do Brasil** (`br.lang` e `pt-br.lang`).

---

## 📌 Opção do Menu Interativo: **[4] Traduções (lang)**

Ao selecionar a opção **`[4]`** no menu **`ipbx-update`** (`ipbx-menu.sh`), os arquivos de idioma são copiados para a pasta central de idiomas do servidor web.

### 🎯 O que este módulo faz:
1. **Tradução Completa dos Termos:** Corrige expressões em espanhol/inglês que ficavam sem tradução na versão original do Issabel (termos de CDR, relatórios de filas, gravações e administração de ramais).
2. **Compatibilidade com Módulos:** Fornece as strings corretas para os módulos do framework (`/var/www/html/lang/`).

### 📂 Destino no Servidor:
- `/var/www/html/lang/`
