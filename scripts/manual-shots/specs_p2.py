from specs_common import *
F = {}
def add(sec, titulo, *figs): F[(sec, titulo)] = list(figs)
W = lambda: ('wait', 800)
add('ramais', 'Painel do Operador',
  fig('r0-fop', 'index.php?module=ramais&action=fop', 'Painel do Operador com os ramais em tempo real.', ['Visões: Cards ou Ramais + Filas.', 'Busca por ramal ou nome do atendente.', 'Filtro por tipo de ramal (PJSIP, SIP, WebRTC).', 'Cartão do ramal: número, nome e situação (Livre, Em chamada, Tocando, Fora do ar).', 'Botões de ação do ramal: ligar, espiar, sussurrar, transferir.'],
      [m(1, t='Visão Cards FOP', up=2, pos='tl'), m(2, sel='input[placeholder*=Buscar]', pos='t'), m(3, t='^Todos$', up=1, pos='t'), m(4, t='^201', closest='[class*="rounded"]', pos='tl'), m(5, sel='button[title*="Discar para Ramal"]', up=1, pos='b')], full=0, minw=560),
  fig('r0-parking', 'index.php?module=ramais&action=fop', 'Estacionamento e salas de conferência.', ['Faixa de estacionamento e conferência.', 'Mover para baixo / Minimizar a faixa.'],
      [m(1, t='Estacionamento', closest='[class*="rounded"]', pos='tl'), m(2, t='Minimizar', pos='tr')], minw=560))
add('ramais', 'Ramais & Atendentes',
  fig('r1-lista', 'index.php?module=ramais&action=editar_nome', 'Lista de ramais com nomes e permissões.', ['Botões que ligam ou desligam um recurso para todos os ramais de uma vez.', 'Busca por número ou nome.', 'Colunas de permissão: marque ou desmarque por ramal (ou use "Todos" no cabeçalho).', 'Editar abre os dados do ramal.', 'Salvar Permissões Alteradas grava o que você mudou.'],
      [m(1, t='Ativar Perdidas', up=1, pos='tl'), m(2, sel='input[placeholder*=Buscar]', pos='tl'), m(3, t='PERDIDAS EXT', up=1, pos='t'), m(4, t='^\\s*Editar', pos='r'), m(5, t='Salvar Permiss', pos='t')], minw=700, vp=(1440, 1000)),
  fig('r1-desvios', 'index.php?module=ramais&action=editar_nome', 'Aba Não Perturbe & Desvios (autoatendimento do ramal).', ['Aba Não Perturbe & Desvios.', 'Não Perturbe: o ramal para de tocar.', 'Desvio incondicional: toda chamada vai para o número informado.'],
      [m(1, t='Não Perturbe e Desvios|Não Perturbe & Desvios', maxlen=60, pos='tl'), m(2, t='^NÃO PERTURBE', pos='t'), m(3, t='DESVIO INCONDICIONAL', pos='t')], steps=[('clicktext', 'Desvios'), W()], minw=700))
add('ramais', 'Blacklist (Bloqueios)',
  fig('r2-blk', 'index.php?module=telefonia&action=blacklist', 'Cadastro e lista de números bloqueados.', ['Número a bloquear (com DDD).', 'Motivo (opcional), para lembrar depois por que foi bloqueado.', 'Bloquear Número: grava e passa a recusar as ligações desse número.', 'Lista dos números já bloqueados; remova para liberar.'],
      [m(1, sel='input[placeholder*="Ex: 1199"]', pos='tl'), m(2, sel='input[placeholder*=Telemarketing]', pos='tl'), m(3, t='Bloquear Número no PABX', pos='r'), m(4, t='Números Bloqueados', closest='[class*="rounded"]', pos='tl')],
      steps=[('js', "document.querySelectorAll('[class*=rose], [class*=red]').forEach(e=>{if(/AMI|PABX: Falha/.test(e.innerText)) e.remove()});")], minw=700))
add('ramais', 'Linhas & Troncos',
  fig('r3-tr', 'index.php?module=troncos&action=view', 'Monitor de linhas e troncos (neste exemplo ainda não há troncos cadastrados).', ['Monitor: cada tronco cadastrado aparece aqui com o estado de registro e o uso dos canais.', 'Atualizar Status confere as linhas na hora.'],
      [m(1, t='Monitor de Linhas', closest='[class*="rounded"]', pos='tl'), m(2, t='Atualizar Status', pos='tr')], minw=700))
add('filas', 'Filas ao vivo',
  fig('f0-rt', 'index.php?module=filas&action=realtime', 'Monitoramento de filas ao vivo.', ['Período: Hoje, Ontem, 7 Dias ou Mês.', 'Filtro por nome de fila e botão Atualizar.', 'Escolha entre Cards e Tabela.', 'Totais: filas, em espera agora, atendidas e abandonadas.', 'Cartão da fila, com SLA e a lista de agentes logados.'],
      [m(1, t='^Hoje$', up=1, pos='tl'), m(2, sel='input[placeholder*=Filtrar]', pos='t'), m(3, t='^\\s*Cards', up=1, pos='tr'), m(4, t='TOTAL DE FILAS', up=1, closest='[class*="grid"]', pos='tl'), m(5, t='^Fila 400', closest='[class*="rounded"]', pos='tl')], minw=700))
add('filas', 'Fluxograma de ligações',
  fig('f1-fl', 'index.php?module=filas&action=flowchart', 'Fluxograma de ligações da central.', ['Rota de entrada (por número/DID).', 'Filtro por rota.', 'Gerenciar Feriados e Horários Especiais.', 'Expandir ou Recolher todos os desenhos.', 'Clique em "[+] Clique para iniciar o fluxo" para abrir o caminho da chamada.'],
      [m(1, t='Rota #1', closest='[class*="rounded"]', pos='tl'), m(2, sel='select', pos='tl'), m(3, t='Gerenciar Feriados', pos='t'), m(4, t='^\\s*Expandir', pos='b'), m(5, t='Clique para iniciar', pos='r')], minw=700),
  fig('f1-aberto', 'index.php?module=filas&action=flowchart', 'Depois de expandir, o desenho mostra cada passo da chamada.', ['Botão Expandir abre todos os caminhos.'],
      [m(1, t='^\\s*Expandir', pos='b')], steps=[('clicktext', 'Expandir'), ('wait', 1200)], vp=(1440, 1100), minw=700))
add('filas', 'Histórico das filas',
  fig('f2-hist', 'index.php?module=relatorios&action=relatorio_filas', 'O Relatório completo de filas lê sozinho o registro das filas da central.', ['Filtrar Relatório: aplica o período e os filtros escolhidos.', 'Resumo do período: chamadas, atendidas, abandonadas, SLA e tempos de espera e de fala.', 'Gráficos por hora do dia e por atendente.'],
      [m(1, t='Filtrar Relatório', pos='t'), m(2, t='MÉDIA ESPERA', up=2, pos='tl'), m(3, t='DISTRIBUIÇÃO POR HORA', closest='[class*="rounded"]', pos='tl')], steps=[('clicktext', '7 Dias'), ('wait', 1800)], minw=700, vp=(1440, 1100)))
add('whatsapp', 'Regras (URA e transbordo)',
  fig('w0-vars', 'index.php?module=whatsapp&action=regras', 'Variáveis que você pode usar nas mensagens.', ['{CLIENTE}: número do cliente.', '{NOME_FILA}: nome da fila ou setor.', '{ATENDENTE}, {RAMAL} e {DATA_HORA}: dados da chamada.', 'Clique numa variável para inserir no texto onde está o cursor.'],
      [m(1, t='{CLIENTE}', up=1, pos='tl'), m(2, t='{NOME_FILA}', up=1, pos='tl'), m(3, t='{ATENDENTE}', up=1, pos='tl'), m(4, t='Clique em qualquer tag', pos='r')], minw=700),
  fig('w0-msg', 'index.php?module=whatsapp&action=regras', 'Caixas de texto das mensagens automáticas.', ['Mensagem de abandono de fila.', 'Mensagem de transbordo (cliente escolheu atendimento por WhatsApp).', 'Aviso ao atendente de chamada perdida.', 'Restaurar Padrões de Fábrica volta aos textos originais.', 'Salvar Regras de Integração grava tudo.'],
      [m(1, t='Mensagem Abandono', up=1, pos='tl'), m(2, t='Mensagem Transbordo', up=1, pos='tl'), m(3, t='Notificação ao Atendente', up=1, pos='tl'), m(4, t='Restaurar Padr', pos='t'), m(5, t='Salvar Regras', pos='t')], minw=700, vp=(1440, 1000)))
add('whatsapp', 'Histórico de envio',
  fig('w2-hist', 'index.php?module=whatsapp&action=historico', 'Histórico dos envios.', ['Período: Hoje, Ontem, 7 Dias, Mês ou datas.', 'Busca por número ou ramal e botão Filtrar.', 'Exportar a lista.', 'Tabela: data, destino, regra, mensagem e se chegou (status).'],
      [m(1, t='^Hoje$', up=1, pos='tl'), m(2, sel='input[placeholder*=Buscar]', pos='t'), m(3, t='^\\s*Filtrar', pos='tr'), m(4, t='DATA/HORA', closest='table', pos='tl')], steps=[('clicktext', '7 Dias'), ('wait', 1500)], minw=700))
add('whatsapp', 'Disparo rápido',
  fig('w3-di', 'index.php?module=whatsapp&action=disparos', 'Disparo rápido de teste.', ['Telefone de destino, com DDD.', 'Texto da mensagem.', 'Anexo opcional: foto, áudio, vídeo ou documento (até 16 MB).', 'Agendar para: deixe em branco para enviar já.', 'Disparar ou Agendar Mensagem.', 'Envios Agendados: o que ainda vai ser enviado.'],
      [m(1, sel='input[placeholder*="11999998888"]', pos='tl'), m(2, sel='textarea', pos='tl'), m(3, sel='input[type=file]', pos='tl'), m(4, sel='input[type=datetime-local]', pos='tl'), m(5, t='Disparar ou Agendar', pos='r'), m(6, t='Envios Agendados', closest='[class*="rounded"]', pos='tl')], minw=700, vp=(1440, 1000)))
add('whatsapp', 'Agenda & Contatos',
  fig('w4-ag', 'index.php?module=whatsapp&action=contatos', 'Agenda de contatos.', ['Novo Contato.', 'Importar contatos e exportar em CSV.', 'Abas Contatos e Mensagens Rápidas.', 'Filtro por etiqueta e busca.', 'Lista de contatos com telefone, e-mail, empresa e etiqueta.'],
      [m(1, t='Novo Contato', pos='tl'), m(2, t='Importar Prismabot', pos='t'), m(3, t='^\\s*Contatos \\(', up=1, pos='tl'), m(4, t='Etiqueta', up=1, pos='tl'), m(5, t='CLIENTE / NOME', closest='table', pos='tl')], minw=700),
  fig('w4-novo', 'index.php?module=whatsapp&action=contatos', 'Janela de novo contato.', ['Nome e telefone com WhatsApp são obrigatórios; empresa, e-mail, etiqueta e observações são opcionais.', 'Adicionar Contato grava o contato (e ele aparece também na Agenda do painel clássico).'],
      [m(1, sel='input[placeholder="Nome completo"]', pos='tl'), m(2, t='Adicionar Contato', pos='r')], steps=[('clicktext', 'Novo Contato'), ('wait', 1000)], minw=520, pad=60))
