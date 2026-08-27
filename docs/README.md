# docs/ — documentação do Yuris

Documento de referência fica aqui. Documento que explica **uma pasta de código**
fica dentro da própria pasta, como `README.md`, para não descolar do código que
descreve.

## Visão geral do sistema

| Arquivo | O que traz |
|---|---|
| `ARCHITECTURE.md` | como o sistema é montado |
| `FEATURES.md` | funcionalidades e casos de uso |
| `PAGES.md` | as páginas, públicas e privadas, e o que cada uma faz |
| `API.md` | os endpoints e seus comportamentos |
| `MULTITENANCY.md` | como o isolamento entre escritórios funciona |
| `DATABASE_SETUP.md` | subir o banco do zero |
| `ENVIRONMENT.md` | as variáveis de ambiente |
| `TAREFAS_SPEC.md` | especificação do módulo de Tarefas |

## Pastas

| Pasta | O que guarda |
|---|---|
| `deploy/` | subir para produção: checklist, AWS/Ubuntu, deploy do agente, rollback |
| `integracao/` | AASP, agente de IA, contrato dos webhooks de saída, prompt v3 |
| `lgpd/` | políticas, procedimentos, RAT, RIPD, modelos de notificação (ANPD e titular), DPA, NDA, inventário de operadores |
| `auditorias/` | auditorias e registros de execução de fases, por data |
| `produto/` | material de apresentação e propostas comerciais |
| `design/` | mockups, incluindo os e-mails de convite (`design/emails/`) |
| `seo/` | material das páginas de SEO e da camada de URL limpa |

## Onde procurar quando

- **"como isso funciona no código?"** → o `README.md` da pasta em
  [`../app/`](../app/), não aqui
- **"por que isso está assim?"** → `auditorias/`, que guarda o motivo das
  decisões
- **"como eu subo?"** → `deploy/`
- **"o que eu respondo para a ANPD / para o cliente?"** → `lgpd/`

## Ao escrever documento novo

Coloque **a data no nome** quando for registro de um momento (auditoria,
execução de fase). Documento de referência não leva data, porque é para ser
atualizado, não acumulado.

Um erro que já aconteceu aqui: o `CLAUDE.md` da raiz era o guia de implementação
de uma feature concluída, mas ficava no lugar do guia geral do projeto e
continuou sendo lido como se fosse atual, quando já falava de uma migration 027
num sistema que passou de 110. Ele virou `auditorias/`. Registro de momento
envelhece; referência tem que envelhecer junto com o código.
