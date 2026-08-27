# config/ — configuração da aplicação

Uma pasta pequena e com uma regra só: **aqui não mora segredo**.

## Arquivos

| Arquivo | O que é |
|---|---|
| `database.php` | monta a configuração de conexão. Lê do `.env` pelo `EnvLoader` e cai em padrões de XAMPP quando não encontra |
| `.htaccess` | bloqueia acesso web a esta pasta |

## Como a configuração é resolvida

```
.env (raiz, fora do git)  ->  EnvLoader  ->  config/database.php  ->  Database
                                   ^
                          ambiente do processo vence o .env
```

Em desenvolvimento, sem `.env`, o padrão é o XAMPP local (`127.0.0.1`, base
`sistema_vendas`, usuário `root`, senha vazia) e o sistema sobe sem
configuração nenhuma. **Em produção o `.env` é obrigatório**, e o modelo do que
ele precisa conter está em [`../.env.example`](../.env.example).

## Regras

**Credencial nunca em arquivo versionado.** O `.env` está no `.gitignore` e
precisa continuar. Se uma chave entrar em commit, considere-a vazada: rotacione,
não basta apagar no commit seguinte.

**O padrão de dev não pode ser o padrão de produção.** `config/database.php`
cai em `root` sem senha quando não acha `.env`. Isso é conveniência local; se um
servidor de produção subir sem `.env`, ele tenta esse padrão e o sintoma é
confuso. Ao investigar conexão em produção, comece confirmando que o `.env` foi
lido.

**Configuração de negócio não é `.env`.** Limite de plano, módulo habilitado e
preferência do escritório vivem no banco, por conta. `.env` é só o que muda
entre máquinas: banco, chaves de serviço, ambiente.

Descrição de cada variável: [`../docs/ENVIRONMENT.md`](../docs/ENVIRONMENT.md).
