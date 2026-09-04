# 🗺️ Mapeamento Visual & Arquitetural do IPBX Issabel 6.0

Este diretório contém os artefatos de visualização arquitetural e topológica do projeto **IPBX Issabel 6.0 (Prisma Telecom)**, criados com foco em **redução extrema de consumo de tokens para IAs** e **visibilidade de alto impacto ("Efeito WOW") para humanos**.

---

## 1. 🌟 Archify (`ipbx_architecture.html`)
O **Archify** compila uma especificação declarativa estruturada em JSON ([`ipbx_architecture.json`](./ipbx_architecture.json)) em um aplicativo HTML independente e interativo ([`ipbx_architecture.html`](./ipbx_architecture.html)).

### Principais Recursos
* **Visual Dark Glassmorphism:** Totalmente alinhado à identidade visual escura do projeto.
* **Vistas Guiadas (Guided Views):**
  * `Fluxo de Voz e Gravacao`: Exibe o percurso da chamada SIP do ramal até a gravação e gravação no CDR.
  * `Gestao Web e Relatorios`: Mostra as conexões do Apache, módulos PHP, bancos MySQL/SQLite e player nativo `<dialog>`.
  * `Provisionamento e LDAP`: Mapeia a conexão GDMS -> `issabel-ldap` (porta 10389, `Prisma@500`) -> ramais SQLite.
* **Inspeção de Nós & Rotas:** Clique em qualquer componente para inspecionar dependências upstream/downstream.
* **Economia de Tokens:** Qualquer IA pode carregar apenas o arquivo [`ipbx_architecture.json`](./ipbx_architecture.json) (~200 tokens) para entender toda a arquitetura antes de realizar qualquer alteração, sem precisar varrer milhares de linhas de código.

### Como Visualizar:
Basta abrir o arquivo no seu navegador:
* Caminho direto: `docs/architecture/ipbx_architecture.html`

---

## 2. 🏙️ mindwalk (Topologia 3D do Código)
O **mindwalk** desenha o repositório como uma cidade 3D ("night map"), onde cada pasta é um quarteirão e a altura dos prédios representa o volume de linhas de código de cada módulo.

### Como Rodar no Windows:
O executável já está configurado em `scratch/mindwalk/mindwalk.exe`.

Para abrir o visualizador 3D:
```powershell
.\scratch\mindwalk\mindwalk.exe map .
```
O servidor iniciará localmente e abrirá a interface interativa 3D no navegador (ex: `http://127.0.0.1:9999`).

### Como Re-gerar o mapa em JSON:
```powershell
.\scratch\mindwalk\mindwalk.exe build . -o scratch/mindwalk/citymap.json
```
