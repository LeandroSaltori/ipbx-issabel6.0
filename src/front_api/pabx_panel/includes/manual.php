<?php
/**
 * Manual do IPbx Prisma (mostrado ao cliente em "Manual").
 * REGRA 11 do AGENTS.md: toda correção ou funcionalidade nova DEVE ser descrita aqui no mesmo commit.
 * Estrutura: seções com 'id', 'titulo', 'icon' e 'itens' => [['t' => título, 'd' => texto, e opcionais:
 *   'passos' => [..], 'campos' => ['Campo' => 'explicação'], 'dica' => '..', 'aviso' => '..', 'img' => 'manual/arquivo.png' (em assets/)]].
 * Texto em português, voltado ao cliente, sem jargão técnico e sem nomes de terceiros.
 */
return [
    ['id' => 'inicio', 'titulo' => 'Primeiros passos', 'icon' => 'fa-flag-checkered', 'itens' => [
        ['t' => 'O que é o IPbx Prisma', 'd' => 'Painel para acompanhar e administrar sua central telefônica: ramais, filas, linhas, relatórios, gravações, WhatsApp e assistente inteligente.'],
        ['t' => 'Menu lateral', 'd' => 'Todas as telas ficam no menu à esquerda. Você só vê as telas que seu perfil permite (veja Usuários & Permissões).'],
        ['t' => 'Novidades', 'd' => 'O botão Novidades mostra tudo o que foi entregue recentemente e o que vem a seguir.'],
        ['t' => 'Entrar pelo painel clássico', 'd' => 'Na própria tela de login você escolhe entre o Painel Novo 6.0 e o Painel Clássico, e entra no escolhido sem digitar a senha duas vezes. Na primeira entrada o usuário é criado no painel novo com o menor acesso do grupo dele (Administrador, Supervisor ou Usuário).',
            'passos' => ['Abra a tela de login do IPbx.', 'Marque Painel Novo 6.0 (ou Painel Clássico), digite usuário e senha e clique em Entrar. A escolha fica lembrada neste navegador.', 'Para ajustar o que cada pessoa vê, use Configurações > Usuários & Permissões no painel novo.'],
            'dica' => 'Os usuários continuam sendo criados no painel clássico. O painel novo só guarda as permissões e o WhatsApp de cada um.',
            'aviso' => 'Quem for desativado no painel novo perde o acesso mesmo entrando pelo clássico.'],
        ['t' => 'Sair do painel', 'd' => 'O botão Sair, no rodapé do menu lateral, encerra sua sessão e leva de volta à tela de login inicial.'],
        ['t' => 'Tela de login', 'd' => 'A tela de login tem a identidade da Prisma Telecom e já traz a escolha entre o Painel Novo 6.0 e o Painel Clássico. Funciona também no celular.', 'passos' => ['Digite usuário e senha.', 'Em Abrir em, escolha Painel Novo 6.0 ou Painel Clássico.', 'Clique em Entrar. O olho no campo de senha mostra ou esconde o que você digitou.']],
        ['t' => 'Modo claro / escuro', 'd' => 'Você pode alternar o visual do painel pelo botão de tema.'],
    ]],
    ['id' => 'dashboard', 'titulo' => 'Dashboard', 'icon' => 'fa-chart-pie', 'itens' => [
        ['t' => 'Visão geral do dia', 'd' => 'Indicadores de chamadas recebidas, atendidas, perdidas e em andamento, com gráficos que atualizam sozinhos.'],
    ]],
    ['id' => 'ramais', 'titulo' => 'Telefonia & Ramais', 'icon' => 'fa-phone', 'itens' => [
        ['t' => 'Painel do Operador', 'd' => 'Mostra todos os ramais em tempo real: livre, em chamada, tocando ou fora do ar, além de linhas, estacionamento e salas de conferência.'],
        ['t' => 'Ramais & Atendentes', 'd' => 'Em um só lugar: nomes dos ramais, permissões, Não Perturbe e desvios de chamada (autoatendimento do ramal, na aba Desvios).',
            'passos' => ['Abra Telefonia & Ramais > Ramais & Atendentes.', 'Clique no ramal que deseja editar.', 'Altere os campos desejados e salve.', 'Para desvios de chamada, use a aba Desvios.'],
            'campos' => ['Ramal' => 'Número do ramal (não editável).', 'Nome do Atendente' => 'Nome mostrado nos relatórios, no Painel do Operador e nos avisos.', 'Celular / WhatsApp (Notificações)' => 'Número que recebe os avisos do atendente, como chamadas perdidas.', 'Nova Senha (Opcional)' => 'Preencha somente se quiser trocar a senha do ramal; em branco mantém a atual.'],
            'dica' => 'Mantenha o celular preenchido com DDD para o atendente receber os avisos por WhatsApp.'],
        ['t' => 'Blacklist (Bloqueios)', 'd' => 'Cadastre números que não devem conseguir ligar para a empresa.',
            'passos' => ['Abra Telefonia & Ramais > Blacklist (Bloqueios).', 'Digite o número a bloquear e, se quiser, o motivo.', 'Salve. A partir daí o número deixa de completar ligações.', 'Para liberar, remova o número da lista.'],
            'campos' => ['Número a Bloquear' => 'Telefone com DDD, somente números.', 'Motivo / Descrição' => 'Anotação interna para saber por que foi bloqueado.']],
        ['t' => 'Linhas & Troncos', 'd' => 'Mostra se cada linha está registrada. Quando uma linha cai, o painel pode avisar por WhatsApp.'],
    ]],
    ['id' => 'filas', 'titulo' => 'Atendimento & Filas', 'icon' => 'fa-headset', 'itens' => [
        ['t' => 'Filas ao vivo', 'd' => 'Quem está na fila, quanto tempo espera e quais atendentes estão disponíveis.'],
        ['t' => 'Fluxograma de ligações', 'd' => 'Desenho do caminho que uma chamada percorre dentro da central.'],
        ['t' => 'Histórico das filas', 'd' => 'O painel lê sozinho o registro real das filas da central, sem precisar de configuração.'],
    ]],
    ['id' => 'whatsapp', 'titulo' => 'WhatsApp & Automação', 'icon' => 'fa-brands fa-whatsapp', 'itens' => [
        ['t' => 'Regras (URA, transbordo e NPS)', 'd' => 'Defina avisos automáticos por WhatsApp, como pesquisa de satisfação e aviso de abandono de fila.'],
        ['t' => 'Chamadas perdidas: quando avisar', 'd' => 'O atendente só é avisado quando a perda é real: ignora chamadas muito curtas e chamadas atendidas em outro ramal. Os limites são ajustáveis na tela de APIs & Conexões.'],
        ['t' => 'Webhook das respostas da pesquisa (NPS)', 'd' => 'Endereço que o Prismabot usa para avisar o painel quando o cliente responde a pesquisa com uma nota de 1 a 5. Copie o endereço mostrado em APIs & Conexões e cadastre-o no Prismabot como webhook de mensagens recebidas.', 'dica' => 'Se você abrir esse endereço direto no navegador, a resposta "ignorado" é normal: significa que o endereço está funcionando e que não havia mensagem para processar.', 'aviso' => 'O endereço contém uma chave de acesso. Não compartilhe em locais públicos.'],
        ['t' => 'Histórico de envio', 'd' => 'Lista de tudo o que foi enviado e se chegou ao destino.'],
        ['t' => 'Disparo rápido', 'd' => 'Envia uma mensagem de teste ou avulsa para um número.'],
        ['t' => 'Agenda & Contatos', 'd' => 'Cadastro de contatos usado nos envios e na identificação de quem liga.'],
    ]],
    ['id' => 'relatorios', 'titulo' => 'Relatórios & Gravações', 'icon' => 'fa-chart-line', 'itens' => [
        ['t' => 'CDR & Gravações', 'd' => 'Todas as chamadas com filtros por data, número e ramal. Dá para ouvir e baixar a gravação na própria tela.'],
        ['t' => 'Relatório completo de filas', 'd' => 'Atendidas, abandonadas, tempos de espera e de conversa por fila e por atendente.'],
        ['t' => 'Relatórios gráficos', 'd' => 'Gráficos de volume de chamadas por período.'],
        ['t' => 'Relatório geral & mapa de calor', 'd' => 'Mostra os horários de maior movimento.'],
        ['t' => 'Relatórios de IA', 'd' => 'Resumos e análises das ligações geradas por inteligência artificial.'],
    ]],
    ['id' => 'assistente', 'titulo' => 'Assistente inteligente (Chat IA)', 'icon' => 'fa-robot', 'itens' => [
        ['t' => 'Como usar', 'd' => 'Clique no botão do assistente e escreva em linguagem normal, por exemplo: "quantas chamadas perdidas hoje?" ou "quem ligou do número 11 9999-0000?".'],
        ['t' => 'O que ele consulta', 'd' => 'Chamadas, resumo do período, situação dos ramais e das filas, tudo com dados reais da sua central.'],
        ['t' => 'Ouvir gravações', 'd' => 'Peça a gravação de uma chamada e o player aparece dentro do próprio chat, com opção de abrir ou baixar.'],
        ['t' => 'Enviar relatório por WhatsApp', 'd' => 'Peça "envie o resumo de hoje para o número tal". O assistente mostra o que vai enviar e só envia depois da sua confirmação.'],
        ['t' => 'Sugerir recursos nos ramais', 'd' => 'O assistente propõe a alteração e você confirma antes de ser aplicada.'],
        ['t' => 'Histórico e nova conversa', 'd' => 'Sua conversa fica salva. O botão de nova conversa limpa a tela, mas o registro continua guardado para a administração.'],
        ['t' => 'Privacidade', 'd' => 'Os administradores podem consultar as conversas de cada usuário em Configurações > Conversas do Assistente IA. Cada consulta fica registrada no log de ações.'],
    ]],
    ['id' => 'config', 'titulo' => 'Configurações', 'icon' => 'fa-gear', 'itens' => [
        ['t' => 'APIs & Conexões', 'd' => 'Conexão com a central e com o WhatsApp, além das regras de aviso de chamada perdida.'],
        ['t' => 'Inteligência Artificial', 'd' => 'Chave e modelo usados pelo assistente e pelos resumos.'],
        ['t' => 'Usuários & Permissões', 'd' => 'Crie usuários e escolha quais telas e ações cada um pode usar. O botão Importar do painel clássico traz de uma vez todos os usuários já cadastrados lá, com nome, ramal e perfil conforme o grupo.', 'dica' => 'A importação não copia senhas: os usuários entram escolhendo o Painel Novo 6.0 na tela de login. Quem já foi importado não é alterado em uma nova importação.', 'aviso' => 'Cada importado começa com o menor acesso do grupo (administrador, supervisor ou usuário). Ajuste as permissões depois, se precisar.'],
        ['t' => 'Servidor SMTP & Disparos', 'd' => 'Configuração do e-mail usado para enviar relatórios.'],
        ['t' => 'Status da Integração', 'd' => 'Mostra se cada parte do sistema está funcionando.'],
        ['t' => 'Painel de Segurança', 'd' => 'Tentativas de acesso indevido, endereços bloqueados (com opção de desbloquear) e lista de endereços sempre liberados. A lista é a mesma usada pela proteção da central.'],
        ['t' => 'Conversas do Assistente IA', 'd' => 'Para administradores: veja o que cada usuário conversou com o assistente.'],
        ['t' => 'Logs & Diagnósticos', 'd' => 'Registro de quem fez o quê no painel, útil para auditoria e suporte.'],
    ]],
];
