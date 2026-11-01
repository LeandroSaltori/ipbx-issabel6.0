from specs_common import *
UM = [('click', '#user-menu-btn'), ('wait', 500)]
GRP = [('js', "['ramais','filas','whatsapp','relatorios','config'].forEach(g=>{const e=document.getElementById('grp-'+g); if(e && e.classList.contains('hidden')) toggleSidebarGroup('grp-'+g);})"), ('wait', 400)]
# chave = (secao, titulo) -> lista de figuras
F = {}
def add(sec, titulo, *figs): F[(sec, titulo)] = list(figs)

add('inicio', 'O que é o IPbx Prisma',
  fig('i0-visao', 'index.php?module=dashboard', 'Os três blocos principais do painel.',
      ['Menu lateral: todas as telas, agrupadas por assunto.', 'Topo da tela: período e tipo de chamada que você quer ver.', 'Cards: os números do período (atendimentos, tempos, fila).'],
      [m(1, sel='#sidebar-panel', pos='tr', pad=0), m(2, t='Painel Operacional', closest='[class*="rounded-2xl"]', pos='tl'), m(3, t='Total de Atendimentos', up=4, pos='tl')], steps=D30, full=1))
add('inicio', 'Tudo o que você pode fazer no painel',
  fig('i1-menu', 'index.php?module=dashboard', 'Menu lateral com todos os grupos abertos.',
      ['Telefonia & Ramais', 'Atendimento & Filas', 'WhatsApp & Automação', 'Relatórios & Gravações', 'Configurações'],
      [m(1, sel='#grp-ramais', up=1, pos='r', nobox=0), m(2, sel='#grp-filas', up=1, pos='r'), m(3, sel='#grp-whatsapp', up=1, pos='r'), m(4, sel='#grp-relatorios', up=1, pos='r'), m(5, sel='#grp-config', up=1, pos='r')],
      steps=GRP, vp=(1440, 1500), minw=300))
add('inicio', 'Menu lateral',
  fig('i2-menu', 'index.php?module=dashboard', 'O menu lateral fica sempre à esquerda; clique em um grupo para abrir ou fechar.',
      ['Clique no nome do grupo para abrir ou fechar.', 'Itens do grupo: cada um abre uma tela.', 'Só aparecem as telas que o seu perfil permite.'],
      [m(1, t='Telefonia & Ramais', pos='r'), m(2, sel='#grp-ramais a:nth-child(2)', pos='r'), m(3, sel='#user-menu-btn', pos='r')],
      steps=[('js', "toggleSidebarGroup('grp-ramais')"), ('wait', 400)], minw=300, pad=12))
add('inicio', 'Novidades',
  fig('i3-btn', 'index.php?module=dashboard', 'O botão Novidades fica no rodapé do menu lateral.', ['Botão Novidades; o ponto amarelo avisa que há coisas novas.'],
      [m(1, t='Novidades', pos='r')], minw=300, pad=10),
  fig('i3-modal', 'index.php?module=dashboard', 'Ao clicar, abre a lista do que já foi entregue e do que vem a seguir.', ['Lista de novidades, da mais recente para a mais antiga.', 'Itens marcados "Novo" chegaram há pouco tempo.'],
      [m(1, sel='dialog[open], #roadmap-modal, [id*=roadmap]', pos='tl', pad=0), m(2, t='^Novo$', pos='r')],
      steps=[('js', 'openRoadmapModal()'), ('wait', 800)], full=1))
add('inicio', 'Entrar pelo painel clássico',
  fig('i4-login', '__login.html', 'Na tela de login, escolha em Abrir em qual painel você quer entrar.', ['Painel Novo 6.0 (já vem marcado).', 'Painel Clássico: configurações completas da central.', 'Entrar abre o painel escolhido, sem pedir a senha de novo.'],
      [m(1, t='Painel Novo 6.0', up=1, pos='tl'), m(2, t='Painel Clássico', up=1, pos='tr'), m(3, sel='.go', pos='r')], minw=520),
  fig('i4-volta', 'index.php?module=dashboard', 'Para voltar ao clássico depois de entrar, use o menu da sua conta.', ['Clique na sua conta.', 'Painel clássico: abre o painel antigo.'],
      [m(1, sel='#user-menu-btn', pos='r'), m(2, sel='#user-menu-pop a[href*=classico]', pos='r')], steps=UM, minw=320))
add('inicio', 'Sair do painel',
  fig('i5-sair', 'index.php?module=dashboard', 'O botão Sair fica no menu da sua conta.', ['Clique na sua conta (canto inferior do menu).', 'Sair encerra a sessão e volta para o login.'],
      [m(1, sel='#user-menu-btn', pos='r'), m(2, t='^\\s*Sair', sel='#user-menu-pop a[href*=logout]', pos='r')], steps=UM, minw=320))
add('inicio', 'Limpar cookies',
  fig('i6-cookies', 'index.php?module=dashboard', 'Limpar cookies fica no menu da sua conta.', ['Abra o menu da sua conta.', 'Limpar cookies: apaga os dados guardados neste navegador e recarrega a tela, sem tirar você do painel.'],
      [m(1, sel='#user-menu-btn', pos='r'), m(2, t='Limpar cookies', pos='r')], steps=UM, minw=320))
add('inicio', 'Tela de login',
  fig('i7-login', '__login.html', 'Os campos da tela de login.', ['Usuário (ou e-mail do cadastro).', 'Senha; o olho mostra ou esconde o que você digitou.', 'Abrir em: Painel Novo 6.0 ou Painel Clássico.', 'Entrar.'],
      [m(1, sel='#input_user', pos='tl'), m(2, sel='#input_pass', pos='tl'), m(3, t='Painel Novo 6.0', up=1, pos='tl'), m(4, sel='.go', pos='r')], minw=520))
add('inicio', 'Tempo de sessão',
  fig('i8-sessao', 'index.php?module=dashboard', 'Depois de 8 horas sem usar, ou ao clicar em Sair, o painel volta para a tela de login.', ['Sair encerra a sessão antes das 8 horas.'],
      [m(1, t='^\\s*Sair', sel='#user-menu-pop a[href*=logout]', pos='r'), ], steps=UM, minw=320),
  fig('i8-login', '__login.html', 'A tela de login que aparece quando a sessão acaba.', ['É só entrar de novo com usuário e senha.'], [m(1, sel='.card', pos='tl')], minw=520))
add('inicio', 'Visual escuro do painel clássico',
  fig('i9-tema', '__login.html', 'A tela de login já usa o visual escuro Prisma v6; o painel clássico usa as mesmas cores.', ['Visual Prisma v6 (escuro).'], [m(1, sel='.card', pos='tl')], minw=520))
add('inicio', 'Modo claro / escuro',
  fig('i10-btn', 'index.php?module=dashboard', 'O botão de tema fica no rodapé do menu lateral.', ['Modo Claro / Modo Escuro: um clique alterna.'], [m(1, sel='#btn-theme-toggle', pos='r')], minw=300, pad=10),
  fig('i10-claro', 'index.php?module=dashboard', 'O painel no modo claro.', ['Depois do clique, o painel inteiro muda de visual.'], [m(1, sel='#btn-theme-toggle', pos='r')], steps=[('click', '#btn-theme-toggle'), ('wait', 700)], full=1))

add('dashboard', 'Visão geral do dia',
  fig('d0-geral', 'index.php?module=dashboard', 'Dashboard com os indicadores do período.', ['Topo: período e tipo de chamada.', 'Atendimentos, demanda ativa (saída) e receptiva (entrada).', 'Tempos médios: atendimento (TMA), espera (TME) e primeira resposta (TPR).', 'Situação da fila de atendimento.'],
      [m(1, t='Painel Operacional', closest='[class*="rounded-2xl"]', pos='tl'), m(2, t='Total de Atendimentos', closest='[class*="rounded-2xl"]', pos='tl'), m(3, t='^TMA', closest='[class*="rounded-2xl"]', pos='tl'), m(4, t='Fila de Atendimento', closest='[class*="rounded-2xl"]', pos='tl')], steps=D30, full=1))
add('dashboard', 'Topo e cards compactos',
  fig('d1-topo', 'index.php?module=dashboard', 'Topo do Dashboard, com os filtros em uma linha só.', ['Título e caminho da tela.', 'Ocultar chamadas repetidas (já vem marcado).', 'Período: Hoje, 7 dias ou 30 dias.', 'Tipo: Externas (clientes), Internas (ramal a ramal) ou Todas.', 'Datas livres e botão Filtrar.'],
      [m(1, t='Painel Operacional', pos='tl'), m(2, t='Ocultar chamadas', up=1, pos='tl'), m(3, t='^Hoje$', up=1, pos='tl'), m(4, t='Externas', up=1, pos='tl'), m(5, t='^\\s*Filtrar', pos='t')], steps=D30, minh=140),
  fig('d1-cards', 'index.php?module=dashboard', 'Os seis cards têm a mesma altura.', ['Total de Atendimentos', 'Demanda Ativa', 'Demanda Receptiva', 'TMA, TME e TPR: tempos médios.'],
      [m(1, t='Total de Atendimentos', closest='[class*="rounded-2xl"]', pos='tl'), m(2, t='Demanda Ativa', closest='[class*="rounded-2xl"]', pos='tl'), m(3, t='Demanda Receptiva', closest='[class*="rounded-2xl"]', pos='tl'), m(4, t='^TMA', closest='[class*="rounded-2xl"]', pos='tl')], steps=D30, minh=300))
add('dashboard', 'Menu da sua conta',
  fig('d2-conta', 'index.php?module=dashboard', 'Clique na sua conta, no canto inferior do menu.', ['Sua conta: inicial, nome e perfil.', 'Perfil: seus dados.', 'Painel clássico.', 'Sair.'],
      [m(1, sel='#user-menu-btn', pos='r'), m(2, t='Perfil', sel='#user-menu-pop button', pos='r'), m(3, sel='#user-menu-pop a[href*=classico]', pos='r'), m(4, t='^\\s*Sair', sel='#user-menu-pop a[href*=logout]', pos='r')], steps=UM, minw=320))
add('dashboard', 'Logo da Prisma: volta ao início',
  fig('d3-logo', 'index.php?module=dashboard', 'Clique na logo para ir ao Dashboard e recolher os grupos do menu.', ['Logo da Prisma.', 'Botão para esconder ou mostrar a barra lateral.'],
      [m(1, sel='#brand-header a', pos='r'), m(2, sel='#brand-header button.brand-full', pos='r')], minw=320, pad=10))
