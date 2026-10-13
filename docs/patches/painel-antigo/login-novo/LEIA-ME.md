# Login novo (Prisma Telecom)

Substitui a tela de login do painel clássico (tema `prisma_v5`) por um login novo, com a identidade da marca:
fundo azul e verde, logo em branco, mascote, e a escolha **Painel Novo 6.0 / Painel Clássico** já integrada ao formulário.
É responsivo (no celular o mascote e o texto de apoio saem e fica só o formulário).

## Instalar (como root, no servidor)
1. `bash ../seletor-painel/aplicar.sh` (uma vez; cria `seletor.php` e o redirecionamento para o painel novo).
2. `bash aplicar-login.sh`
   - snapshot em `/var/backup/ipbx/backup_...` (com manifesto e link `latest`);
   - guarda o modelo atual como `login.tpl.original-prisma`;
   - copia as imagens para `themes/prisma_v5/images/login/` e o novo `login.tpl`;
   - limpa o cache do Smarty (`templates_c`).

## Desfazer
`bash aplicar-login.sh --desfazer` (ou restaure o snapshot).

## Observações
- O login novo não usa os scripts antigos do tema; é um formulário simples que envia usuário, senha e a escolha do painel para o `index.php`.
- Imagens em `img/`: `logo_branco.png`, `mascote.png`, `triangulo.png` (recortadas das artes enviadas). Para trocar o mascote ou a logo, substitua os arquivos e rode o script de novo.
- A tela de verificação em duas etapas (2FA) usa outro modelo e não é alterada.
