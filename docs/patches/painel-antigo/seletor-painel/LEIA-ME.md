# Seletor de painel (Painel Novo 6.0 / Painel Clássico)

Patch para o painel clássico. **Não altera nenhum arquivo existente da central até você executar o passo 3.**

## Como funciona
1. Depois do login no painel clássico, o usuário vê dois cartões: **Painel Novo 6.0** e **Painel Clássico**.
2. Ao escolher o novo, o servidor gera um passe assinado (válido por 45 segundos, uso único) com usuário, grupos e ramal e abre `front_api/index.php?sso=...`.
3. O painel novo confere a assinatura e cria/abre o usuário vinculado. **Nenhuma senha é lida, copiada ou guardada.**
4. Perfis na primeira entrada: `administrator` → Administrador, `Supervisor` → Supervisor, `Ramal` e qualquer grupo desconhecido → Usuário (menor acesso). Depois, ajuste as permissões em Configurações > Usuários & Permissões do painel novo; a entrada seguinte não sobrescreve seus ajustes.

## Instalação (no servidor, como root)
1. Atualize o painel novo com o comando de sempre (o instalador cria a chave compartilhada `/etc/ipbx/sso.key`).
2. Copie `seletor.php` para `/var/www/html/seletor.php` (`chown asterisk:asterisk`, `chmod 644`).
3. (Opcional) para abrir o seletor logo após o login, em `/var/www/html/index.php` altere o final da função `get_post_login_redirect` de `return 'index.php';` para:
   ```php
   if (empty($_COOKIE['ipbx_painel'])) return 'seletor.php';
   if ($_COOKIE['ipbx_painel'] === 'novo') return 'seletor.php?ir=novo';
   return 'index.php';
   ```
   Faça o snapshot antes (política de backup do projeto). Para desfazer, volte a linha original.
4. Teste: entre no painel clássico, abra `https://SEU-SERVIDOR/seletor.php` e escolha o painel novo.

## Observação
O botão "Trocar de painel" pode ser um link para `seletor.php` no menu do tema `prisma_v5`.
