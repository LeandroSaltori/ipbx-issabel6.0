import json
from specs_common import *
F = {}
def add(sec, titulo, *figs): F[(sec, titulo)] = list(figs)
def CHAT(*msgs, greet=False):
    js = "aiChatOpen(); setTimeout(()=>{const b=document.getElementById('ai-chat-body'); if(!%s) b.innerHTML=''; %s; b.scrollTop=0;},700)" % ('true' if greet else 'false', ';'.join("(()=>{const d=document.createElement('div');d.className='ai-msg %s';d.innerHTML=%s;b.appendChild(d)})()" % (c, json.dumps(h)) for c, h in msgs))
    return [('js', js), ('wait', 1500)]
CHATSEL = '#ai-chat-dialog'
D7 = [('clicktext', '7 Dias'), ('wait', 2000)]
add('relatorios', 'Gravação por WhatsApp: arquivo anexo',
  fig('rl0-cdr', 'index.php?module=relatorios&action=cdr_gravacoes', 'Na lista de chamadas, o botão do WhatsApp envia a gravação como arquivo de áudio.', ['Coluna Player & Copiloto: ouvir, baixar e enviar por WhatsApp.'],
      [m(1, t='Ouvir', up=1, pos='tl')], steps=D7, minw=700))
add('relatorios', 'Chat IA: enviar e agendar por conversa',
  fig('rl1-chat', 'index.php?module=dashboard', 'Peça ao assistente e confirme o cartão antes de enviar (conversa de exemplo).', ['Seu pedido, em linguagem normal.', 'Cartão de confirmação: destinatário, texto, data e hora.', 'Confirmar envia ou agenda; Cancelar descarta.'],
      [m(1, sel='.ai-msg.me', pos='tl'), m(2, sel='.ai-msg.bot:last-child', pos='tl'), m(3, sel='.ai-chip[data-ok="1"]', pos='b')],
      steps=CHAT(('me', 'agende o resumo de ontem para a Carlos às 8h'), ('bot', '<b>Confirmar ação?</b><br>Agendar WhatsApp para Carlos Almeida em 02/11 às 08:00: "Bom dia! Segue o resumo de ontem."<br><button type="button" class="ai-chip" data-ok="1">Confirmar</button><button type="button" class="ai-chip" data-ok="0">Cancelar</button>')), minw=520),
  fig('rl1-agendados', 'index.php?module=whatsapp&action=disparos', 'O agendamento aparece em Disparo Rápido, na lista Envios Agendados.', ['Lista de Envios Agendados, com o que foi marcado pelo Chat IA.'],
      [m(1, t='Envios Agendados', closest='[class*="rounded"]', pos='tl')], minw=700))
add('relatorios', 'Pesquisa de Satisfação',
  fig('rl2-pe', 'index.php?module=relatorios&action=pesquisa', 'Filtros e indicadores da pesquisa.', ['Período: Hoje, Ontem, 7 dias, Este mês, Tudo ou Personalizado.', 'Filtros: operador, nota e resolução; depois, Filtrar.', 'Cards: transferidas para a pesquisa, média, taxa de resolução e satisfação.', 'Gráficos com a distribuição das notas e a resolução dos problemas.'],
      [m(1, t='^Hoje$', up=1, pos='tl'), m(2, t='Todos os operadores', pos='t'), m(3, t='TRANSFERIDAS', closest='[class*="grid"]', pos='tl'), m(4, t='DISTRIBUIÇÃO DAS NOTAS', closest='[class*="rounded"]', pos='tl')], steps=[('clicktext', 'Tudo'), ('wait', 1800)], minw=700, vp=(1440, 1000)))
add('relatorios', 'CDR & Gravações',
  fig('rl3-cdr', 'index.php?module=relatorios&action=cdr_gravacoes', 'Filtros e lista de chamadas.', ['Período e busca por ramal ou número.', 'Filtros de origem/destino, fila, duração, status e tipo.', 'Filtrar aplica o que você escolheu.', 'Lista de chamadas: data, origem, destino, duração e resultado.', 'Ouvir abre o player; os outros botões baixam, enviam por WhatsApp ou ligam de volta.'],
      [m(1, t='^Hoje$', up=1, pos='tl'), m(2, t='Origem ou Destino', pos='t'), m(3, t='^\\s*Filtrar', pos='t'), m(4, t='DATA/HORA', closest='thead', pos='tl'), m(5, t='^\\s*Ouvir', pos='r')], steps=D7, minw=700, vp=(1440, 1000)))
add('relatorios', 'Relatório completo de filas',
  fig('rl4-rf', 'index.php?module=relatorios&action=relatorio_filas', 'Relatório completo de filas.', ['Período rápido e datas.', 'Filtros: fila, atendente, status, telefone e duração.', 'Filtrar Relatório.', 'Cards de resumo: chamadas, atendidas, abandonadas, SLA e tempos.', 'Gráficos por hora do dia e por agente.'],
      [m(1, t='Período Rápido', pos='tl'), m(2, t='Filas PABX', up=1, pos='tl'), m(3, t='Filtrar Relatório', pos='tr'), m(4, t='TOTAL', closest='[class*="grid"]', pos='tl'), m(5, t='DISTRIBUIÇÃO POR HORA', closest='[class*="rounded"]', pos='tl')], steps=D7, minw=700, vp=(1440, 1100)))
add('relatorios', 'Relatórios gráficos',
  fig('rl5-gr', 'index.php?module=relatorios&action=graphic_reports', 'Relatórios gráficos.', ['Escolha o que ver: Ramais, Filas ou Troncos.', 'Período e tipo de chamada.', 'Gráfico de pizza com a participação de cada ramal.', 'Botões para exportar em planilha, PDF, e-mail e WhatsApp.'],
      [m(1, t='Extensions', up=1, pos='tl'), m(2, t='Externas', sel='select', pos='tl'), m(3, t='Gráfico de Chamadas', closest='[class*="rounded"]', pos='tl'), m(4, sel='button[title*=PDF], button[title*=pdf]', pos='b')], steps=D7, minw=700, vp=(1440, 1000)))
add('relatorios', 'Relatório geral & mapa de calor',
  fig('rl6-ge', 'index.php?module=relatorios&action=relatorio_geral', 'Contadores e mapa de calor.', ['Período; "Incluir internas" soma também ramal para ramal.', 'Contadores de chamadas: total, recebidas, efetuadas, internas e únicos.', 'Duração das chamadas.'],
      [m(1, t='Incluir Internas', up=1, pos='tl'), m(2, t='Contadores de Chamadas', closest='[class*="rounded"]', pos='tl'), m(3, t='Duração das Chamadas', closest='[class*="rounded"]', pos='tl')], steps=D7, minw=700, vp=(1440, 1100)))
add('relatorios', 'Relatórios de IA',
  fig('rl7-ia', 'index.php?module=relatorios&action=ia_analytics', 'Auditoria e análise de voz com IA.', ['Filtros: tipo de chamada, período, sentimento, assunto.', 'Processar Auditoria Global: a IA analisa as chamadas do período.', 'Indicadores: visão geral dos clientes, nota do script, alertas de risco, resolução na 1ª chamada.', 'Assuntos mais falados e checklist de conformidade.'],
      [m(1, t='Externas', sel='select', pos='tl'), m(2, t='Processar Auditoria', pos='t'), m(3, t='VIBE GERAL', closest='[class*="grid"]', pos='tl'), m(4, t='Categorização de Assuntos', closest='[class*="rounded"]', pos='tl')], steps=D7, minw=700, vp=(1440, 1100)))
add('relatorios', 'Análise com IA e Histórico',
  fig('rl8-ana', 'index.php?module=relatorios&action=relatorio_geral', 'Cada relatório tem o botão Analisar com IA e o Histórico das análises.', ['Histórico: análises já feitas.', 'Analisar com IA: usa só os números do período que está na tela.'],
      [m(1, t='^\\s*Histórico', pos='tl'), m(2, t='Analisar com IA', pos='tr')], steps=D7, minw=700))
add('relatorios', 'Assistente: externas, internas e todas',
  fig('rl9-tipo', 'index.php?module=dashboard', 'O mesmo critério do Dashboard: Externas, Internas ou Todas.', ['Externas (clientes) é o padrão.', 'Internas é ramal a ramal; Todas soma as duas.'],
      [m(1, t='Externas', up=1, pos='tl'), m(2, t='Internas', pos='t')], steps=D30, minw=520))
# ---- assistente
add('assistente', 'Como usar',
  fig('as0-fab', 'index.php?module=dashboard', 'O botão do assistente fica no canto da tela.', ['Clique no botão para abrir a conversa.'], [m(1, sel='#ai-chat-fab', pos='l')], minw=420, pad=14),
  fig('as0-chat', 'index.php?module=dashboard', 'A janela do assistente.', ['Cabeçalho: Nova conversa (reinicia a tela) e fechar.', 'Perguntas prontas: um clique já pergunta.', 'Campo para escrever com suas palavras; Enter ou Enviar.'],
      [m(1, sel='#ai-chat-head', pos='tl'), m(2, sel='.ai-chip', pos='tl'), m(3, sel='#ai-chat-form', pos='tl')], steps=[('js', 'aiChatOpen()'), ('wait', 1500)], minw=520))
add('assistente', 'O que ele consulta',
  fig('as1-cons', 'index.php?module=dashboard', 'Exemplo de pergunta e resposta com números do período (conversa de exemplo).', ['Pergunta.', 'Resposta com os dados reais da central.'],
      [m(1, sel='.ai-msg.me', pos='tl'), m(2, sel='.ai-msg.bot', pos='tl')],
      steps=CHAT(('me', 'quantas chamadas perdidas hoje?'), ('bot', 'Hoje (chamadas <b>externas</b>) houve <b>12</b> chamadas recebidas, <b>9</b> atendidas e <b>3</b> perdidas. Tempo médio de atendimento: 1m 50s.')), minw=520))
add('assistente', 'Ouvir gravações',
  fig('as2-grav', 'index.php?module=dashboard', 'O player aparece dentro da conversa (exemplo).', ['Pedido da gravação.', 'Player no próprio chat, com opção de abrir ou baixar.'],
      [m(1, sel='.ai-msg.me', pos='tl'), m(2, sel='audio', pos='tl')],
      steps=CHAT(('me', 'quero ouvir a última chamada do ramal 201'), ('bot', 'Aqui está a gravação de 01/11 às 05:44 (30s):<br><audio controls style="width:100%"></audio><br><a href="#" style="color:#a5b4fc">Abrir em nova aba</a> · <a href="#" style="color:#a5b4fc">Baixar</a>')), minw=520))
add('assistente', 'Enviar relatório por WhatsApp',
  fig('as3-env', 'index.php?module=dashboard', 'O envio só acontece depois do seu Confirmar (exemplo).', ['Pedido de envio.', 'Cartão com destinatário e texto.', 'Confirmar envia; Cancelar descarta.'],
      [m(1, sel='.ai-msg.me', pos='tl'), m(2, sel='.ai-msg.bot', pos='tl'), m(3, sel='.ai-chip[data-ok="1"]', pos='b')],
      steps=CHAT(('me', 'envie o resumo de hoje para a Carlos'), ('bot', '<b>Confirmar ação?</b><br>Enviar WhatsApp para Carlos Almeida: "Resumo de hoje: 12 chamadas, 9 atendidas, 3 perdidas."<br><button type="button" class="ai-chip" data-ok="1">Confirmar</button><button type="button" class="ai-chip" data-ok="0">Cancelar</button>')), minw=520))
add('assistente', 'Sugerir recursos nos ramais',
  fig('as4-sug', 'index.php?module=dashboard', 'O assistente propõe e você confirma (exemplo).', ['Pedido.', 'Proposta de alteração com Confirmar e Cancelar.'],
      [m(1, sel='.ai-msg.me', pos='tl'), m(2, sel='.ai-msg.bot', pos='tl')],
      steps=CHAT(('me', 'ative o aviso de chamada perdida no ramal 202'), ('bot', '<b>Confirmar ação?</b><br>Ativar "aviso de perdida externa" no ramal 202 (João Pereira).<br><button type="button" class="ai-chip" data-ok="1">Confirmar</button><button type="button" class="ai-chip" data-ok="0">Cancelar</button>')), minw=520))
add('assistente', 'Alterar ramais pelo assistente',
  fig('as5-alt', 'index.php?module=dashboard', 'Renomear ou cadastrar celular pelo chat (exemplo).', ['Pedido com nome e celular.', 'Cartão do que vai mudar; só aplica depois do Confirmar.'],
      [m(1, sel='.ai-msg.me', pos='tl'), m(2, sel='.ai-msg.bot', pos='tl')],
      steps=CHAT(('me', 'renomeie o ramal 203 para Ana Costa e cadastre o celular 11 99999-0003'), ('bot', '<b>Confirmar ação?</b><br>Ramal 203: nome → Ana Costa; WhatsApp → 5511999990003.<br><button type="button" class="ai-chip" data-ok="1">Confirmar</button><button type="button" class="ai-chip" data-ok="0">Cancelar</button>')), minw=520))
add('assistente', 'Tudo o que o assistente consegue fazer',
  fig('as6-tudo', 'index.php?module=dashboard', 'As perguntas prontas mostram o que dá para pedir.', ['Cada botão é uma pergunta pronta: clique e a resposta vem na hora.'],
      [m(1, sel='.ai-chip', pos='tl')], steps=[('js', 'aiChatOpen()'), ('wait', 1500)], minw=520))
add('assistente', 'Análises de IA nos relatórios',
  fig('as7-bot', 'index.php?module=relatorios&action=relatorio_filas', 'Botões do Copiloto de IA, dentro de cada relatório.', ['Histórico das análises.', 'Analisar com IA.'],
      [m(1, t='^\\s*Histórico', pos='tl'), m(2, t='Analisar com IA', pos='tr')], steps=D7, minw=700))
add('assistente', 'Chat IA: contatos, mensagens de aviso e filas',
  fig('as8-cont', 'index.php?module=dashboard', 'Cadastro de contato pelo chat (exemplo).', ['Pedido.', 'Cartão com o que será gravado.'],
      [m(1, sel='.ai-msg.me', pos='tl'), m(2, sel='.ai-msg.bot', pos='tl')],
      steps=CHAT(('me', 'cadastre a Fernanda Rocha, celular 11 98888-0002, etiqueta Comercial'), ('bot', '<b>Confirmar ação?</b><br>Novo contato: Fernanda Rocha · 5511988880002 · etiqueta Comercial.<br><button type="button" class="ai-chip" data-ok="1">Confirmar</button><button type="button" class="ai-chip" data-ok="0">Cancelar</button>')), minw=520))
add('assistente', 'Histórico e nova conversa',
  fig('as9-nova', 'index.php?module=dashboard', 'Nova conversa limpa a tela; o registro continua guardado.', ['Nova conversa.'],
      [m(1, sel='#ai-chat-new', pos='b')], steps=[('js', 'aiChatOpen()'), ('wait', 1500)], minw=520))
