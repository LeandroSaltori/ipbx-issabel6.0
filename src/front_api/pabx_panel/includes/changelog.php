<?php
/**
 * Histórico de novidades do painel (mostrado ao cliente em "Novidades").
 * Atualizado a cada entrega (regra 10 do AGENTS.md): acrescente um item em 'done' (a mais recente primeiro), com 'data' AAAA-MM-DD.
 * 'plan' lista o que está em andamento / próximos passos.
 */
return [
    'done' => [
        ['data' => '2026-10-11', 'mes' => 'Outubro/2026', 'novo' => true, 'icon' => 'fa-users-gear', 'titulo' => 'Ramais e Atendentes em um só lugar', 'desc' => 'Nomes, permissões, Não Perturbe e Desvios agora ficam na mesma tela, em abas. O menu também ficou mais limpo: Novidades, modo claro e perfil rolam junto com ele.'],
        ['data' => '2026-10-11', 'mes' => 'Outubro/2026', 'novo' => true, 'icon' => 'fa-paper-plane', 'titulo' => 'Relatório por WhatsApp pelo Chat IA', 'desc' => 'Peça no chat "envie o resumo de hoje para o número tal" e o relatório de chamadas chega por WhatsApp, só para quem tem permissão de envio.'],
        ['data' => '2026-10-11', 'mes' => 'Outubro/2026', 'novo' => true, 'icon' => 'fa-clipboard-list', 'titulo' => 'Formulários de atendimento', 'desc' => 'O atendente registra o motivo e o resultado de cada chamada, com listas que você mesmo personaliza e consulta por período.'],
        ['data' => '2026-10-11', 'mes' => 'Outubro/2026', 'novo' => true, 'icon' => 'fa-shield-halved', 'titulo' => 'Painel de segurança', 'desc' => 'Veja as tentativas de acesso indevido e os endereços bloqueados, libere um IP e mantenha uma lista de IPs confiáveis, sincronizada com o Fail2ban do Issabel.'],
        ['data' => '2026-10-11', 'mes' => 'Outubro/2026', 'novo' => true, 'icon' => 'fa-broom', 'titulo' => 'Painel mais enxuto e rápido', 'desc' => 'Renomear ramal agora grava direto no Issabel, sem depender da REST API (que virou opcional); botões que não funcionavam foram retirados e o painel deixou de aguardar uma API que não responde.'],
        ['data' => '2026-10-11', 'mes' => 'Outubro/2026', 'novo' => true, 'icon' => 'fa-heart-pulse', 'titulo' => 'Status da Integração', 'desc' => 'Uma tela mostra se o Asterisk, os bancos, o WhatsApp, a IA, o e-mail e as tarefas automáticas estão funcionando, com o motivo quando algo falha. Fica em Configurações.'],
        ['data' => '2026-10-11', 'mes' => 'Outubro/2026', 'novo' => true, 'icon' => 'fa-envelope-circle-check', 'titulo' => 'Avisos de abandono e resumo sem repetição', 'desc' => 'Aviso de abandono de fila e resumo da chamada passam a ser enviados uma única vez; só tentam de novo (até 3 vezes) se o envio falhar.'],
        ['data' => '2026-10-11', 'mes' => 'Outubro/2026', 'novo' => true, 'icon' => 'fa-triangle-exclamation', 'titulo' => 'Alerta de tronco fora do ar', 'desc' => 'O supervisor recebe um WhatsApp assim que uma linha cai e outro quando ela volta, uma única vez cada, com intervalo mínimo de 15 min por linha. Fica desligado até você ativar em Configurações > API.'],
        ['data' => '2026-10-11', 'mes' => 'Outubro/2026', 'novo' => true, 'icon' => 'fa-rocket', 'titulo' => 'Novidades do sistema', 'desc' => 'Este histórico: todas as funções novas e correções passam a aparecer aqui automaticamente, no rodapé do menu.'],
        ['mes' => 'Outubro/2026', 'novo' => true, 'icon' => 'fa-robot', 'titulo' => 'Chat com IA no painel', 'desc' => 'Assistente flutuante que responde sobre ligações, ramais, filas e gravações usando os dados reais do seu PABX. Liberado por usuário.'],
        ['mes' => 'Outubro/2026', 'novo' => true, 'icon' => 'fa-diagram-project', 'titulo' => 'IA por função', 'desc' => 'Escolha qual IA faz a transcrição de áudio, os resumos/insights e o chat, com chave própria por provedor.'],
        ['mes' => 'Outubro/2026', 'novo' => true, 'icon' => 'fa-pause-circle', 'titulo' => 'Pausas & Tempo Logado dos agentes', 'desc' => 'Relatório com tempo logado, tempo em pausa, motivos e detalhe de cada pausa.'],
        ['mes' => 'Outubro/2026', 'novo' => true, 'icon' => 'fa-user-gear', 'titulo' => 'Autoatendimento do ramal', 'desc' => 'Não Perturbe e desvios de chamada por ramal, sincronizados com a interface nativa do Issabel.'],
        ['mes' => 'Outubro/2026', 'novo' => true, 'icon' => 'fa-display', 'titulo' => 'FOP em tempo real', 'desc' => 'Status ao vivo dos ramais (chamando, em conversa, com quem), ramais sem registro esmaecidos, escuta/sussurro/intervenção, desligar chamada e pausa de agentes nas filas.'],
        ['mes' => 'Outubro/2026', 'novo' => false, 'icon' => 'fa-whatsapp fa-brands', 'titulo' => 'WhatsApp: Prismabot ou uazapi', 'desc' => 'Dois servidores de envio à escolha. Histórico de envios e erros por ramal.'],
        ['mes' => 'Outubro/2026', 'novo' => false, 'icon' => 'fa-file-export', 'titulo' => 'Relatórios com anexo e exportação completa', 'desc' => 'Envio por WhatsApp/e-mail com Excel, PDF ou CSV; exportação sempre do período inteiro; CDR com paginação 20/50/100.'],
        ['mes' => 'Outubro/2026', 'novo' => false, 'icon' => 'fa-bell', 'titulo' => 'Chamada perdida avisada uma única vez', 'desc' => 'O WhatsApp de chamada perdida é enviado uma vez e só repete se o envio falhar.'],
    ],
    'plan' => [
    ],
];
