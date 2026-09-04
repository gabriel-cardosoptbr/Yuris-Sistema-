# Processos/Monitor/ — o motor que busca publicações nos diários

Enquanto a pasta de cima guarda **o que** está sendo monitorado, aqui está
**como** a busca acontece: os provedores de cada fonte e os runners que rodam
por cron.

Nada aqui é chamado por uma tela. É tudo disparado por agendamento
(`public/api/` tem os ticks) e por sincronização.

## Arquivos

| Classe | O que faz |
|---|---|
| `ProviderInterface.php` | o contrato que toda fonte tem que cumprir. Fonte nova entra por aqui |
| `AaspProvider.php` | API de Intimações da AASP. É a fonte principal, e a mais completa: 15 métodos |
| `DjenProvider.php` | Diário de Justiça Eletrônico Nacional |

**A OAB manda, e o nome fica de fora quando ela existe.** A OAB identifica o
advogado unicamente; o nome só traz risco. Um caso real de 04/09/2026: uma busca
saiu com a OAB vazia e o nome curto do usuário ("Maria Fernanda"), e a DJEN
devolveu **1.300 publicações de 82 advogadas homônimas**, que entraram no cache
do escritório. Com a OAB, a mesma janela devolve 7. Pior ainda seria o inverso:
a mesma advogada aparece na DJEN ora como `ROMAO`, ora `ROMÃO`, então filtrar
por nome junto com a OAB arrisca **esconder** publicação dela, que é prazo
perdido. Travado em `../../../scripts/tests/djen_filtros_test.php`.
| `PublicationHasher.php` | hash canônico por publicação. **É o que impede duplicata** |
| `AaspSyncRunner.php` | processa as integrações AASP cujo prazo de sincronização venceu |
| `PushMonitorRunner.php` | processa os monitoramentos vencidos, chamando o provider certo |

## Como adicionar uma fonte nova

1. Implemente `ProviderInterface`.
2. Devolva a publicação no formato que o `PublicationHasher` espera, ou o
   deduplicador não funciona para a fonte nova.
3. Registre a fonte onde o `PushMonitorRunner` escolhe o provider.
4. Considere a cota: fonte nova pode mudar o custo do add-on
   (`../MonitorQuota.php`).

## Regras

**O hash é o contrato de estabilidade.** Mudar a montagem do hash faz toda
publicação já vista voltar a parecer nova. Se precisar mesmo mudar, o certo é
versionar o hash e migrar, nunca trocar em cima.

**Runner tem que ser idempotente.** Ele roda por cron e pode rodar duas vezes
sobre a mesma janela, por atraso ou por reexecução. Rodar de novo não pode
gerar evento duplicado nem notificar o cliente outra vez.

**Credencial da AASP é do cliente e fica cifrada.** Passa por
`../../Core/Crypto.php`. Nunca logue o valor, nem em log de erro.
