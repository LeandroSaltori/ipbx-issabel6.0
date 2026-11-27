from specs_common import *
F = {}
def add(sec, titulo, *figs): F[(sec, titulo)] = list(figs)
add('ramais', 'Grupos de Chamada',
  fig('r4-grupos', 'index.php?module=ramais&action=grupos', 'Lista de grupos de chamada, espelhada com a central.', ['Novo grupo: abre o formulário de cadastro.', 'Lista dos grupos: número, nome, como toca e membros.', 'Editar ou Excluir cada grupo (Excluir é bloqueado se o grupo estiver em uso).'],
      [m(1, t='Novo grupo', pos='tl'), m(2, t='^Grupo$', up=3, pos='tl'), m(3, t='^Editar', pos='b')], minw=700),
  fig('r4-novo', 'index.php?module=ramais&action=grupos&edit=600', 'Edição de um grupo.', ['Número e nome do grupo.', 'Como tocar: todos juntos, em sequência etc., e tempo de toque.', 'Ramais do grupo (e números externos, se quiser).', 'Salvar e aplicar na central: grava e já vale nas ligações.'],
      [m(1, t='Número do grupo', closest='div', pos='tl'), m(2, sel='form select', pos='tl'), m(3, t='Ramais do grupo', closest='div', pos='tl'), m(4, t='Salvar e aplicar', pos='tl')], minw=700))
add('config', 'Monitor de Ramais e Conexões',
  fig('c27-mon', 'index.php?module=configuracoes&action=monitor_ramais', 'Monitor de Ramais e Conexões.', ['Filtros: todos, online, offline, oscilando ou com senha errada.', 'Estado do ramal e IP de onde ele está conectado.', 'Aparelho (modelo) e MAC, quando o telefone informa.', 'Quedas nas últimas 24 h e tentativas de senha errada.'],
      [m(1, t='^Todos', pos='tl'), m(2, t='^Estado$', pos='tl'), m(3, t='^Aparelho$', pos='tl'), m(4, t='Quedas', pos='tl')], minw=900, clip=(255, 40, 1440, 440)))
