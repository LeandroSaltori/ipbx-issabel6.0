# Reference Guide: Z-PRO API & Prismabot Integration (v4.x)

Este documento contém a referência completa da API Z-PRO / ZDG / Prismabot para consulta permanente no projeto **IPbx Prisma**.

---

## 🔑 Autenticação & Cabeçalhos

Todas as requisições para a API do Z-PRO requerem o token Bearer no cabeçalho HTTP:

```http
Authorization: Bearer SEU_TOKEN_API
Content-Type: application/json
```

---

## 📤 Endpoints Principais de Mensagens & Envio

### 1. Envio de Mensagem Simples de Texto (Standard & v2)
* **Endpoint**: `POST /api/messages/send` ou `POST /v2/api/external/{ApiID}/messages/send`
* **Payload**:
```json
{
  "number": "5516991637028",
  "body": "Olá! Vimos que você tentou ligar no nosso atendimento às 10:30.",
  "externalKey": "PRISMA_1787043800",
  "isClosed": false,
  "validateNumber": true,
  "options": {
    "delay": 1200
  }
}
```

---

### 2. Envio de Mídia / Gravação de Áudio PABX (Audio / Media)
* **Endpoint**: `POST /api/messages/send/media` ou `POST /v2/api/external/{ApiID}/messages/send/media`
* **Campos**:
```json
{
  "number": "5516991637028",
  "mediaUrl": "https://pabx.empresa.com.br/monitor/rec_123.mp3",
  "body": "🎙️ Gravação da sua chamada realizada em 18/08/2026",
  "mediaType": "audio"
}
```

---

### 3. Mensagens Interativas com Botões (Baileys / UazAPI)
* **Endpoint**: `POST /v2/api/external/{ApiID}/interactive/button`
* **Payload**:
```json
{
  "number": "5516991637028",
  "title": "Pesquisa de Satisfação NPS",
  "body": "Como você avalia nosso atendimento no ramal 208?",
  "footer": "IPbx Prisma Telecom",
  "buttons": [
    { "buttonId": "nps_5", "buttonText": { "displayText": "⭐⭐⭐⭐⭐ Excelente" } },
    { "buttonId": "nps_3", "buttonText": { "displayText": "⭐⭐⭐ Regular" } },
    { "buttonId": "nps_1", "buttonText": { "displayText": "⭐ Ruim" } }
  ]
}
```

---

### 4. Gestão de Tickets & Filas (CRM / Atendimento Humanizado)
* **Criar Ticket**: `POST /v2/api/external/{ApiID}/ticket/create`
* **Transferir Fila**: `POST /v2/api/external/{ApiID}/ticket/transfer/{ticketId}`
* **Fechar Ticket**: `POST /v2/api/external/{ApiID}/ticket/close/{ticketId}`

---

## 🔗 Links Oficiais de Documentação

1. **Repositório OpenAPI Specs**: [github.com/alexebex/zpro-api-reference](https://raw.githubusercontent.com/alexebex/zpro-api-reference/main/openapi.yaml)
2. **Central de Ajuda ZDG**: [ajuda.zdg.com.br/central-do-assinante/referencia-da-api](https://ajuda.zdg.com.br/central-do-assinante/referencia-da-api)
