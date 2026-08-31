# IPBX ISSABEL (PRISMA TELECOM) - REGRAS E DIRETRIZES ARQUITETURAIS

Este documento estabelece as regras e padrões arquiteturais obrigatórios para o desenvolvimento e manutenção do projeto **ipbx-issabel6.0**.

---

## 1. PRINCÍPIO DE NÃO-REGRESSÃO (ZERO QUEBRA)
1. **Edições Cirúrgicas e Pontuais:**
   - **NUNCA reescrever arquivos inteiros do zero** quando for solicitada uma alteração pontual. Realizar alterações cirúrgicas mantendo 100% da estrutura existente.
2. **Preservação de Rotas, Hooks e Bindings:**
   - Preservar integralmente rotas, hooks, bindings Smarty/PHP (`$smarty->assign(...)`, `.tpl`), conexões de banco de dados e funções JavaScript já homologadas.
3. **Preservação de Scripts e Funções Auxiliares:**
   - É estritamente proibido remover funções auxiliares, scripts utilitários ou dependências existentes sem ordem explícita do usuário.
4. **Integridade do Menu Modular (`ipbx-menu.sh`):**
   - Todas as opções do menu interativo `[1]` a `[30]`, `[A]` e `[0]` devem permanecer **100% funcionais, íntegras e sem quebras** em qualquer alteração.

---

## 2. PADRÃO DE ARQUITETURA DE UI PARA ISSABEL/ELASTIX
1. **Estrutura de Containers com Overflow Interno:**
   - O framework web do Issabel renderiza o conteúdo dentro de containers com overflow interno ativo (`#neo-contentbox`, `.neo-contentbox`, `#content`).
2. **Proibição de `position: fixed` Simples na Árvore de Módulos:**
   - É **proibido** utilizar `position: fixed` diretamente em divs inseridas no fluxo de layout dos módulos CDR, tabelas e relatórios, pois o container com scroll interno quebra o viewport fixing.
3. **Uso Obrigatório de HTML5 `<dialog>` Nativo (Top Layer):**
   - Qualquer modal, player de áudio flutuante, formulário popup ou janela de visualização **DEVE utilizar obrigatoriamente a tag HTML5 `<dialog>` nativa** (`dialog#modal-player-gravacao` ou equivalente).
   - O elemento deve ser anexado diretamente à raiz do documento (`document.body.appendChild(...)`) e aberto via método nativo `.showModal()`, garantindo renderização na **Top Layer** do navegador, imune a restrições de overflow, transformações ou hierarquias internas do tema.
   - O fechamento deve ser executado via `.close()`, limpando estados de reprodução e removendo/ocultando o dialog com segurança.
   - Estilização do backdrop deve ser aplicada via pseudo-elemento `dialog::backdrop` (`background: rgba(0, 0, 0, 0.65); backdrop-filter: blur(4px);`).

---

## 3. PADRÃO DE DESIGN E LAYOUT VALIDADO
1. **Preservação da Identidade Visual e Paleta Dark:**
   - Preservar a paleta escura homologada (`#1c1b2f`, `#252440`, `#0f172a`, gradientes violeta/azul `#7c3aed` / `#6366f1` e acabamentos em glassmorphism).
2. **Proteção Contra Truncamento e Quebra de Botões:**
   - Todo botão de ação contendo ícone/texto (como *"Baixar Gravação"*, *"Ouvir"*, *"Filtrar"*, *"Salvar"*) deve conter obrigatoriamente:
     ```css
     white-space: nowrap !important;
     flex-shrink: 0 !important;
     width: auto !important;
     overflow: visible !important;
     text-overflow: clip !important;
     ```
3. **Experiência Visual de Alto Impacto (Efeito "WOW"):**
   - Interfaces com tipografia moderna (Segoe UI / Google Fonts), micro-interações suaves, badges de status coloridos, cards de KPIs executivos com gradientes elegantes e gráficos dinâmicos (Chart.js).

---

## 4. WORKFLOW DE EXECUÇÃO E ESCOPO ISOLADO
1. **Apresentação de Escopo Isolado Pré-Edição:**
   - Antes de aplicar alterações em arquivos `.tpl`, `.php`, `.js` ou `.css`, apresentar claramente o escopo isolado do que será modificado, explicitando o impacto e a finalidade.
2. **Validação de Impacto Interdependente:**
   - Qualquer ajuste em componentes compartilhados (como temas, CSS centralizado `custom.css` ou scripts do framework) deve ser validado simultaneamente nos módulos dependentes (`cdrreport`, `monitoring`, `pesquisa`, `callcenter`).

---

## 5. POLÍTICA DE BACKUP E SNAPSHOTS ANTES DE ATUALIZAR
1. **Snapshot Obrigatório Pré-Atualização:**
   - Todo script de atualização (`install.sh`, `update.sh`, `ipbx-menu.sh`) **deve obrigatoriamente** criar um ponto de restauração com data e hora em `/var/backup/ipbx/backup_YYYY-MM-DD_HHMMSS`.
   - O manifesto (`manifesto.txt`) deve conter a descrição do que foi atualizado e o horário exato.
   - O link simbólico `/var/backup/ipbx/latest` deve apontar para o snapshot mais recente.

---

## 6. POLÍTICA DE ROLLBACK SEGURO (`rollback.sh`)
1. **Restauração Não-Destrutiva:**
   - A restauração de arquivos web deve sobrepor/restaurar apenas os arquivos e diretórios presentes no snapshot (`cp -rpf "$SELECTED_BACKUP/html/." /var/www/html/`), **sem jamais executar `rm -rf /var/www/html/modules`**.
2. **Histórico Versionado e Execução Flexível:**
   - Permitir escolha interativa de versões históricas ou passagem de parâmetros não-interativos (`--latest`), com suporte a pipes e leitura via `/dev/tty`.

---

## 7. INTEGRIDADE DOS DADOS EM TEMPO REAL
1. **Consultas em Dados Reais:**
   - Consultas de relatórios operacionais e dashboards devem refletir fielmente dados reais do MySQL (`asteriskcdrdb.cdr`), SQLite (`address_book.db`, `menu.db`) e do spool de gravações (`/var/spool/asterisk/monitor/`).
   - Proibido uso de dados estáticos/mockados em relatórios de produção.

---

## 8. POLÍTICA DE SEGURANÇA CIRÚRGICA E ZERO-DOWNTIME
1. **Preservação Total de APIs e Scripts PHP:**
   - Regras de Apache Hardening ou scripts de proteção **NUNCA devem interferir** em scripts PHP legítimos, módulos customizados, integrações de WhatsApp, Webhooks, APIs REST ou conexões AMI/AGI.
   - Bloqueio de execução de scripts PHP no Apache restrito estritamente a pastas estáticas de uploads/mídia (`/var/www/html/recordings/` e subpastas `/themes/*/images/`).
2. **Operação Contínua do Asterisk:**
   - Não reiniciar ou derrubar o serviço Asterisk desnecessariamente em produção. Aplicar recargas suaves (`asterisk -rx "dialplan reload"`, `logger reload`).

---

## 9. PROTOCOLO DE RESPOSTA A INCIDENTES E IMUNIZAÇÃO GLOBAL
1. **Análise Forense Prévia (Fatos Antes de Ações):**
   - Diante de anomalias, coletar diagnósticos somente-leitura (crontabs, logs do Apache, processos e permissões em `/cache/`) antes de alterar qualquer arquivo.
2. **Alimentação da Base Global (`ipbx-security-hardening.sh`):**
   - Consolidar continuamente novos padrões de vulnerabilidades/invasores detectados no repositório global para imunizar toda a frota de PBXs.
