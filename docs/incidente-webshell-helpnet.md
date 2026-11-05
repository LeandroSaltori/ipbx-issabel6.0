# Incidente: webshell em uso (Helpnet, 05/11/2026)

Registro de um caso real e do que o Sentinel passou a fazer por causa dele.

## Linha do tempo
- **08:52:07**: IP externo `200.150.126.174` chama `GET /PBX.php?cmd=cat /etc/passwd` e `/cmd.php?cmd=...` no HTTPS do servidor. O `PBX.php` respondeu 200 com o conteúdo: era uma **webshell funcionando**, plantada numa invasão anterior. Repetido às 08:53:08 e 08:53:11.
- **08:53:03**: o monitor antigo avisa "NOVO USUARIO WEB CRIADO: supportpbx" (nome usado pela campanha "Emad"; está na lista de contas maliciosas do `ipbx-security-hardening.sh`). Na checagem posterior o usuário não existia mais no `acl.db`.
- **11:32**: ao rodar o monitor de segurança pelo menu, o Sentinel isolou na quarentena `PBX.php`, `admin/PBX.php`, `images/config.all.php`, `images/phpversions.php` e `admin/modules/_cache`. O hardening aplicou a proteção em `control_panel/libs/utilities.php` (falha de execução remota).

## O que foi verificado
- `/etc/passwd`: sem UID 0 novo nem usuários extras; sem `/root/.ssh`.
- `sudo` do `asterisk` limitado (shutdown, service, chkconfig, issabel-helper); nenhum sudo na janela do ataque.
- `ampusers` (FreePBX): apenas `admin`. `acl.db`: sem `supportpbx`.
- `rpm -V` dos binários que a auditoria apontou (`kmod`, `dnf-3`, `xtables-nft-multi`) deu limpo: era falso positivo (corrigido no Sentinel).
- Limite da evidência: o log do Apache só mostra GET; comandos enviados por POST não aparecem.

## O que o Sentinel passou a fazer
- **Alerta "WEBSHELL EM USO"** (`check_webshell_hits`, a cada minuto): requisição a `.php` com `?cmd=`, `?exec=`, `?shell=`, `?command=`, `?system=` ou `?passthru=` nos logs do Apache. Um alerta por IP + arquivo, com o código de resposta (200 = o arquivo executou). Só avisa, nada é alterado.
- Auditoria de binários ignora a entrada de link simbólico do rpm (falso positivo de `kmod`, `dnf-3`, `xtables-nft-multi`).

## Resposta recomendada a um caso assim
1. `ipbx-sentinel --list` e `--audit`; ver no log do HTTPS (`ssl_access_log`) tudo que o IP fez.
2. Trocar senhas: root, administradores da central, MySQL, AMI e troncos SIP.
3. Conferir o CDR atrás de ligações de saída estranhas (fraude).
4. Fechar a porta 3306 para fora da rede e remover `root@%` do MySQL se nada depender dele.
5. Se houver sinal de root (conta nova, chave SSH, binário trocado), restaurar backup limpo ou reinstalar.
