# Quando o WhatsApp de um escritório para de trazer mensagem

Roteiro de diagnóstico escrito depois do caso da conta 83 em 10/09/2026, com os
comandos que realmente foram usados. A ordem importa: cada passo elimina uma
camada, e pular para o fim leva a mexer no que não está quebrado.

O erro que mais custa tempo aqui é começar pelo Yuris. Na maioria das vezes o
Yuris tem exatamente o que a Evolution tem, e o problema está antes.

---

## 0. O log de verdade

- Yuris: `docker logs yuris_app`. O `/var/log/apache2/error.log` dentro do
  contêiner é um symlink para `/dev/stderr` e **sempre parece vazio**.
- Evolution: `docker logs evolution_api`. Passe por
  `sed -E "s/\x1b\[[0-9;]*m//g"` para tirar as cores, senão o `grep` erra.
- O tamanho da resposta que o Apache registra **inclui cabeçalhos**:
  `237 = ~200 + 37`, e 37 é exatamente `{"ok":true,"event":"messages.upsert"}`.
  Já perdi tempo achando que 237 era corpo poluído.

---

## 1. A pergunta que decide tudo: a Evolution tem a mensagem?

Se ela não tem, o problema não é do Yuris e mexer no Yuris não resolve.

```bash
docker exec -i evolution_postgres psql -U evolution -d evolution -c "
SELECT i.name, COUNT(m.id) AS msgs, to_timestamp(MAX((m.\"messageTimestamp\")::bigint)) AS ultima
  FROM \"Instance\" i LEFT JOIN \"Message\" m ON m.\"instanceId\" = i.id
 GROUP BY i.name ORDER BY ultima DESC NULLS LAST;"
```

O usuário do Postgres é `evolution`, não `postgres`.

**Compare com os OUTROS canais.** Se os outros estão recebendo agora e só um
parou, o problema é daquela conexão, não da infraestrutura. Foi assim que ficou
claro, no caso da conta 83, que não era a Evolution inteira.

---

## 2. Só então compare com o Yuris

```bash
docker exec -i yuris_app php -r '
require_once "/var/www/html/app/bootstrap.php";
$pdo = App\Core\Database::getConnection();
echo $pdo->query("SELECT MAX(created_at) FROM whatsapp_messages WHERE instance_id=14")->fetchColumn();'
```

- Evolution na frente do Yuris: é entrega por webhook. Rode a reconciliação
  (passo 6) e olhe o log do webhook.
- Os dois parados na mesma hora: **o problema é antes da Evolution.** Siga.

---

## 3. O estado da conexão, e o motivo da última queda

```bash
docker exec -i evolution_postgres psql -U evolution -d evolution -c "
SELECT name, \"connectionStatus\", \"disconnectionReasonCode\", \"disconnectionAt\", \"updatedAt\"
  FROM \"Instance\" WHERE name = 'NOME-DO-CANAL';"
```

`disconnectionReasonCode`:

| código | o que é | o que fazer |
|---|---|---|
| 401 | sessão deslogada (o aparelho removeu este dispositivo, ou o WhatsApp invalidou) | precisa de novo pareamento |
| 403 | conexão recusada | costuma voltar sozinha |
| 428 | conexão fechada | reiniciar a instância |
| 440 | sessão substituída (o mesmo número pareado em outro lugar) | ver se alguém conectou o número em outro sistema |

Cuidado: `connectionStatus` pode dizer `open` mesmo com a entrega quebrada.
Ele diz que o socket está de pé, não que as mensagens estão chegando.

Estado ao vivo, que é mais confiável que a coluna:

```bash
curl -s -H "apikey: $KEY" "$URL/instance/connectionState/$INSTANCIA"
```

As credenciais ficam em `whatsapp_settings` da conta, nas chaves
`evolution_base_url`, `evolution_api_key`, `evolution_instance`. A coluna é
`config_key`/`config_value`, não `chave`/`valor`.

---

## 4. O sintoma que engana: recebe aviso, não recebe mensagem

```bash
docker logs evolution_api --since 30m 2>&1 | sed -E "s/\x1b\[[0-9;]*m//g" \
  | grep -i "NOME-DO-CANAL" | grep -i "Original message not found"
```

`Original message not found for update. Skipping.` com `fromMe:false`,
repetidas, significa que o WhatsApp está mandando **atualizações** de mensagens
que a Evolution **nunca recebeu**. A conversa está acontecendo e o conteúdo não
chega.

Confirme pelo outro lado: se a tabela `Chat` tem `updatedAt` recente mas a tabela
`Message` não ganha linha, é exatamente este quadro.

```bash
docker exec -i evolution_postgres psql -U evolution -d evolution -c "
SELECT c.\"remoteJid\", c.\"updatedAt\" FROM \"Chat\" c JOIN \"Instance\" i ON i.id = c.\"instanceId\"
 WHERE i.name = 'NOME-DO-CANAL' ORDER BY c.\"updatedAt\" DESC LIMIT 5;"
```

Isso **não** se resolve no Yuris e **não** se resolve consultando melhor.

---

## 5. `rate-overlimit`: o WhatsApp limitando a conexão

```bash
docker logs evolution_api --since 12h -t 2>&1 | sed -E "s/\x1b\[[0-9;]*m//g" \
  | grep -i "rate-overlimit" | awk '{print substr($1,1,13)}' | sort | uniq -c
```

Conexão limitada atrasa e derruba entrega. No caso da conta 83 foram 2.034 em
30h.

**Boa parte era autoinfligida:** `fetchGroupInfo` roda uma vez por grupo, e o
escritório tem 50. Por isso o sync por linha de comando nasce em **modo leve**
(`--completo` pede a volta inteira) e o cron caiu para 3h. Antes de culpar o
WhatsApp, confira se não é ferramenta nossa batendo na conexão.

---

## 6. A reconciliação, que é rede de segurança e não cura

```bash
docker exec yuris_app php /var/www/html/public/api/whatsapp/sync.php
```

Modo leve por padrão: só `findMessages`, que lê o banco da própria Evolution e
não custa nada ao WhatsApp. Se ele diz "mensagens novas: N" e o `MAX(created_at)`
não anda, é porque **a Evolution não tem nada novo para dar**. O número que ele
imprime é processado, não inserido.

Cron: `17 */3 * * *`, com `flock`, log em `/home/ubuntu/yuris-cron.log`.

---

## 7. Reiniciar a instância (seguro)

```bash
curl -s -X POST -H "apikey: $KEY" "$URL/instance/restart/$INSTANCIA"
```

Usa **as mesmas credenciais**: não pede QR, não apaga conversa, não cria
instância. Confira o webhook antes e depois, e ele tem que sair igual:

```bash
curl -s -H "apikey: $KEY" "$URL/webhook/find/$INSTANCIA"
```

Espere uns 12 segundos e verifique o passo 1 de novo. Se o
`Original message not found` continuar e a tabela `Message` não andar, o restart
não resolveu e a causa é do lado do WhatsApp.

---

## 8. O último recurso, e o que ele NÃO faz

Novo pareamento (QR) **na mesma instância**. Isso:

- **não** apaga a instância
- **não** apaga as conversas nem as mensagens já guardadas, nem na Evolution nem
  no Yuris
- **não** cria canal novo, nem token novo, nem webhook novo

É `logout` seguido de `connect` no mesmo nome, e a tela do Yuris (Comunicação →
Chat WhatsApp) já faz isso. Mas exige que a pessoa esteja com o celular na mão.

**Nunca** crie uma segunda instância para o mesmo número. Um canal por conta é
regra do projeto, e duas instâncias no mesmo número derrubam uma à outra
(código 440).

---

## 9. Como saber se a falha é da Evolution ou do n8n

O n8n não recebe nada por conta própria: ele recebe do webhook da Evolution.
Então, se o passo 1 mostra que a Evolution não tem a mensagem, o n8n não tinha
como ver e não há o que investigar lá.

Só faz sentido olhar o n8n quando a Evolution TEM a mensagem. Aí:

```bash
docker logs n8n-n8n-1 --since 30m 2>&1 | tail -50
```

E confira, no `webhook/find`, se o evento que interessa está na lista (são 12 hoje,
incluindo `MESSAGES_DELETE`). Reescrever o webhook **substitui a lista inteira**:
mande sempre todos os eventos e todos os headers, senão você apaga o que não
mandou.

---

## 10. `syncFullHistory`

Está `false`, e é para continuar assim por padrão. Ele só muda o que acontece no
**primeiro pareamento**, puxando o histórico inteiro do aparelho, e é um dos
caminhos mais rápidos para tomar `rate-overlimit`. Não serve para recuperar
mensagem perdida depois: para isso é a reconciliação do passo 6.

---

## O que ficou em aberto

A Evolution recebe e guarda mensagens que não entrega por webhook, e às vezes
nem recebe mensagens cuja atualização ela recebe. A reconciliação a cada 3h é
rede de segurança, não cura. Se voltar a acontecer, comece pelo passo 1 e pelo
`rate-overlimit`.
