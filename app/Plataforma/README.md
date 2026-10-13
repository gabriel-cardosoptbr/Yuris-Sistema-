# app/Plataforma — o CRM ligado à plataforma Fleetiflow (edição CRM)

A plataforma Fleetiflow (o SaaS dos despachantes, outro repositório) e este CRM
conversam **só por API**: o CRM lê cada conta e pede mudanças por
`/api/integracao/v1` da plataforma, e a plataforma conta o que mudou por eventos
assinados. Ninguém lê o banco do outro. O contrato inteiro está em
`docs/integracao-comercial.md`, no repositório da plataforma.

Nasceu em 13/10/2026: o cliente da plataforma "conversa com o card do CRM", o
vendedor vê como está o cliente (plano, módulos, uso, quanto gera) para fazer
upsell, e tudo liga por um código único. Conta jurídica (Yuris) não tem nada disto.

## Os dois códigos

- **Código de conexão** (`FFC1.…`): sai do Painel Master da plataforma e é colado
  uma vez em **Operação › Plataforma**. Leva o endereço da API e a chave `ffc_`.
- **Código da conta** (`FLT-XXXX-XXXX`): o identificador que o cliente vê na tela
  "Conta" da plataforma. É colado na ficha do lead, em "Conta na plataforma".

## Arquivos

| Classe | O que faz |
|---|---|
| `Conexao.php` | abre o código de conexão, liga (`GET /conexao` confere a chave, `PUT /conexao` registra o endereço dos eventos e recebe o segredo), guarda chave e segredo cifrados (`App\Core\Crypto`) em `plataforma_conexoes` e é o ÚNICO lugar que chama a API (`Conexao::api`) |
| `Vinculo.php` | liga o lead à conta pelo código (`plataforma_vinculos`), conta à plataforma quem é o responsável (o "seu consultor" do cliente), lê o resumo da conta e guarda o último para a carteira e para quando a plataforma estiver fora do ar, sugere contas pelo CNPJ e pelo nome do lead |
| `Eventos.php` | recebe os eventos: confere a assinatura e o timestamp, guarda uma vez por id (`plataforma_eventos`), escreve a nota no histórico do lead e avisa o responsável quando é pedido da conta |

Tela e API: `public/plataforma.php` + `assets/ff-plataforma.js` (ligação e
carteira), `assets/ff-plataforma-lead.js` (seção da ficha do lead, carregada pelo
`sidebar.php`), `public/api/plataforma.php` (com sessão) e
`public/api/plataforma_eventos.php` (máquina a máquina, assinatura HMAC).

## Regras

- **Quem liga o CRM à plataforma**: só dono e administrador.
- **Quem muda a conta do cliente** (liberar módulo, trocar plano, responder pedido):
  administrador ou o responsável pelo lead, e só se a conexão tiver o escopo
  `comercial`. O nome de quem fez vai para a auditoria da conta, lá na plataforma.
- **Vendedor** (não dono, não admin) vê e mexe só nos leads de que é responsável,
  na ficha e na carteira, como no resto da edição CRM.
- Um lead, uma conta; uma conta, um lead. Ligar a outra desliga a anterior.
- Evento de conta sem vínculo liga sozinho ao lead com o mesmo CNPJ, se houver
  um só. Sem lead, o evento fica guardado.
- Evento recebido marca o resumo guardado como antigo; a próxima abertura da
  ficha busca de novo. Resumo com menos de 2 minutos não é buscado de novo.
- A chave nunca é logada, nunca vai para a tela nem em URL. Erro de banco nunca
  vai para a tela (`PDOException` é `RuntimeException`: o endpoint a trata antes).
- O endereço dos eventos é a origem pública desta página no momento de ligar, ou
  `PLATAFORMA_CRM_URL` do `.env`, se preenchida.

## Testes

`scripts/tests/plataforma_crm_test.php`: código de conexão, assinatura, textos,
idempotência, vínculo, nota e aviso. Não chama a plataforma de verdade. Como os
outros testes com banco, não grava no servidor de produção (Docker).
