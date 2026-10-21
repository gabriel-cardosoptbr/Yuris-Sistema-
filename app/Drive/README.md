# app/Drive

O **Google Drive** ligado a cada cliente (ambientes da Inovaize, 20/10/2026). Os
arquivos ficam no Drive; o sistema guarda só as referências (o id da pasta do
cliente, a última listagem conhecida e os vínculos com cartões e entregas).

| Arquivo | O que faz |
|---|---|
| `Api.php` | a única saída para o Google (OAuth e Drive v3), com o transporte trocável para o teste; erro do Google vira `DriveErro` com mensagem em português |
| `DriveErro.php` | a falha com motivo curto: revogado, sem_acesso, nao_encontrado, instavel, dominio |
| `Conexao.php` | a conta Google autorizada da conta do sistema: OAuth, refresh token cifrado (`App\Core\Crypto`), renovação do token, domínio corporativo obrigatório (`GOOGLE_DRIVE_DOMINIO`), desconectar |
| `Pasta.php` | a pasta de cada cliente: ligar pelo link ou pela busca, listar e navegar subpastas com a hora da leitura, enviar arquivo, ver e dar acesso à equipe |
| `Sincronizacao.php` | a atualização automática pela API de mudanças do Drive (`bin/drive_sync.php`) |
| `ArquivoDoCartao.php` | arquivo da pasta do cliente vinculado a um cartão (`task_drive_arquivos`) |

API: `public/api/drive.php` (operação) e `public/api/drive_oauth.php` (conectar).
Tela: `public/assets/ff-drive.js` (aba Arquivos da ficha do cliente e o seletor de
arquivo do cartão) e o cartão "Google Drive" da Visão Geral da Empresa. Migration
160. Teste: `scripts/tests/ambientes_test.php` (Google de mentira).

## Configuração externa (uma vez)

1. No Google Cloud da Inovaize: projeto com a **Google Drive API** ativa, tela de
   consentimento OAuth (interna, se a conta for Google Workspace) e uma credencial
   **OAuth client ID** do tipo "Aplicativo da Web", com a URI de redirecionamento
   `https://yuris.inovaize.com/api/drive_oauth.php`.
2. No `.env` do servidor: `GOOGLE_DRIVE_CLIENT_ID`, `GOOGLE_DRIVE_CLIENT_SECRET`,
   `GOOGLE_DRIVE_DOMINIO=inovaize.com` (recomendado) e, se o endereço for outro,
   `GOOGLE_DRIVE_REDIRECT_URI`. Precisa de `APP_ENCRYPTION_KEY` (já usada pelo Core).
3. Um administrador abre Empresa › Visão Geral › "Conectar conta Google" e entra
   com a conta corporativa.
4. Cron a cada 5 minutos: `docker exec yuris_app php /var/www/html/bin/drive_sync.php`.

## Regras

- **Lista com hora, nunca lista velha como atual.** Cada pasta lida fica em
  `drive_itens` com a hora da leitura (`drive_pastas_lidas`). A tela mostra
  "sincronizado às 14:32". Se o Google falhar, mostra o erro, marca a lista como a
  última conhecida (com a hora dela) e a deixa apagada; a pasta não some e nada é
  apresentado como se fosse de agora.
- **Atualização automática**: o cron lê as mudanças do Drive desde o último
  marcador e relê só as pastas tocadas; a ficha aberta relê a pasta a cada minuto
  (do cache se a leitura tem menos de 2 minutos) e há "Atualizar" para ler agora.
- **Árvore do cliente**: subpasta só abre se foi vista dentro da pasta do próprio
  cliente; arquivo só vira vínculo de cartão nas mesmas condições.
- **Acesso alinhado**: "Acessos da equipe" compara quem vê o cliente no sistema com
  quem tem acesso à pasta no Drive (abrir o arquivo pelo link exige os dois). Só
  administrador dá acesso, e só a quem já vê o cliente no sistema (papel de edição,
  sem e-mail do Google).
- **Envio de arquivo**: vai para a pasta (ou subpasta) do cliente no Drive; com
  material esperado, registra o recebimento. Aprovação, entrega e publicação
  continuam com os próprios estados (`App\Digital\Entrega`).
- Ligar ou trocar a pasta do cliente é da administração (vendedor não).
- **Pré-visualização em todos os arquivos** (pedido do dono do produto, 20/10/2026):
  a pasta aparece em grade, cada arquivo com a miniatura que o Drive gera (imagem,
  vídeo, PDF, documento, planilha). `Pasta::miniatura` busca o `thumbnailLink` e baixa
  a imagem com o token da conta (`Api::baixar`, só endereço do Google), sempre de
  arquivo visto na árvore do próprio cliente, e guarda em disco pela data de
  modificação (`storage/drive_miniaturas`, ou `DRIVE_MINIATURAS`; é cache, pode
  apagar). Arquivo sem miniatura mostra o ícone do tipo. Clicar abre a
  pré-visualização dentro do sistema (o visualizador do Drive, `/file/d/ID/preview`),
  com "Abrir no Drive"; para ver, a conta Google da pessoa precisa ter acesso à
  pasta ("Acessos da equipe"). O seletor de arquivo do cartão usa a mesma grade, com
  "Ver" e "Escolher".
