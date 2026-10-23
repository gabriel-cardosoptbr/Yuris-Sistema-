# app/FollowUp: follow-up automático do WhatsApp

**Onde aparece:** Operação › Follow-up (`public/followup.php`, só edição CRM) e
o relógio no card do funil da Prospecção.

O sistema manda sozinho as mensagens de follow-up quando o lead fica em
silêncio, pela mesma (e única) instância da Evolution do número da conversa. A
conta controla tudo na tela: quando cada cadência começa, em quais etapas do
funil ela vale, com quem, o horário de envio, os textos e a espera entre um e
outro. O card mostra quando sai a próxima mensagem.

Até 23/10/2026 o follow-up da Fleetiflow morava num fluxo do n8n
(`pY0tfwPbHlfUt5al`), com textos e prazos escritos no código do fluxo e só para
quem nunca respondeu. O pedido foi controlar isso no sistema e criar também o
follow-up de quem respondeu e sumiu (a Ouro Verde respondeu em 07/10, a conversa
ficou com uma pessoa e ninguém mais cutucou).

## Arquivos

| Arquivo | O que faz |
|---|---|
| `Regra.php` | **a conta do próximo follow-up, PURA (sem banco)**. Horário de envio (`proximaJanela`, fuso de Brasília), rajadas, a situação da conversa numa cadência (`situacao`: agendado com data e hora, encerrada ou fora, com o motivo), a escolha da cadência (`escolher`: a primeira ativa em que a conversa cabe) e o texto com `{nome}` e `{empresa}`. O card, a tela e o envio usam esta mesma conta |
| `Cadencia.php` | as cadências da conta (tabela `followup_cadencias`): ler, validar o que vem da tela, salvar, ligar/desligar, excluir, ordenar. Nasce desligada. `cobreSemResposta()` diz se uma cadência ativa já cuida de quem não respondeu (aí o n8n sai, ver abaixo) |
| `Fatos.php` | lê do WhatsApp o que a regra precisa de cada card, em poucas consultas para a lista inteira: a última mensagem do lead, as nossas desde então, por qual número mandar e se a conversa está com o robô de pré-venda. Soma as conversas ligadas ao card **e as @lid do mesmo contato** (`whatsapp_identidades`). `paraOFunil()` é o que o card recebe em `api/cards.php` |
| `Envio.php` | o envio (chamado por `bin/followup_worker.php` a cada minuto) e a fila da tela (`agendados`), mais o histórico |

## A regra, em uma tela

- **Ciclo** = o silêncio do lead: começa na primeira mensagem nossa depois da
  última dele (ou na primeira de todas, se ele nunca escreveu).
- As nossas mensagens do ciclo viram **rajadas** (até 10 min entre uma e outra é
  a mesma rajada, porque a abertura e a Vitória quebram a fala em balões). A 1ª
  rajada é a mensagem que espera resposta; cada rajada a mais é um follow-up já
  dado, **nosso ou de uma pessoa**. O próximo passo é o número de rajadas.
  Contar pelas mensagens, e não por um registro nosso, fez a troca do n8n para
  cá continuar do passo em que cada lead estava.
- A espera de cada passo conta da **nossa última mensagem** e cai no próximo
  momento dentro do horário de envio da cadência.
- **Gatilhos:** `sem_resposta` (o lead nunca escreveu), `parou` (escreveu alguma
  vez e a última palavra é nossa), `qualquer`. Se a última palavra é do lead,
  nenhuma cadência manda: é a vez de alguém responder a ele.
- **Começo:** o passo 1 não sai se a nossa mensagem tiver mais de
  `inicio_max_dias` (padrão 3). Evita mandar para conversa antiga quando a
  cadência é ligada. Quem já está no passo 2 em diante segue.
- **Com quem:** todas as conversas, só com o robô (agente do número ligado e
  conversa não assumida) ou só com uma pessoa.
- Espera mínima de um passo: 30 min. No máximo 10 passos.

## O envio

- A chave geral é a mesma do botão "Follow-up" do Chat
  (`AutomacaoSdr`, `sdr_followup_ligado`): desligada, nenhuma cadência manda.
- **Um envio por número por rodada, e de 5 a 10 min entre um e outro** no mesmo
  número (o número 1 da Fleet caiu em 05/10/2026 com envio em rajada).
- Logo antes de mandar, relê a conversa e refaz a conta: se o lead respondeu um
  segundo antes, não manda. Número fora do ar: o lead espera ele voltar.
- A linha de `followup_envios` nasce `enviando` **antes** do envio, com chave
  única (conversa, cadência, início do ciclo, passo): dois workers juntos nunca
  mandam o mesmo passo duas vezes. Falha volta a tentar até 3 vezes, de 20 em 20
  min, só se aquele passo ainda for o da vez.
- Depois de mandar: grava a mensagem na conversa (o card enxerga na hora), move
  o card para a etapa escolhida no primeiro envio (só se ele estiver numa etapa
  da própria cadência) e registra na linha do tempo do lead. **Não pausa o robô**
  (é envio automático, não uma pessoa assumindo).

## O n8n

Enquanto houver cadência ativa de `sem_resposta` ou `qualquer`,
`api/whatsapp/sdr_automacao.php` responde `followup: false` ao n8n
(`AutomacaoSdr::paraOn8n`), e o fluxo antigo para de mandar sozinho. O lead
nunca recebe os dois. Desligar a cadência na tela devolve o follow-up ao n8n.
O disparo da abertura continua no n8n.

A Fleetiflow foi trazida com `scripts/manutencao/followup_da_fleet.php`.

## Teste

`scripts/tests/followup_test.php`: a regra sem banco e o caminho inteiro na
conta 304 local com envio de mentira (precisa da migration 168).
