Inovaize - Sistema de Vendas (skeleton)

Estrutura mínima para rodar no XAMPP local em: http://localhost/sistema_vendas/

Passos rápidos:

1. Copie a pasta para `C:\xampp\htdocs\sistema_vendas` (já criado).
2. Crie um banco MySQL e importe `database/schema.sql`.
3. Atualize `config/database.php` com suas credenciais MySQL.
4. Acesse `http://localhost/sistema_vendas/public/`.

Arquivos importantes:
- config/database.php -> conexão PDO
- database/schema.sql -> esquema inicial com tabelas
- public/index.php -> front controller simples
- public/login.php -> página de login
- public/dashboard.php -> UI inicial do dashboard (dados mínimos)
- app/Models/Database.php -> wrapper PDO
- app/Models/User.php -> modelo de usuário
- app/Controllers/AuthController.php -> login/logout

Observações:
- Esta é uma base inicial com autenticação, validação de CSRF e esquema SQL.
- Próximos passos: implementar todas as features da spec (dashboard dinamicamente ligado à aba Prospecção, CRUD de cards, drag&drop, gráficos completos).
