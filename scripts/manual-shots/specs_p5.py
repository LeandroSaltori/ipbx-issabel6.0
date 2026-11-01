from specs_common import *
F = {}
def add(sec, titulo, *figs): F[(sec, titulo)] = list(figs)
add('dashboard', 'Sincronização de ramais e filas',
  fig('d5-sync', 'index.php?module=ramais&action=editar_nome', 'Em Ramais & Atendentes: nome e tipo vêm da central; o resto é seu.', ['Número e nome do atendente: vêm da central.', 'Permissões e WhatsApp: ficam como você configurou.', 'Sincronizar atualiza na hora, sem esperar o minuto.'],
      [m(1, t='^RAMAL$', up=0, pos='tl'), m(2, t='PERDIDAS EXT', up=1, pos='t'), m(3, t='^\\s*Sincronizar', pos='b')], minw=700))
add('dashboard', 'Desempenho (telas lentas)',
  fig('d6-perf', 'index.php?module=configuracoes&action=logs', 'Aba Desempenho em Logs & Diagnósticos.', ['Aba Desempenho: telas e chamadas que demoraram mais de 1,5 s, com o tempo de cada etapa.'],
      [m(1, t='Desempenho', closest='[class*="rounded"]', pos='tl')], steps=[('clicktext', 'Desempenho'), ('wait', 1200)], minw=700))
add('whatsapp', 'Chamadas perdidas: quando avisar',
  fig('w1-perd', 'index.php?module=configuracoes&action=api', 'Aba 4, Avisos e teste de envio.', ['Aba 4: Avisos e teste de envio.', 'Limites de chamada perdida (duração mínima, etc.) ajustáveis aqui.'],
      [m(1, t='4. Avisos', up=1, pos='tl'), m(2, t='Perdida', up=2, pos='tl')], steps=[('clicktext', '4. Avisos'), ('wait', 1000)], minw=700))
add('assistente', 'Privacidade',
  fig('as10-priv', 'index.php?module=configuracoes&action=chat_ia', 'Conversas do Assistente IA (só administradores).', ['Escolha um usuário para ler as conversas dele; cada consulta fica registrada no log de ações.'],
      [m(1, t='Veja o que cada usuário', closest='[class*="rounded"]', pos='tl')], minw=700))
add('config', 'Status da Integração',
  fig('c26-st', 'index.php?module=configuracoes&action=status', 'Topo da tela Status da Integração.', ['Verificar de novo confere cada parte do sistema na hora.', 'Abaixo, cada parte aparece em uma linha com o resultado (OK, Atenção, Falha ou Desativado) e uma frase explicando.'],
      [m(1, t='Verificar de novo', pos='r'), m(2, t='Algo não está funcionando', closest='[class*="rounded"]', pos='tl')],
      steps=[('js', "[...document.querySelectorAll('span,div')].forEach(e=>{if(e.children.length===0 && /^\d+ (OK|Atenção|Falha|Desativado)$/.test(e.textContent.trim())) e.style.visibility='hidden'})")], clip=(255, 60, 1185, 280)))
