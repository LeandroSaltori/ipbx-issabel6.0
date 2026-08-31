# IPBX ISSABEL (PRISMA TELECOM) - REGRAS E DIRETRIZES ARQUITETURAIS

Este documento consolida as regras mandatórias de desenvolvimento e engenharia do projeto **ipbx-issabel6.0**.

---

## 1. PRINCÍPIO DE NÃO-REGRESSÃO (ZERO QUEBRA)
- **Edições Cirúrgicas:** NUNCA reescrever arquivos inteiros do zero quando for solicitada uma alteração pontual. Realizar alterações pontuais mantendo 100% da estrutura existente.
- **Preservação de Rotas e Bindings:** Preservar integralmente rotas, hooks, bindings Smarty/PHP (`$smarty->assign`, `.tpl`) e funções JavaScript já homologadas.
- **Preservação de Funções Auxiliares:** Não remover funções auxiliares ou scripts existentes sem ordem explícita do usuário.
- **Menu Modular:** Todas as opções `[1]` a `[30]`, `[A]` e `[0]` do `ipbx-menu.sh` devem permanecer 100% funcionais.

---

## 2. PADRÃO DE ARQUITETURA DE UI PARA ISSABEL/ELASTIX
- **Overflow Interno:** O Issabel renderiza o conteúdo dentro de containers com overflow interno ativo (`#neo-contentbox`, `.neo-contentbox`, `#content`).
- **Proibição de `position: fixed` Simples:** É proibido utilizar `position: fixed` diretamente em divs dentro dos módulos/tabelas.
- **Uso Obrigatório de HTML5 `<dialog>` Nativo (Top Layer):**
  - Qualquer modal, player ou popup DEVE usar obrigatoriamente a tag HTML5 `<dialog>` nativa (`dialog#modal-player-gravacao`).
  - Anexar à raiz (`document.body.appendChild(...)`) e abrir via `.showModal()`.
  - Fechar via `.close()`.
  - Estilização do backdrop via `dialog::backdrop` (`background: rgba(0, 0, 0, 0.65); backdrop-filter: blur(4px);`).

---

## 3. PADRÃO DE DESIGN E LAYOUT VALIDADO
- **Paleta Dark Homologada:** Preservar a paleta escura (`#1c1b2f`, `#252440`, `#0f172a`, gradientes violeta/azul).
- **Anti-Truncamento de Botões:** Botões com ícone/texto devem conter obrigatoriamente `white-space: nowrap !important; flex-shrink: 0 !important; width: auto !important; overflow: visible !important;`.
- **Experiência Premium:** Cards KPIs com gradientes, tipografia moderna (Segoe UI) e gráficos dinâmicos (Chart.js).

---

## 4. WORKFLOW DE EXECUÇÃO
- Antes de aplicar alterações em `.tpl`, `.php`, `.js` ou `.css`, apresentar o escopo isolado do que será alterado.
- Validar o impacto nos módulos dependentes (`cdrreport`, `monitoring`, `pesquisa`).

---

## 5. SNAPSHOTS, BACKUP E ROLLBACK SEGURO
- Snapshot obrigatório em `/var/backup/ipbx/backup_YYYY-MM-DD_HHMMSS` antes de qualquer alteração (`update.sh`, `install.sh`, `ipbx-menu.sh`).
- Restauração não-destrutiva no `rollback.sh`: nunca executar `rm -rf /var/www/html/modules`.

---

## 6. SEGURANÇA CIRÚRGICA E ZERO-DOWNTIME
- Hardening restrito a pastas estáticas de upload (`/recordings/`, `/themes/*/images/`).
- Preservação total de scripts PHP, Webhooks, WhatsApp e conexões AMI/AGI.
- Recargas suaves no Asterisk (`dialplan reload`, `logger reload`).
