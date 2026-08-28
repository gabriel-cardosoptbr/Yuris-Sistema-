# Billing/ — planos, limites e cobrança

A página pública de planos é `public/planos.php`, mas o efeito deste módulo não
está numa tela só: ele aparece **espalhado pelo sistema**, toda vez que alguém
tenta criar algo além do que o plano permite.

O nome da pasta é `Billing` e não `Planos` porque é o termo já usado no banco,
na variável de ambiente `BILLING_GATEWAY` e no resto do código. Na interface, o
cliente lê "Planos".

## Arquivos

| Classe | O que faz |
|---|---|
| `PlanFeature.php` | os limites e módulos de cada plano, e o enforcement deles. 13 métodos |
| `BillingGuard.php` | a checagem prática: pode criar mais um usuário, mais um processo, mais um monitoramento? 10 métodos |
| [`Gateway/`](Gateway/) | integração com gateway de pagamento |

## Duas travas diferentes, não confunda

- **Limite de plano** (aqui): quantos usuários, quantos processos, quantos
  monitoramentos. Vive em `PlanFeature` e `BillingGuard`.
- **Módulo habilitado por conta**: `account.features`, em
  `../Master/Account.php`. É o super admin ligando ou desligando um módulo
  inteiro para aquele cliente, independente do plano.

Uma conta pode estar num plano que inclui um módulo e mesmo assim tê-lo
desligado, ou o contrário. As duas travas coexistem de propósito.

## Regras

**Enforcement é *fail-open*.** Na dúvida, libera. É decisão consciente: um bug
na checagem não pode impedir um escritório pagante de trabalhar. O preço é que
uma checagem esquecida não aparece como erro, só como limite que não pegou, e
por isso limite novo precisa de teste.

**Checar antes de criar.** Verificar depois já deixou o cliente acima do plano.

**Cobrança não é o mesmo que limite.** Mudar plano no gateway não solta o limite
sozinho: quem manda no que o cliente pode fazer é a conta, no banco.

## Testes

`../../scripts/tests/plan_feature_test.php` e
`../../scripts/tests/plan_gate_e2e_test.php`. Rode os dois ao mexer em plano.

As duas fecham em verde (`plan_feature` 79 ok · 0 falha, `plan_gate_e2e` 25 ok ·
0 falha). Qualquer falha é regressão.

O `plan_feature` também guarda uma decisão de produto: **a página pública não
mostra valor**, o preço é tratado por consulta. Ele confere que nenhum `R$` e
nenhum valor da grade aparece em `planos.php`, e que o JSON-LD não tem
`offers`/`price`, para o Google não anunciar um preço que a página não mostra.
Se você reintroduzir preço na página, é lá que vai apitar.
