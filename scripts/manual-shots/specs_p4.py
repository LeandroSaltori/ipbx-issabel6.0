from specs_common import *
from specs_p3 import CHAT, D7
F = {}
def add(sec, titulo, *figs): F[(sec, titulo)] = list(figs)
MASK = ('js', "document.querySelectorAll('input').forEach(i=>{ if(/prismabot\\.com|^[0-9a-f-]{20,}/i.test(i.value)) i.value='https://api.exemplo.com.br/v2/api/external/xxxxxxxx'; })")
WPON = ["sqlite3 /tmp/claude-0/-home-claude/c6c31332-6c51-5b72-ac5e-03aaa26652ab/scratchpad/ut/panel/.ht_whatsapp_config.sqlite \"update system_users set webphone=1 where id=1\""]
WPOFF = ["sqlite3 /tmp/claude-0/-home-claude/c6c31332-6c51-5b72-ac5e-03aaa26652ab/scratchpad/ut/panel/.ht_whatsapp_config.sqlite \"update system_users set webphone=0 where id=1\""]
add('config', 'APIs & Conexões',
  fig('c0-api', 'index.php?module=configuracoes&action=api', 'Abas da tela APIs & Conexões.', ['Abas: 1 REST API do PABX, 2 API Prismabot, 3 WhatsApp direto (QR Code), 4 Avisos e teste de envio, 5 Banco MySQL (CDR), 6 Restauração global.', 'Endereço e chave da API (exemplo; mostrada só ao administrador).', 'Salvar Credenciais e Testar Conexão.'],
      [m(1, t='REST API', up=1, closest='nav, [class*="flex"]', pos='tl'), m(2, t='URL DA API', up=1, pos='tl'), m(3, t='Salvar Credenciais', pos='t')], steps=[MASK], minw=700, vp=(1440, 900)))
add('config', 'Inteligência Artificial',
  fig('c2-ia', 'index.php?module=configuracoes&action=ia', 'Tela de Inteligência Artificial.', ['Menu da tela: Visão geral, Tarefas da IA, provedores (OpenAI, Groq, Gemini), voz e ajustes.', 'Bolinha ao lado do provedor: verde = chave pronta; cinza = sem chave.', 'Chave "Inteligência Artificial ativa": liga ou desliga tudo.', 'Salvar.'],
      [m(1, t='Visão geral', closest='nav, aside, [class*="rounded-2xl"]', pos='tl'), m(2, t='Groq', up=1, pos='r'), m(3, t='Inteligência Artificial ativa', up=2, pos='tl'), m(4, t='^\\s*Salvar', pos='t')], minw=700),
  fig('c2-prov', 'index.php?module=configuracoes&action=ia&ia_tab=groq', 'Página de um provedor: cole a chave e salve.', ['Campo da chave (fica oculta; só aparecem início e fim).', 'Salvar testa a chave na hora.'],
      [m(1, sel='input[type=password], input[type=text][name*=key]', pos='tl'), m(2, t='^\\s*Salvar', pos='t')], minw=700))
add('config', 'Enviar mensagem de WhatsApp (topo)',
  fig('c3-btn', 'index.php?module=dashboard', 'O avião de papel fica no topo da tela.', ['Abre a janela Nova Conversa.'], [m(1, sel='#btn-nova-conversa', pos='b')], minw=420, pad=10),
  fig('c3-modal', 'index.php?module=dashboard', 'Janela Nova Conversa.', ['Busca pelo nome ou número.', 'Mensagem e envio.'], [m(1, sel='input[placeholder*="Buscar contato"]', pos='tl'), m(2, sel='textarea[placeholder*="Digite sua"]', pos='tl')], steps=[('click', '#btn-nova-conversa'), ('wait', 900)], full=1))
add('config', 'Webphone: teclas rápidas com luz de ocupado/livre',
  fig('c4-teclas', 'index.php?module=dashboard', 'Aba de teclas do Webphone.', ['Abas do Webphone: teclado e teclas.', 'Teclas com nome e ramal; a cor mostra a situação.', 'Editar teclas.'],
      [m(1, sel='[data-k=dss]', pos='tl'), m(2, sel='.dk', pos='tl'), m(3, sel='#dss-b', pos='r')],
      steps=[('wait', 1200), ('js', 'window.PrismaWebphone && window.PrismaWebphone.open()'), ('wait', 600), ('js', "(()=>{const walk=(r)=>{r.querySelectorAll('*').forEach(e=>{ if(e.shadowRoot) walk(e.shadowRoot); if(e.children.length===0 && /não parece estar configurado/.test(e.textContent||'')) e.style.display='none'; });}; walk(document);})()"), ('js', "(()=>{const h=[...document.querySelectorAll('*')].find(e=>e.shadowRoot&&e.shadowRoot.getElementById('dsspane'));h&&h.shadowRoot.querySelector('[data-k=dss]').click()})()"), ('wait', 1500)], pre=WPON, post=WPOFF, vp=(1440, 1000), clip=(1050, 40, 390, 720)))
add('config', 'Webphone (telefone no navegador)',
  fig('c5-wp', 'index.php?module=dashboard', 'O Webphone aberto no navegador.', ['Abas do telefone: teclado, agenda, teclas rápidas e histórico.', 'Campo para digitar o número ou ramal; Ligar chama.'],
      [m(1, sel='[data-k=dss]', up=1, pos='tl'), m(2, t='Ligar', pos='b')],
      steps=[('wait', 1200), ('js', 'window.PrismaWebphone && window.PrismaWebphone.open()'), ('wait', 1200), ('js', "(()=>{const walk=(r)=>{r.querySelectorAll('*').forEach(e=>{ if(e.shadowRoot) walk(e.shadowRoot); if(e.children.length===0 && /não parece estar configurado/.test(e.textContent||'')) e.style.display='none'; });}; walk(document);})()"), ('wait', 200)], pre=WPON, post=WPOFF, vp=(1440, 1000), clip=(1050, 0, 390, 620)))
add('config', 'Filtros do relatório de Gravações & CDR',
  fig('c6-fil', 'index.php?module=relatorios&action=cdr_gravacoes', 'Filtros do relatório.', ['Campo da busca e filtros de fila, duração mínima e tipo.', 'Ocultar zeradas e Só com gravação.', 'Status e tipo; depois Filtrar.'],
      [m(1, sel='input[placeholder*="Fila"]', pos='tl'), m(2, t='Ocultar zeradas', up=1, pos='tl'), m(3, t='^\\s*Filtrar', pos='t')], steps=D7, minw=700))
add('config', 'Usuário e senha iguais nos dois painéis',
  fig('c7-user', 'index.php?module=configuracoes&action=usuarios', 'Janela de novo usuário.', ['E-mail: é também o usuário do login.', 'Senha de acesso: vale nos dois painéis.'],
      [m(1, sel='#form-user-email', pos='tl'), m(2, sel='#form-user-password', pos='tl')], steps=[('clicktext', 'Criar Novo'), ('wait', 1500), ('js', "document.getElementById('form-user-name').value='Maria Souza';document.getElementById('form-user-email').value='maria@empresa.com.br';document.getElementById('form-user-whatsapp').value='5511999990001'")], minw=520, pad=40))
add('config', 'Avisos ao salvar um usuário',
  fig('c8-aviso', 'index.php?module=configuracoes&action=usuarios', 'O aviso aparece dentro da janela, acima do botão Salvar.', ['Aviso do que falta; nada é enviado até corrigir.'],
      [m(1, sel='#user-form-error', pos='tl')], steps=[('clicktext', 'Criar Novo'), ('wait', 1200), ('js', "document.querySelector('#form-user button[type=submit]').click()"), ('wait', 900)], minw=520, pad=40))
add('config', 'Como cada usuário entra',
  fig('c9-login', '__login.html', 'Todos entram pelo mesmo login.', ['Usuário ou e-mail do cadastro.', 'Senha.', 'Painel Novo 6.0 ou Painel Clássico.'],
      [m(1, sel='#input_user', pos='tl'), m(2, sel='#input_pass', pos='tl'), m(3, t='Painel Novo 6.0', up=1, pos='tl')], minw=520))
add('config', 'Player de gravação',
  fig('c10-play', 'index.php?module=relatorios&action=cdr_gravacoes', 'Player de gravação.', ['Barra de progresso.', 'Velocidade e -5s/+5s.', 'Baixar Gravação.'],
      [m(1, sel='dialog[open] audio, dialog[open] input[type=range]', pos='tl'), m(2, t='1.5x', pos='tl'), m(3, t='Baixar Grava', pos='t')], steps=D7 + [('clicktext', 'Ouvir'), ('wait', 1200), ('js', "[...document.querySelectorAll('dialog[open] *')].forEach(e=>{if(e.children.length===0&&/indispon/i.test(e.textContent))e.style.display='none'})")], full=0, minw=520, pad=40))
add('config', 'Filtros de duração e gravação nos relatórios',
  fig('c11-dur', 'index.php?module=relatorios&action=relatorio_filas', 'Filtros de duração e gravação.', ['Duração mínima em segundos.', 'Ocultar zeradas e Apenas com gravação.'],
      [m(1, sel='input[placeholder*="Ex: 10"]', pos='tl'), m(2, t='Ocultar zeradas', up=1, pos='tl')], steps=D7, minw=700))
add('config', 'Páginas no Extrato Detalhado de Chamadas',
  fig('c12-pag', 'index.php?module=relatorios&action=relatorio_filas', 'Paginação do extrato.', ['Quantas chamadas por página (20, 50 ou 100).'],
      [m(1, t='^20$', up=1, pos='t')], steps=D7 + [('js', "window.scrollTo(0,99999);document.querySelectorAll('main, [class*=overflow]').forEach(e=>e.scrollTop=99999)"), ('wait', 600)], minw=700))
add('config', 'Ouvir gravações nos relatórios',
  fig('c13-ouvir', 'index.php?module=relatorios&action=cdr_gravacoes', 'Player da linha.', ['Ouvir na própria linha.'], [m(1, t='Ouvir', pos='tl')], steps=D7, minw=700))
add('config', 'Sessão do painel',
  fig('c14-sessao', '__login.html', 'Se a sessão acabar, o painel volta ao login do IPbx Prisma.', ['Entre de novo com usuário e senha.'], [m(1, sel='.card', pos='tl')], minw=520))
add('config', 'Webphone com conta de outro servidor',
  fig('c15-outro', 'index.php?module=configuracoes&action=usuarios', 'Vincular Ramal, com a opção Personalizado (outro servidor).', ['Vincular Ramal PABX: escolha a última opção, Personalizado (outro servidor).', 'Domínio, porta, usuário e senha da conta de outro servidor.'],
      [m(1, sel='#form-user-extension', pos='tl'), m(2, sel='#form-wp-host', up=1, pos='tl')], steps=[('clicktext', 'Criar Novo'), ('wait', 1200), ('js', "const s=document.getElementById('form-user-extension');s.selectedIndex=s.options.length-1;s.dispatchEvent(new Event('change'))"), ('wait', 600)], minw=560, pad=40))
add('config', 'Usuários & Permissões',
  fig('c16-us', 'index.php?module=configuracoes&action=usuarios', 'Gestão de usuários.', ['Importar do painel clássico: traz todos de uma vez.', 'Criar Novo Usuário.', 'Perfis: Administrador, Supervisor, Usuário e Personalizado.', 'Lista de usuários, perfil, ramal e permissões.', 'Editar (lápis) e excluir (lixeira).'],
      [m(1, t='Importar do painel', pos='tl'), m(2, t='Criar Novo', pos='tr'), m(3, t='Visualização de filas', closest='[class*="grid"]', pos='tl'), m(4, t='USUÁRIO', closest='thead', pos='tl'), m(5, t='^\\s*Editar', pos='r')], minw=700))
add('config', 'Servidor SMTP & Disparos',
  fig('c17-smtp', 'index.php?module=configuracoes&action=smtp', 'Configuração do e-mail.', ['Provedor e servidor de saída (SMTP).', 'Salvar Configuração.', 'Testar Envio Real.', 'Agendamentos já cadastrados.'],
      [m(1, t='CONFIGURAÇÕES DE SAÍDA', closest='[class*="rounded"]', pos='tl'), m(2, t='Salvar Configura', pos='t'), m(3, t='Testar Envio', pos='r'), m(4, t='AGENDAMENTOS DE RELAT', closest='[class*="rounded"]', pos='tl')], minw=700, vp=(1440, 1100)))
add('config', 'Chat IA: resumo diário, bloqueio de números e rotas',
  fig('c18-chat', 'index.php?module=dashboard', 'Bloqueio de número pelo chat (exemplo).', ['Pedido.', 'Cartão para confirmar.'],
      [m(1, sel='.ai-msg.me', pos='tl'), m(2, sel='.ai-msg.bot', pos='tl')],
      steps=CHAT(('me', 'bloqueie o número 11 98888-7777'), ('bot', '<b>Confirmar ação?</b><br>Bloquear o número 11988887777 na Blacklist.<br><button type="button" class="ai-chip" data-ok="1">Confirmar</button><button type="button" class="ai-chip" data-ok="0">Cancelar</button>')), minw=520))
add('config', 'Ligar com um clique (click-to-call)',
  fig('c19-c2c', 'index.php?module=relatorios&action=cdr_gravacoes', 'Ícone de telefone ao lado do número.', ['Clique no telefone do número para ligar.'], [m(1, sel='i.fa-phone, [title*="Ligar"]', pos='tl')], steps=D7, minw=700))
add('config', 'Resumo Diário (em Relatórios Agendados)',
  fig('c20-resumo', 'index.php?module=configuracoes&action=smtp', 'Cadastro de agendamento.', ['Relatório a enviar: Resumo Diário (ligações de ontem).', 'Horário e destino (e-mail e/ou WhatsApp).', 'Criar Agendamento.'],
      [m(1, t='Relatório a Enviar', up=1, pos='tl'), m(2, t='Horário do Disparo', up=1, pos='tl'), m(3, t='Criar Agendamento', pos='r')], minw=620, vp=(1440, 1100)))
add('config', 'Agenda sincronizada com o painel clássico',
  fig('c21-ag', 'index.php?module=whatsapp&action=contatos', 'Agenda & Contatos.', ['Os mesmos contatos da Agenda do painel clássico.', 'Sincronizar traz os números do PABX.'],
      [m(1, t='CLIENTE / NOME', closest='thead', pos='tl'), m(2, t='Sincronizar Chamadas', pos='t')], minw=700))
add('config', 'Assistente WhatsApp (opcional)',
  fig('c22-aw', 'index.php?module=configuracoes&action=assistente_whatsapp', 'Assistente no WhatsApp.', ['Ligar o assistente.', 'Números autorizados (com DDD).', 'Salvar e Enviar mensagem de teste.', 'Conversa ao vivo.'],
      [m(1, t='Ligar o assistente', up=1, pos='tl'), m(2, sel='input[placeholder*=\"16999\"]', pos='tl'), m(3, t='Enviar mensagem de teste', pos='r'), m(4, t='CONVERSA AO VIVO', closest='[class*="rounded"]', pos='tl')], minw=700))
add('config', 'Ocultar chamadas repetidas',
  fig('c24-rep', 'index.php?module=relatorios&action=cdr_gravacoes', 'A caixinha já vem marcada.', ['Ocultar chamadas repetidas: desmarque para ver todos os registros.'], [m(1, t='Ocultar chamadas repetidas', up=1, pos='tl')], steps=D7, minw=620))
add('config', 'Algo não funciona? Consertar sozinho e copiar relatório',
  fig('c25-fix', 'index.php?module=configuracoes&action=status', 'Card de conserto automático.', ['Tentar consertar sozinho.', 'Copiar relatório para o suporte.'],
      [m(1, t='Tentar consertar', pos='t'), m(2, t='Copiar relat', pos='t')], minw=620))
add('config', 'Painel de Segurança',
  fig('c27-seg', 'index.php?module=configuracoes&action=seguranca', 'Painel de Segurança.', ['Endereços sempre liberados (whitelist).', 'Adicionar endereço.', 'Quem tentou acessar e foi bloqueado.'],
      [m(1, t='WHITELIST', closest='[class*="rounded"]', pos='tl'), m(2, t='Adicionar', pos='r'), m(3, t='IPS QUE MAIS', closest='[class*="rounded"]', pos='tl')], minw=700))
add('config', 'Conversas do Assistente IA',
  fig('c28-conv', 'index.php?module=configuracoes&action=chat_ia', 'Tela para administradores.', ['Lista de usuários à esquerda; escolha um.', 'A conversa aparece à direita, só para leitura.'],
      [m(1, t='Nenhuma conversa registrada', closest='[class*="rounded"]', pos='tl'), m(2, t='Selecione um usu', closest='[class*="rounded"]', pos='tl')], minw=700))
add('config', 'Logs & Diagnósticos',
  fig('c29-logs', 'index.php?module=configuracoes&action=logs', 'Logs e diagnósticos.', ['Arquivos de log: ações de usuários, erros, SMTP, API, AMI, IA e outros.', 'Filtro por nível e busca por palavra.', 'Baixar o arquivo ou limpar.', 'Registro em tempo real.'],
      [m(1, t='Ações de Usuári', closest='[class*="grid"]', pos='tl'), m(2, t='Todos os Níveis', pos='tl'), m(3, t='Baixar Arquivo', pos='t'), m(4, t='user_actions', closest='[class*="rounded"]', pos='tl')], minw=700))
add('config', 'Console da central',
  fig('c30-con', 'index.php?module=configuracoes&action=console', 'Console da central.', ['Botões prontos: chamadas ativas, ramais, registros e transportes.', 'Detalhes de um ramal ou log filtrado por ramal.', 'Saída do que você pediu.', 'Campo de comando; Executar.'],
      [m(1, t='Chamadas ativas', up=1, pos='tl'), m(2, t='Ramal', up=1, pos='tl'), m(3, t='SAÍDA', closest='[class*="rounded"]', pos='tl'), m(4, t='Executar', pos='r')], minw=700))
