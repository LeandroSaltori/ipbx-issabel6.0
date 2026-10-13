# Escolha do painel na tela de login (Painel Novo 6.0 / Painel Clássico)

Na própria tela de login do painel clássico aparecem duas opções, **Painel Novo 6.0** e **Painel Clássico**. O usuário escolhe, digita usuário e senha e já entra no painel escolhido. A escolha fica lembrada no navegador.

## Como funciona
1. O formulário de login envia a escolha junto com usuário e senha.
2. Se a escolha for "Painel Novo", depois de validar a senha o painel clássico gera um passe assinado (45 segundos, uso único) e abre `front_api/index.php?sso=...`.
3. O painel novo confere a assinatura e entra. **Nenhuma senha é lida, copiada ou guardada.** Na primeira entrada o usuário é criado com o menor acesso do grupo (`administrator` → Administrador, `Supervisor` → Supervisor, demais → Usuário).
4. Quem é do grupo Webphone continua indo direto para `/phone`.

## Instalação (no servidor, como root)
1. Atualize o painel novo com o comando de sempre (o instalador cria a chave `/etc/ipbx/sso.key`).
2. `bash /root/ipbx-test/docs/patches/painel-antigo/seletor-painel/aplicar.sh`
   - cria o snapshot em `/var/backup/ipbx/backup_AAAA-MM-DD_HHMMSS` (com manifesto e link `latest`);
   - copia `seletor.php` para `/var/www/html/`;
   - acrescenta as duas opções ao `login.tpl` do tema `prisma_v5` e uma linha em `get_post_login_redirect` do `index.php`. O trecho é marcado com `IPBX-PAINEL-SELETOR` e pode ser aplicado mais de uma vez sem duplicar.
3. Teste: abra a tela de login, escolha "Painel Novo 6.0" e entre.

## Desfazer
`bash aplicar.sh --desfazer` remove os trechos (ou restaure o snapshot: `cp -rpf /var/backup/ipbx/latest/html/. /var/www/html/`).

## Observações
- `seletor.php?ir=novo` também pode ser usado como atalho "Trocar para o painel novo" dentro do painel clássico.
- Um segundo login por 2FA não é afetado; a escolha vale apenas para o login por usuário e senha.

## Botão Voltar do navegador
O `aplicar.sh` também faz o painel clássico, quando aberto sem destino (sem `menu=`) por quem escolheu **Painel Novo** no login, encaminhar de volta ao painel novo. Assim, ao clicar em Voltar depois de entrar, a pessoa não cai no painel antigo. Para usar o clássico, há o link **Painel clássico** no rodapé do menu do painel novo (ele grava a escolha como clássico).
