# Relatório de Auditoria Estática - ipbx-issabel6.0_claude

Branch: `audit/claude` (base: `main` de LeandroSaltori/ipbx-issabel6.0, commit `04eef3d`).
O repositório original não foi alterado (push bloqueado no clone).

> **Escopo:** validação estática (sintaxe, padrões, leitura de código) e testes isolados do `rollback.sh` numa sandbox.
> **Não houve teste em um Issabel/Asterisk real.** Este relatório não atesta que o repositório está "100% operacional".
> Faltou `shellcheck` no ambiente, então não há análise de lint além do `bash -n`.

## 1. Verificado e sem achados

| Verificação | Resultado |
|---|---|
| `bash -n` em 23 scripts `.sh` | Sem erros de sintaxe |
| `php -l` em 1086 arquivos PHP de `src/` (PHP 8.3.6) | 10 erros fatais, todos corrigidos (ver 2). Agora 0 erros |
| Links simbólicos quebrados | Nenhum |
| Menu `ipbx-menu.sh` | Opções 1 a 30, `[A]` e `[0]` presentes e ligadas a funções. Existe também a opção 31 (áudios PT-BR), fora do briefing |

## 2. Corrigido (3 commits)

1. **`fix(php8)`**: acesso a offset de string por chaves (`$s{0}`) foi removido no PHP 8 e dava Fatal error. Corrigido para `$s[0]` em 10 arquivos: `agent_console/libs/JSON.php`, `makefont.php` (4 cópias), `paloSantoCallsDetail.class.php` (2), `campaign_out/uploaders/CSV/index.php`, `client/libs/paloSantoUploadFile.class.php`, `monitoring_OLD/libs/paloSantoMonitoring.class.php`.
2. **`fix(rollback)`**: o fallback de backups `_old` executava `rm -rf` em `admin`, `lang` e `modules` (viola a política do AGENTS.md) e ignorava `--dry-run`. Agora sobrepõe com `cp -rpf`, mantém as pastas `_old` e respeita `--dry-run`. Sem terminal interativo e sem `--latest`/`--dry-run`, o script recusa em vez de assumir "sim". Testado em sandbox isolada.
3. **`chore(perms)`**: 24 scripts estavam com modo `100644` no git; agora `100755`.

## 3. Pendente: decisão sua (não alterado de propósito)

1. **Senha do MySQL embutida no código de um repositório público.** Aparece em `install.sh`, `ipbx-menu.sh`, `src/agenda.php`, `src/nome_ramais/index.php`, `src/extensions/chrome-click-to-dial/call.php` e `src/modules/pesquisa/libs/paloSantoPesquisa.class.php`. Remover o fallback quebra instalações que dependem dele, então o caminho seguro é: trocar a senha nos servidores, ler sempre de `/etc/issabel.conf` e só então apagar o fallback.
2. **`sudoers` do usuário `asterisk` com `NOPASSWD: ALL`** (`install.sh` e `ipbx-menu.sh`). Qualquer web shell vira root. Restringir a uma lista de comandos exige testar o que os módulos realmente usam (`retrieve_conf`, `fwconsole`, `asterisk`).
3. **`nome_ramais/index.php` sem autenticação** aceita POST e dispara `retrieve_conf` e `fwconsole reload`.
4. **Permissões frouxas**: `chmod 644 /etc/issabel.conf` (senha root do MySQL legível por qualquer usuário local) e `chmod 666` em `pesquisa.db`.
5. **Credenciais padrão públicas**: senha do LDAP no README e porta 10389 aberta no firewall; PIN do ChanSpy `1234`.
6. **Atualização automática**: `curl | bash` e cron semanal executam código da `main` como root, sem verificação de assinatura ou versão.
7. **Modais com `position: fixed`** (viola a regra de Top Layer): `cdrreport` (`celModalCdr`, `addressBookModal`), `monitoring`, `pesquisa`, `missed_calls` (`addressBookModal`) e `relatorio_de_filas`. Converter para `<dialog>` + `.showModal()` exige teste visual no navegador dentro do Issabel.
8. **Pastas duplicadas** (`*_OLD`, `*_ORIGINAL`, `asternic_cdr_old`) dentro de `src/modules`: aumentam o repositório (219 MB) e o risco de copiar código antigo para produção.
9. `rollback.sh` restaura o MOTD em `/usr/local/sbin/motd.sh`, e o README diz `/etc/profile.d/motd.sh`. Conferir qual é o correto.

## 4. Só pode ser validado num Issabel real

Execução do `install.sh` e do `ipbx-menu.sh` (30 opções), recargas do Asterisk, renderização dos `.tpl` Smarty no tema `prisma_v5`, módulos do Call Center, OpenVPN, LDAP e a restauração por snapshot completa.
