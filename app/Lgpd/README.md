# Lgpd/ — direitos do titular, anonimização e documentos legais

Telas: **LGPD** (`public/lgpd.php`), **DPO** (`public/dpo.php`), **Termos**
(`public/termos.php`), **Privacidade** (`public/privacidade.php`), **Cookies**
(`public/cookies.php`).

Esta pasta tem uma característica que nenhuma outra tem: **o que está aqui é
obrigação legal, com prazo**. Uma solicitação de titular tem prazo de resposta,
um incidente tem prazo de notificação. Quando algo aqui falha, o efeito não é
uma tela quebrada, é exposição do escritório perante a ANPD.

O material de apoio (políticas, RIPD, RAT, modelos de notificação) está em
[`../../docs/lgpd/`](../../docs/lgpd/).

## Arquivos

### Solicitação do titular (Art. 18)
| Classe | O que faz |
|---|---|
| `LgpdRequest.php` | a solicitação: acesso, correção, exclusão, portabilidade |
| `LgpdRequestModule.php` | registra **quais módulos já foram pesquisados** naquela solicitação |
| `LgpdRequestFinding.php` | cada dado pessoal encontrado na busca |
| `LgpdRequestAttachment.php` | anexos da solicitação |
| `LgpdRequestRetentionJustification.php` | justificativa estruturada quando um dado **não** pode ser apagado (guarda legal, por exemplo) |

### Tratamento de dado
| Classe | O que faz |
|---|---|
| `Anonymizer.php` | substitui dado pessoal por placeholder **preservando as chaves estrangeiras**. 29 métodos, é o arquivo mais delicado da pasta |
| `PIIMasker.php` | mascaramento para exibir achados na tela sem expor o dado inteiro |
| `PayloadMasker.php` | mascaramento de payload de webhook antes de sair do Yuris |

### Governança
| Classe | O que faz |
|---|---|
| `DataProcessor.php` | inventário de operadores, os terceiros que tratam dado em nome da Yuris (Art. 5 VII, 33 e 39) |
| `PendingReview.php` | itens que precisam de revisão do DPO antes do go-live. Uso interno de super admin e DPO |
| `LegalDocument.php` | versionamento de termos e políticas. O aceite de cada versão fica em `../Usuarios/TermAcceptance.php` |

## Por que anonimizar não é apagar

`Anonymizer` troca o dado pessoal por placeholder e **mantém o registro e suas
relações**. Apagar a linha quebraria o histórico do processo, o financeiro e a
auditoria, que precisam continuar existindo por outras obrigações legais.

Por isso o direito de exclusão nem sempre é exclusão física, e por isso existe
`LgpdRequestRetentionJustification`: quando o dado fica, a razão tem que estar
registrada.

## Regras

**Exclusão sem justificativa registrada é problema.** Se algo não pode ser
apagado, a justificativa é estruturada, não um comentário no chamado.

**Busca de titular percorre todos os módulos.** `LgpdRequestModule` existe para
provar quais já foram varridos. Módulo novo no sistema precisa entrar nessa
varredura, ou a resposta ao titular fica incompleta sem ninguém perceber.

**Achado nasce mascarado.** Nunca exiba o dado cru na tela de uma solicitação.

**Documento legal é versionado, nunca editado em cima.** Alterar uma versão já
aceita invalida os aceites: quem aceitou passa a constar como tendo aceitado um
texto que nunca leu.

**Incidente tem prazo.** O registro é em `../Master/SecurityIncident.php`, e o
procedimento está em
[`../../docs/lgpd/PROCEDIMENTO_INCIDENTES.md`](../../docs/lgpd/PROCEDIMENTO_INCIDENTES.md).
