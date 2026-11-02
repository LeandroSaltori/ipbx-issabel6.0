# 🔄 Atualização Automática Contínua - IPBX Prisma Telecom

Depois que um servidor recebe os **3 comandos de atualização** uma única vez, ele passa a buscar sozinho
as novidades da branch **`main`** deste repositório e a aplicá-las, toda noite, com proteção e rollback.

## Canal e fluxo de trabalho
- A branch **`main` é o canal dos clientes**: tudo que entra nela chega a todos os servidores já instalados na noite seguinte.
- Por isso só se sobe para a `main` depois de **testar de verdade** (regra do projeto). O dia entre o commit e a noite é a margem para corrigir.
- Para um servidor novo ou para atualizar na hora, rodam-se os 3 comandos (clone, `instalar-front-api.sh`, `ipbx-sentinel --install`). Eles também ativam a rotina automática.

## Como funciona (`scripts/ipbx-autoupdate.sh`, comando `ipbx-autoupdate`)
1. **Cron** `/etc/cron.d/ipbx-autoupdate`: roda entre **02:00 e 05:30** (a cada 30 min só para repetir se precisou esperar), com espera aleatória de até 15 min para espalhar os servidores. Consulta leve (`git ls-remote`): se a `main` não mudou, não faz nada.
2. **Validação:** baixa uma cópia limpa e confere a sintaxe de todos os `.sh` e `.php`. Com erro, **não aplica** e avisa no Telegram.
3. **Chamadas:** se houver chamadas em andamento, espera e tenta na janela seguinte (após 3 dias aplica mesmo assim; a atualização só troca arquivos do painel, não derruba ligação).
4. **Snapshot** do código (painel `front_api`, Sentinel, autoupdate e crons) em `/var/backup/ipbx/autoupdate/` (guarda os 5 últimos). **Dados do cliente nunca entram nem são revertidos:** bancos SQLite, chaves de API, integrações, usuários, agendas e blacklist.
5. **Aplica** com os mesmos comandos de sempre: `instalar-front-api.sh` + `ipbx-sentinel --install`.
6. **Teste de saúde:** painel responde, Asterisk segue ativo e `index.php` sem erro de sintaxe.
7. **Rollback automático:** se a aplicação ou o teste falhar, restaura o snapshot, marca a versão como ruim (não repete em loop; só tenta de novo quando sair outra) e avisa no Telegram.

## Comandos
| Comando | O que faz |
|---|---|
| `ipbx-autoupdate --status` | Versão aplicada, último resultado e se está ligado |
| `ipbx-autoupdate --now` | Verifica e aplica agora (ignora a espera por chamadas) |
| `ipbx-autoupdate --snapshots` | Lista os snapshots |
| `ipbx-autoupdate --rollback [ARQ]` | **Rollback manual** do código ao último snapshot (ou ao informado); o servidor aguarda uma versão nova |
| `ipbx-autoupdate --off` / `--on` | Desliga/liga neste servidor |
| `ipbx-autoupdate --selftest` | Teste da lógica com um repositório falso (15 verificações) |

Configuração opcional em `/etc/ipbx/autoupdate.conf`: `AUTOUPDATE=1`, `AU_BRANCH=main`, `AU_REPO_URL=...`.
Log: `/var/log/ipbx-autoupdate.log`. Avisos no Telegram usam as credenciais do Sentinel (`ipbx-sentinel --notify`).

## Limites conhecidos
- O rollback automático cobre falhas evidentes (erro ao aplicar, painel fora, Asterisk parado). Um erro de lógica que não derruba o painel só se desfaz por `--rollback` ou revertendo o commit na `main`.
- O login clássico trocado pelo instalador tem snapshot e `--desfazer` próprios (`docs/patches/painel-antigo`); o `--rollback` do autoupdate volta só o painel novo.
- Servidor sem Telegram configurado atualiza e reverte sem avisar ninguém.
