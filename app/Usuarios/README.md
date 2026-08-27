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
| `AuthController.php` | login, logout e endurecimento da sessão (regenera id, expira, marca cookie) |
| `User.php` | o usuário em si: busca por login, por id |
| `TotpHelper.php` | 2FA por TOTP, RFC 6238 implementado à mão (HMAC-SHA1, janela de 30s, 6 dígitos). Sem biblioteca externa |
| `AdvogadoConvite.php` | convite por token para advogado associado, com expiração |
| `AdvogadoVinculo.php` | o vínculo já aceito, e o que ele dá acesso |
| `AccountVinculo.php` | vínculo entre **contas**: matriz e filial. Não confundir com o vínculo de advogado |
| `Team.php` | times dentro da conta, usados como filtro nas telas |
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
