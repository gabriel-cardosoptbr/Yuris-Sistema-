# Lgpd/Paginas/ — as duas telas públicas do titular de dados

São páginas web completas, não classes. Moram **fora de `public/`** e só
respondem porque a camada de rota as declara em
[`../../../config/rotas.php`](../../../config/rotas.php).

| Arquivo | Endereço público | O que é |
|---|---|---|
| `solicitar.php` | `/lgpd/solicitar` | formulário de solicitação do titular (LGPD Art. 18) |
| `acompanhar.php` | `/lgpd/acompanhar?token=…` | acompanhamento por token, sem login |
| `centro-privacidade.php` | `/configuracoes/privacidade` | Centro de Privacidade do usuário logado: consentimentos e revogação. **Exige login** |

O nome `centro-privacidade.php` não é o do endereço de propósito: existe também
`public/privacidade.php`, que é a **Política de Privacidade pública**. São
páginas diferentes, e o nome evita confundir uma com a outra. Quem liga o
endereço ao arquivo é [`../../../config/rotas.php`](../../../config/rotas.php).

A tela desativada `/configuracoes/monitoramentos` não tem arquivo: o conteúdo
virou a aba "Monitoramentos" dentro de `/escritorios.php` em 26/05/2026, e o
desvio 301 é declarado direto na tabela de rotas, sem arquivo-carcaça.

## Por que elas saíram de `public/`

Existia `public/lgpd.php` (a página pública "LGPD & Segurança") **e** a pasta
`public/lgpd/` com as duas telas do titular. `public/configuracoes/` fazia o
mesmo com `public/configuracoes.php`. A pasta ganhava do arquivo: o `mod_dir` do
Apache via o diretório primeiro e respondia **301 para `/lgpd/`**, que não tinha
`index.php`, e em produção isso terminava em **403**.

Como `/lgpd.php` é link no rodapé de toda página legal e da landing, e o nginx
converte `.php` para a forma limpa, o visitante percorria:

```
/lgpd.php  →301→  /lgpd  →301→  /lgpd/  →403
```

**Não dá para consertar no `.htaccess`.** O `mod_dir` marca a requisição como
diretório antes do `mod_rewrite` rodar, e nenhuma substituição interna desfaz
isso (testado com `[L]`, `[PT]`, `[DPI]`, `[END]`, alvo relativo e absoluto, e
mandando para o front controller). Só redirect externo vence, e esse entra em
laço com a regra do nginx. A saída foi não ter o conflito.

E por que **fora** de `public/`, em vez de só renomear a pasta: arquivo que
existe dentro de `public/` é servido direto pelo Apache, sem passar pelo router.
Renomear criaria um **segundo endereço público** para a mesma página, indexável
e com canonical próprio. Fora de `public/`, o endereço é um só: o declarado.

## Regras

**Os endereços `/lgpd/solicitar` e `/lgpd/acompanhar` são permanentes.** O link
de acompanhamento é montado em
[`../../../public/api/lgpd/request.php`](../../../public/api/lgpd/request.php) e
**enviado por e-mail ao titular de dados**. E-mail já enviado não se corrige. As
duas formas valem, com e sem `.php`.

**A view ainda mora em `public/includes/legal_page.php`.** Este é o acoplamento
conhecido que sobra do D3: mover a página para fora de `public/` resolve o
endereço, não a view. Por isso o `require` daqui alcança `public/`. Quando as
views saírem, este caminho encurta.

**Ao mexer aqui, rode `scripts/tests/rotas_test.php`.** Ele trava o que importa:
os quatro endereços permanentes, a ausência do conflito de nome, e o fato de
estes arquivos continuarem fora de `public/`.
