Visão geral da arquitetura

Estrutura principal do repositório:
- app/
  - Controllers/  => Lógica de aplicação e handlers (PHP)
  - Models/       => Acesso ao banco e modelos (PDO)
- public/         => Frontend público e rotas (PHP), assets, JS/CSS
- config/         => Arquivos de configuração (ex: database.php)
- database/       => Schema e migrations
- storage/        => Arquivos gerados/armazenamento local

Fluxo básico
- Usuário autentica via sessão e acessa páginas em public/*.php.
- Páginas chamam endpoints em public/api/*.php para leitura/escrita via fetch/ajax.
- Modelos em app/Models encapsulam acesso ao banco (PDO).

Segurança e autenticação
- Autenticação simples via sessão PHP ($_SESSION['user_id']).
- CSRF tokens embutidos nas páginas para operações sensíveis.

Próximos passos arquiteturais sugeridos
- Mover configurações permanentes para tabela no banco.
- Adicionar camada de serviços para integração com provedores externos (WhatsApp, OpenAI).
- Implementar logs/telemetria para conversas do agente.
