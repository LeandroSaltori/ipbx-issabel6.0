# 🎨 Tema Visual e Identidade da Interface (`src/themes/`)

Este diretório contém os temas visuais customizados para o Issabel PBX, com destaque para o **Prisma v5** (`prisma_v5/`).

---

## 📌 Opção do Menu Interativo: **[2] Tema e Favicon**

Ao selecionar a opção **`[2]`** no menu **`ipbx-update`** (`ipbx-menu.sh`), este tema e o `favicon.ico` são instalados e definidos como padrão no banco de dados SQLite (`/var/www/db/settings.db`).

### 🎯 Características do Tema Prisma v5:
1. **Design Moderno e Dark Mode:** Paleta escura elegante (`#1c1b2f`, `#252440`, `#0f172a`) com acentos em violeta/azul (`#7c3aed` / `#6366f1`) e acabamento em glassmorphism.
2. **Tipografia Premium:** Fontes modernas com alto contraste e legibilidade para operadores de Call Center e administradores de telecom.
3. **Cards e Tabelas Responsivas:** Tabelas com zebra striping suave, badges coloridos de status de chamadas (ANSWERED, NO ANSWER, BUSY, FAILED) e cards executivos de métricas.
4. **Proteção de Viewport e Top Layer:** Compatível com os padrões de modais em `<dialog>` nativo na Top Layer e sem cortes de texto ou botões (`white-space: nowrap !important;`).

### 📂 Destino no Servidor:
- `/var/www/html/themes/prisma_v5/`
- `/var/www/html/favicon.ico`

### ⚙️ Ativação Automática no Banco:
```sql
sqlite3 /var/www/db/settings.db "UPDATE settings SET value='prisma_v5' WHERE key='theme';"
```
