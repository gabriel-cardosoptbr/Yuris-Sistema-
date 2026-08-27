# Billing/Gateway/ — integração com gateway de pagamento

Camada fina entre o Yuris e quem processa o pagamento. O objetivo do desenho é
que **nenhum outro lugar do sistema saiba qual gateway está em uso**.

## Arquivos

| Classe | O que faz |
|---|---|
| `GatewayInterface.php` | o contrato: 8 métodos que qualquer gateway precisa cumprir |
| `Gateway.php` | factory que devolve o adapter ativo, decidido pela variável de ambiente `BILLING_GATEWAY` |
| `NullGateway.php` | adapter que não faz nada. É o padrão em desenvolvimento e o fallback |

## Por que existe um gateway que não faz nada

Porque o resto do sistema pode chamar cobrança sem se importar se há gateway
configurado. Em desenvolvimento, `BILLING_GATEWAY` fica vazio ou em `null`, o
`NullGateway` responde, e ninguém precisa de credencial real para rodar o Yuris
na máquina.

Isso também significa que **em dev a cobrança silenciosamente não acontece**. Ao
testar fluxo de pagamento, confirme qual adapter está ativo antes de concluir
que funcionou.

## Ao adicionar um gateway de verdade

1. Implemente `GatewayInterface` inteiro. Método que ficar sem implementação
   vira falha em produção, não em dev, porque em dev o `NullGateway` cobre.
2. Registre no `Gateway.php`.
3. Credenciais vêm de `.env` e passam por `../../Core/Crypto.php` se ficarem no
   banco. Nunca em código.
4. Webhook do gateway (pagamento aprovado, assinatura cancelada) é endpoint em
   `../../../public/api/`, e precisa **validar assinatura**: qualquer um
   consegue chamar aquela URL.
5. O evento do gateway não muda o que o cliente pode fazer por si só. Quem
   manda no limite é a conta, no banco. Ver [`../README.md`](../README.md).
