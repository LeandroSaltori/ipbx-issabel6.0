# IPbx Prisma — IA de ligações, histórico/reanálise e exportações

Código em `src/front_api/pabx_panel` (`includes/functions.php`, `index.php`, `modules/relatorios/*`). Regra geral: **toda função de IA salva o que gerou, reaproveita ao reabrir e tem botão para refazer**; tudo opera em **português** e usa o **período do filtro** da tela.

## 1. Chaves e funções de IA
- Uma chave por provedor, escolhida por **função** (`audio` = transcrição, `insights` = análises de relatório, `chat` = assistente): `aiKeyFor()`, `aiRoleChoice()`, `aiGetConfig($role)`. Tela: Configurações > Inteligência Artificial.
- `aiAnyKeyConfigured()`: verdadeiro se existe **qualquer** chave válida (nenhuma tela deve checar só a chave antiga `ai_api_key`). Com uma única chave, ela é a padrão de todas as funções.
- Teste de conexão: `test_llm_connection` (usa `aiKeyFor`).

## 2. Transcrição de gravação (`transcribe_audio`, `aiTranscribeFile`)
1. Áudio de telefone (8 kHz) é cortado **nos silêncios** (`aiAudioCutPoints` + ffmpeg `-f segment -segment_times`), em partes curtas, para o Whisper não entrar em repetição ("027027…").
2. Cada parte vai ao Whisper (OpenAI/Groq) em `verbose_json`, **sem prompt** e fixa em português; `aiWhisperRequest` descarta segmentos de alucinação (`compression_ratio`, `no_speech_prob`, `avg_logprob`, filtro relaxado para não perder fala real).
3. Limites: arquivo até 200 MB, `set_time_limit(1800)`. Retorna texto, partes, descartes, segmentos e duração.
4. `aiTranscriptQualityNote`: se a transcrição for ruim (repetição de trigramas, poucos segmentos), o resumo começa com "ATENÇÃO" avisando que o áudio é de baixa qualidade, em vez de inventar.
5. **Histórico:** tabela SQLite `ai_transcripts` (criada sob demanda em `aiTranscriptCachePut`); reabrir a mesma gravação lê do cache.

## 3. Análise/auditoria de ligação (`analyze_call_audio`, `aiAnalyzeCallTranscript`)
- Prompt estruturado em JSON com exemplo (few-shot): motivo do contato, o que foi dito (valores, equipamentos, datas, nomes), solução do atendente, resultado, pendências, satisfação do cliente. **Nunca genérico**; sem dado, "Sem avaliação".
- Ligações longas: análise por partes (map-reduce) para nada ficar de fora.
- **Histórico:** tabela `call_ai_analysis` (coluna `analysis_ver`). A análise salva é reaproveitada quando `analysis_ver >= AI_CALL_ANALYSIS_VER` (constante em `functions.php`, hoje 3) e não há `force`. **Ao melhorar o prompt, subir a constante**: análises antigas são refeitas sozinhas na próxima abertura (marcadas `stale`).
- Gravação: `aiAuditSaveAnalysis` com `busy_timeout` e novas tentativas (escritas concorrentes no SQLite); a resposta traz `saved`/`save_error` e a tela avisa se não salvou.
- **Refazer:** botões **Reanalisar** (CDR & Gravações) e **Reauditar** (Auditoria de IA); `force=1` ignora o cache.
- O resumo do WhatsApp (`send_ai_summary`) usa `aiCallSummaryCached` (a análise salva).
- Log: `logUserAction` com o usuário real logado.

## 4. Análises de relatório (`analyze_pabx_insights`) e Dashboard
- Cache por assinatura (período + números) em `ai_report_cache` (`aiReportCacheEnsure/Get/Put`); histórico no Dashboard em `ai_insights_history`. Mudou período ou números → nova análise sozinha. Clicar de novo em **Analisar com IA** refaz (wrapper global de `fetch` em `index.php` envia `force`). Rótulo: "análise salva em …".

## 5. Exportações (CSV, Excel, PDF)
Regra: **trazem exatamente o período e os filtros da tela** (30 dias = 30 dias), com cabeçalho de período e usuário logado.
| Peça | Onde | Observação |
|---|---|---|
| `ipbxCsv` (JS, `index.php`) | Todas as telas com CSV | Gera o CSV no navegador com BOM/UTF-8 |
| `shareBuildXlsx` | Excel | Largura de colunas, cabeçalho fixo, filtro automático |
| `shareBuildPdf` | PDF de tabela | Texto longo quebra em linhas (`$wrap`), usuário no cabeçalho |
| `shareBuildDocPdf` | PDF de documento | Auditoria IA e relatório do atendente (cliente, atendente, data, resultado, resumo, checklist, transcrição) |
| `reportExportSend` | Envio/baixa | Escolhe o formato |
- Ações no servidor: `ia_audit_export`, `operator_ia_pdf`, `ia_audit_list_pdf` (período por preset ou `date_start`/`date_end`), `report_pdf` (declaradas em `$__api_action_names`). PDFs são **gerados no servidor** (a captura de tela por html2pdf saía em branco).
- CDR & Gravações: tetos por formato (CSV 1.000.000, Excel 200.000, PDF 20.000 linhas); acima disso o arquivo traz as mais recentes e avisa.
- Relatório de Filas: botões CSV/Excel/PDF usam a consulta filtrada (`$_GET`), não só a página.

## 6. Como testar uma mudança nesta área
1. Abrir a ligação, analisar, fechar e reabrir: deve vir da base, sem nova chamada à IA.
2. Clicar **Reanalisar/Reauditar**: deve gerar de novo e salvar (`saved: true`).
3. Baixar o PDF da auditoria logo após analisar: não pode dar 404 "ainda não foi analisada".
4. Exportar com 30 e com 100 dias: conferir primeira/última data e contagem.
