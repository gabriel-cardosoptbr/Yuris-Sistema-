# Usuarios/ — quem entra, como entra, e o que enxerga

Cobre a tela de login, a tela **Gestão › Usuários**, e todo o mecanismo de
convite e vínculo entre escritórios.

Um detalhe que confunde quem chega agora: existem **dois tipos de gente** no
Yuris, e eles não são a mesma coisa.

- **Usuário da conta** (`User`): trabalha no escritório, tem login próprio e um
  papel (`owner`, `admin`, `member`). É criado dentro da conta.
- **Advogado associado** (`AdvogadoVinculo`): é de fora. Entra por convite com
  token e enxerga apenas os processos e clientes que foram compartilhados com
  ele, nada além.

## Arquivos

| Classe | O que faz |
|---|---|
| `AuthController.php` | login, logout e endurecimento da sessão. `abrirSessao()` (22/10/2026) é o único lugar que monta a sessão de quem provou quem é, pela senha ou pelo "Lembrar"; `ensureSessionStarted()` virou público para o `Lembrar` usar os mesmos cookies seguros; o logout também apaga o "Lembrar" e o aparelho dos avisos push. Também regenera id, expira e marca cookie. Agnóstico de marca: qualquer tela de login pode postar aqui, desde que mande `login_page` com o próprio caminho (whitelist em `sanitizeLoginPage()`, contra open redirect): assim erro de senha e logout voltam pra tela de origem (`/login.php` ou `/login-fleetiflow.php`), não sempre pro login Yuris. `dadosDaSessao()` (29/10/2026) é a parte do login que monta usuário, conta, nível e permissões por página; `abrirSessao()` grava esses dados, e a troca de empresa e a sessão emprestada do WhatsApp (`PessoaVinculada`) montam a sessão por ela. Depois de conferir a senha, o login passa por `PessoaVinculada::doEndereco()`. Token do formulário que não bate (tela aberta há muito tempo) volta com "A tela de login ficou aberta muito tempo e expirou. Digite a senha de novo." em vez de "Token inválido." (28/10/2026); as telas já pegam o token novo antes de enviar (`public/assets/login-token.js` + `api/auth/csrf.php`) |
| `PessoaVinculada.php` | **a mesma pessoa em mais de uma conta** (29/10/2026, o Kauê vende para a Inovaize e para a Fleetiflow com o mesmo login). Cada conta tem o SEU usuário da pessoa (o da outra conta é usuário de verdade de lá: vendedor, responsável de lead, dono de conversa, com as permissões de lá), e `usuario_vinculos` (migration 169) diz que são a mesma pessoa. `contas()` lista as empresas da pessoa; `trocar()` abre a sessão do usuário dela na outra conta pela mesma rotina do login (o botão "Empresa" do menu, `api/auth/trocar_empresa.php`); `doEndereco()` faz o login num endereço de marca abrir na conta daquele endereço (a senha conferida é sempre a do login digitado). **Sessão emprestada do WhatsApp:** o `app/bootstrap.php` chama `emprestarSessaoSePreciso()` em todo `/api/whatsapp/`: quando a chamada pede um número (`channel_id`), uma empresa (`?empresa=`) ou uma mídia (`media.php?msg_id=`) de outra conta da pessoa, a sessão DAQUELA requisição vira a do usuário dela lá (`dadosDaSessao`, `SessaoEmprestada`), nunca gravada. Todas as regras de acesso do Chat valem como se ela tivesse entrado lá; quem não é a mesma pessoa continua recusado. É o que deixa o Chat juntar as conversas das duas empresas. Webhook e endpoints por token nunca emprestam. Pôr alguém em outra conta: `scripts/manutencao/vincular_pessoa.php`. Teste: `scripts/tests/pessoa_vinculada_test.php` |
| `SessaoEmprestada.php` | o gerenciador de sessão de UMA requisição emprestada (acima): a leitura devolve a sessão montada para o usuário da outra conta, a gravação e o apagar não fazem nada, então a sessão de verdade, no arquivo, não muda. `codificar()` monta a sessão no formato do `session.serialize_handler` ("php" ou "php_serialize"; outro formato não empresta) |
| `Lembrar.php` | o **"Lembrar"** da tela de login (22/10/2026): marcado, o aparelho volta sozinho por 30 dias sem a sessão do PHP (que acaba ao fechar o navegador; no iPhone, ao fechar o app). Cookie `yuris_lembrar` = seletor:validador; o banco (`login_lembrar`, migration 167) guarda só o hash do validador. Quem chama a volta é o `app/bootstrap.php`, só quando o cookie existe: abre a sessão para olhar, entra por `AuthController::abrirSessao` (o mesmo código do login, conferindo usuário ativo e conta com acesso) e fecha. Validador errado apaga o token; sair apaga o do aparelho; trocar a senha (`api/users.php`, Painel Master) apaga os de todos os aparelhos da pessoa. O validador não muda a cada uso (o app abre várias chamadas ao mesmo tempo); a validade anda com o uso. No app instalado a caixa já vem marcada. Conferido com curl: login marcado, volta só com o cookie, cookie adulterado recusado |
| `User.php` | o usuário em si: busca por login, por id |
| `TotpHelper.php` | 2FA por TOTP, RFC 6238 implementado à mão (HMAC-SHA1, janela de 30s, 6 dígitos). Sem biblioteca externa |
| `AdvogadoConvite.php` | convite por token para advogado associado, com expiração |
| `AdvogadoVinculo.php` | o vínculo já aceito, e o que ele dá acesso |
| `AccountVinculo.php` | vínculo entre **contas**: matriz e filial. Não confundir com o vínculo de advogado |
| `Team.php` | **setores** (na tela é "Setor"; no banco, `teams`) dentro da conta, com membros e, desde a migration 140 (05/10/2026), um **gestor** (`gestor_user_id`). O setor marca a conversa do WhatsApp (`whatsapp_chats.team_id`) e o lead (`cards.team_id`), e as duas ficam iguais (ver `../WhatsAppAgente/README.md`). `garantirPorNome()` acha ou cria o setor pelo nome, sem diferenciar caixa nem acento, e é como a automação marca o lead sem saber o id. `definirSetorDoCard()` com `soSeVazio` não troca setor escolhido por pessoa e recusa setor de outra conta. `temColuna()` deixa o código funcionar antes de a 140 ser aplicada. Na edição CRM, "Setores" é item próprio do menu Gestão (`usuarios.php?tab=setores`) |
| `Consent.php` | consentimento granular do titular (LGPD Art. 8º e 18 IX) |
| `TermAcceptance.php` | registro de que alguém aceitou uma versão específica de um termo. O documento aceito vive em `../Lgpd/LegalDocument.php` |

## Regras que não podem ser afrouxadas

**Token de convite é `bin2hex(random_bytes(32))`, sempre.** Nunca sequencial,
nunca derivado de e-mail ou id. E sempre com expiração.

**Advogado associado não herda acesso.** Ele vê só o que foi compartilhado
explicitamente (via `../Master/ResourceShare.php`). Se em algum ponto ele passar
a enxergar por herança de conta, isso é vazamento.

**Papel não é hierarquia automática.** `owner` e `admin` têm poderes
diferentes e explícitos; não presuma que um contém o outro sem conferir a
checagem real.

**Toda página gate por permissão exige a chave nas DUAS listas.** A whitelist
do servidor (`$_validPages` em `../../public/api/users.php`) e o grid de
checkbox do formulário (`ALL_PAGES` em `../../public/usuarios.php`). Faltando
em qualquer uma, o checkbox marca mas o `INSERT` em `user_permissions` é
descartado em silêncio — achado em 28/09/2026: `clientes` e `tarefas` nunca
estiveram em nenhuma das duas, e nenhum usuário perfil `user` jamais conseguiu
acesso real a essas duas páginas por aqui (`clientes.php` bloqueia de verdade
quem não tem a permissão; `tarefas.php` não bloqueia, então só o item do menu
sumia). Página nova gated por `_sidebarCan()` no `sidebar.php`: acrescente a
chave nos dois lugares na mesma mudança.

**A seção "Permissões de Acesso" do modal Criar Usuário precisa aparecer
sozinha, sem o admin precisar tocar no campo Perfil.** Ela é escondida por CSS
e só um listener de `change` no `<select>` a revelava — como `user` já vem
selecionado por padrão no HTML, quem cria alguém sem mexer nesse campo nunca
via as permissões, e o usuário nascia sem nenhuma (mesmo bug de 28/09/2026,
raiz do problema acima). `usuarios.php` agora sincroniza a visibilidade uma vez
ao carregar e de novo toda vez que o modal reabre (`btnNewUser`), não só em
`change`.
