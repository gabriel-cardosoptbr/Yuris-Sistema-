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
| `AuthController.php` | login, logout e endurecimento da sessão (regenera id, expira, marca cookie). Agnóstico de marca: qualquer tela de login pode postar aqui, desde que mande `login_page` com o próprio caminho (whitelist em `sanitizeLoginPage()`, contra open redirect) — assim erro de senha e logout voltam pra tela de origem (`/login.php` ou `/login-fleetiflow.php`), não sempre pro login Yuris |
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
