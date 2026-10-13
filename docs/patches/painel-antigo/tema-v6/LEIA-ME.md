# Tema prisma_v6 (painel clássico escuro)

Clone do `prisma_v5` com as cores do Painel Novo 6.0 (fundo `#14132a`/`#1c1b2f`, cartões `#252440`, violeta/índigo `#7c3aed`/`#6366f1`).
O `prisma_v5` **não é alterado**; as cores ficam em camadas carregadas por último:
`css/prisma-v6.css` (tema geral) e `css/prisma-v6-modulos.css` (CDR, Gravações, Monitoramento, Pesquisa, Chamadas Perdidas, Resumo por Ramal...).
Nenhum arquivo de módulo é editado.

```
bash aplicar-tema.sh              # instala a pasta (não troca o tema)
bash aplicar-tema.sh --ativar     # usa o prisma_v6 como padrão
bash aplicar-tema.sh --desativar  # volta ao tema anterior
```
Cada execução gera snapshot em `/var/backup/ipbx/backup_AAAA-MM-DD_HHMMSS` (com `manifesto.txt` e cópia do `settings.db`).
Também dá para escolher o tema por usuário em *System > Preferences > Theme*.
Pendência conhecida: telas que abrem em página própria (ex.: histórico CEL do CDR) não usam o tema e seguem claras.
