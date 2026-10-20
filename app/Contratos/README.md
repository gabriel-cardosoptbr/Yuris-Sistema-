# app/Contratos: contratos e assinatura eletrônica (edição CRM)

Tela **Operação › Contratos** (`public/contratos.php`), a seção "Contratos" da ficha
do lead e da ficha do cliente, a página de quem assina (`public/assinar.php`) e a
consulta pública de autenticidade (`public/verificar.php`). Conta jurídica (Yuris)
não tem nada disto.

Nasceu em 18/10/2026: a equipe prepara um contrato, gera um link individual para
cada participante e acompanha as assinaturas. Quem assina abre o link no
computador ou no celular, confirma por um código enviado ao WhatsApp, confere os
dados e o documento e assina, **sem criar conta no CRM**.

**O que é, juridicamente:** assinatura eletrônica com confirmação por código de uso
único enviado ao WhatsApp do convite, aceite explícito e trilha de evidências.
**Não é** assinatura avançada nem qualificada (ICP-Brasil), e nenhuma tela diz que
é. O código comprova acesso ao WhatsApp do convite, não a titularidade do CPF.

## Arquivos

| Classe | O que faz |
|---|---|
| `Contrato.php` | lado da equipe: criar, editar, anexar o PDF, participantes, fechar a versão e convidar, novo link, liberar bloqueio, cancelar, expirar, recalcular a situação, listar, a ficha e a **central da conta** (quantos contratos, em que situação, quanto disco ocupam os assinados) |
| `Assinatura.php` | lado de quem assina: abrir o convite (troca o token por sessão curta), enviar e conferir o código, identificar (cadastro confirmado ou nome e CPF declarados), o PDF, assinar e recusar |
| `Convite.php` | o link de cada participante para cada versão: token de 256 bits, só o hash no banco, validade, revogação, uso único, endereço base pela marca |
| `Cripto.php` | UUID v4, código de verificação, token, código de 6 dígitos, HMAC com chave do servidor, cifra de CPF e telefone (`App\Core\Crypto`), selo Ed25519 do manifesto, JSON canônico |
| `Cpf.php` | dígitos verificadores e máscara. Só matemática |
| `Eventos.php` | a trilha de eventos encadeada por hash, e a conferência da corrente |
| `Armazenamento.php` | os arquivos no disco privado, com nome pelo hash, sem sobrescrever, hash conferido na leitura |
| `Finalizacao.php` | depois da última assinatura: comprovante, PDF de conclusão (qpdf) e manifesto; repete com espera crescente se falhar |
| `Manifesto.php` | monta e confere o manifesto de evidências selado pela plataforma |
| `PdfSimples.php` | gerador mínimo de PDF (texto e linhas) para o comprovante: o servidor não tem biblioteca de PDF |
| `Entrega.php` | manda convite e código pelo WhatsApp do número conectado da própria conta (mesma instância da Evolution) |
| `Rede.php` | IP de quem assina, confiando no `X-Forwarded-For` só através de proxy confiável |
| `Tempo.php` | UTC no banco, Brasília na tela, prazo pelo fim do dia local |
| `Permissao.php` | quem faz o quê, conferido no servidor em cada ação |

Endpoints: `public/api/crm_contratos.php` (equipe) e `public/api/assinatura.php`
(página externa). Worker: `bin/contratos_worker.php`. Migration: `database/migrations/run_151.php`.
Teste: `scripts/tests/contratos_test.php` (`php -d extension=sodium` no XAMPP local).

## O fluxo

1. **Rascunho.** A equipe cria o contrato (título, responsável, cliente ou negociação,
   prazo), anexa o PDF e inclui os participantes: contratante, contratado,
   representante da empresa (com empresa, CNPJ e função) e testemunhas. Cada um tem o
   WhatsApp do convite e, se quiser, o CPF esperado.
2. **Fechar e enviar.** O PDF da versão é fechado: o SHA-256 foi calculado dos bytes
   no servidor e não muda mais (gatilho no banco). Cada participante ganha um convite
   para aquela versão, mandado pelo WhatsApp da conta e mostrado para a equipe copiar.
3. **Quem assina** abre `/assinar#t=...`. Antes do código, a página mostra só a
   empresa, o título, o primeiro nome, o papel e o final do WhatsApp. Pede o código,
   confirma, confere ou informa nome e CPF, vê o PDF inteiro, marca o aceite
   ("Li o contrato apresentado e concordo em assiná-lo eletronicamente.", desmarcado)
   e aperta "Assinar contrato".
4. **Concluído** quando todos os obrigatórios assinaram a versão atual. Aí saem o
   comprovante, o PDF de conclusão e o manifesto selado, e a consulta pública passa a
   conferir tudo.

## Na tela

- **Lista em folhas A4** (`ff-contratos.js`, `folha`): cada contrato é um card com a forma de uma página: situação, código,
  cliente ou negociação, assinaturas feitas, quantos abriram o link, as iniciais de quem participa (cor pela situação),
  criado, enviado, prazo ou conclusão e responsável. `Contrato::listar` traz `enviado_em`, `abriram` e `pessoas`.
- **Acompanhamento na ficha**: as etapas do contrato (criado, PDF anexado, participantes, enviado, assinaturas, concluído
  ou o encerramento) em `marcos`, e o caminho de cada participante na versão em assinatura em `acompanhamento`
  (convite enviado, abriu o link, confirmou o código, viu o documento, assinou ou recusou), com horário e observações
  (abriu várias vezes, errou o código, link gerado mas não mandado, CPF em revisão). Vem dos eventos a partir do envio
  da versão. Abrir o link e ver o documento não são assinatura nem prova de leitura.

## Situações

Contrato: `rascunho`, `aguardando`, `parcial`, `concluido`, `recusado`, `expirado`,
`cancelado`. Uma versão substituída fica `substituida` (com as assinaturas que teve).
Participante: `pendente`, `convidado`, `assinou`, `recusou`, `bloqueado`, `cancelado`, `expirado`.

- **Nova versão** (corrigir o PDF): anexar outro PDF cria o rascunho da próxima
  versão; ao fechar e enviar, a versão aberta vira substituída, todos os convites e
  sessões dela caem, as assinaturas antigas continuam registradas (da versão
  substituída) e todos assinam de novo.
- **Cancelar e expirar** barram novas assinaturas e preservam tudo. Cancelar o fluxo
  não apaga assinatura nem diz que desfez um contrato já celebrado.
- **Recusa** de qualquer participante encerra o contrato como recusado.

## Identificadores e segredos

| | Para que serve | Como é |
|---|---|---|
| UUID do contrato | identifica o contrato para sempre, em todas as versões | v4, gerado no servidor |
| UUID da versão, do participante, da assinatura, do convite, do código, do arquivo | cada um o seu | v4 |
| Código de verificação | consulta pública de autenticidade; não dá acesso a mais nada | 60 bits, base32 de Crockford, `XXXX-XXXX-XXXX` |
| Token do link | é o que permite assinar; um por participante e versão | 256 bits; no banco só o SHA-256; vai no **fragmento** (`#t=`) |
| Código de 6 dígitos | prova acesso ao WhatsApp do convite | no banco só o HMAC-SHA256 com chave do servidor |
| Sessão da página externa | depois do token, só ela vale; presa ao convite, à versão e ao hash | cookie `ctr_s`, HttpOnly, SameSite=Strict, 30 min |

Nenhum segredo usa CPF, nome, data ou número sequencial. Token e código nunca vão
para log (o envio grava só "WhatsApp terminado em 0001"). Por que o fragmento: o
navegador não manda `#...` ao servidor, então o token não cai em log do nginx nem
do Apache, não vaza no `Referer`, e a prévia de link do WhatsApp (um GET) nunca o
vê. A página troca o token por sessão num POST e apaga o fragmento da barra.
**GET nunca assina nem consome nada.**

Configuração padrão por conta (`crm_contrato_config`, ajustável por administrador):
código de 6 dígitos, validade de 5 min, 5 tentativas, 60 s entre reenvios, no
máximo 8 códigos por hora por convite, convite válido por 15 dias (ou até o prazo
do contrato, o que vier antes).

## Divergência e cadastro

- **CPF esperado diferente do informado**: o participante fica `bloqueado`, o convite
  e a sessão caem e a equipe vê o motivo. Liberar exige escrever o que foi conferido
  (vira evento) e gera um link novo.
- **Contato do convite não se troca durante a assinatura.** Mudar o WhatsApp ou o CPF
  esperado de quem já foi convidado revoga o convite: precisa de um novo.
- **Cliente já ligado pela equipe ao participante**: depois do código, a página mostra
  nome e CPF mascarado do cadastro para a pessoa confirmar (`cadastro_confirmado`).
  Informar um CPF existente **não** autentica ninguém nem revela cadastro.
- **Sem cadastro**: nome completo e CPF (dígitos conferidos no servidor), registrados
  como `autodeclarado`. O participante é o registro mínimo do signatário; nenhum
  usuário do CRM é criado. Se houver cliente com o mesmo CPF na conta, o evento
  `identificacao` sinaliza `possivel_cadastro_cliente_id` para a equipe decidir: nada
  é ligado ou mesclado sozinho.
- **Representante**: empresa, CNPJ e função ficam na evidência com o aviso de que a
  identificação do representante não comprova, sozinha, os poderes de representação.

## Evidências de cada assinatura

Gravadas na transação da assinatura, em `crm_contrato_assinaturas.evidencia_json`
(JSON canônico, com o SHA-256 ao lado): contrato (UUID e código), versão (UUID,
número, SHA-256 do PDF), participante e papel, nome e CPF mascarado declarados ou
confirmados, origem dos dados, vínculo com cadastro, representação, método, contato
mascarado, UUID do código e do convite, texto e versão do aceite, instante UTC do
servidor com milissegundos, IP (proxy confiável) e navegador. A fotografia dos
dados (`identificacao_json`, com o CPF cifrado) não muda se o cadastro mudar depois.
O código nunca entra na evidência.

Eventos (trilha): criação, documento, participantes, versão fechada e substituída,
convite gerado e enviado, acesso, código enviado, incorreto e confirmado,
identificação, CPF divergente, liberação, documento entregue ao navegador (não é
prova de leitura), assinatura, recusa, conclusão, expiração, cancelamento, arquivos
gerados e baixados, e cliente vinculado.

**Vincular a um cliente** (`Contrato::vincularCliente`, ação `vincular_cliente` da API,
20/10/2026): liga ou desliga o contrato de um cliente da conta em qualquer situação,
inclusive concluído, porque o cliente é cadastro do CRM e não faz parte do documento
assinado. Só quem gerencia o contrato; fica na trilha como `cliente_vinculado`. A ficha
do cliente usa isso no "Vincular contrato existente" (lista `?lista=1&sem_cliente=1`).

**Na seção "Documentos" da ficha** (cliente e lead, 20/10/2026):
`Contrato::documentosDaFicha` devolve os contratos do cliente (e dos leads de origem
dele) ou do lead, com os arquivos de cada um: o documento enviado para assinatura (o
da versão atual; no rascunho, o da última versão), o PDF assinado e o comprovante. A
API de anexos (`public/api/crm_anexos.php`) manda isso junto da lista, e os links baixam
por `?baixar=`, que confere o hash e registra `arquivo_baixado`. No caminho inverso,
`Contrato::criarDeAnexo` (ação `criar_de_anexo`) transforma um PDF anexado na ficha em
contrato novo, já ligado ao mesmo cliente ou lead, com o título tirado da descrição do
anexo (ou do nome do arquivo). A API confere que o anexo é da conta e que a sessão
alcança a ficha dele antes de ler o arquivo.

## O que impede e o que detecta alteração

Nada disto é "imutabilidade absoluta". Cada controle, e o que ele faz:

| Controle | Impede | Detecta |
|---|---|---|
| Gatilhos `trg_ctr_*` (migration 151): assinatura, evento e arquivo sem UPDATE/DELETE; versão aberta sem troca de arquivo ou hash | a aplicação e qualquer consulta comum | |
| Unicidade (`versao_id`, `participante_id`) e chave de idempotência | assinatura duplicada por clique, aba ou corrida | |
| Arquivos com nome pelo hash, gravação atômica, sem sobrescrever, só leitura | troca acidental pela aplicação | o hash é conferido em toda leitura; arquivo mexido não é servido |
| Corrente de hashes dos eventos | | evento apagado ou alterado no meio quebra a corrente (`Eventos::verificar`, mostrado na ficha) |
| SHA-256 do PDF na versão, na assinatura e no manifesto | | PDF diferente do assinado |
| Manifesto selado (Ed25519, chave em arquivo fora do banco e do repositório) | | qualquer mudança no manifesto, mesmo por quem tem o banco |

**Limite conhecido:** o usuário de banco da aplicação (`yuris_user`) tem todos os
privilégios no schema e, com eles, pode apagar um gatilho. Quem tem root no servidor
apaga arquivo. Para isso existem os controles de **detecção**. Endurecimento
recomendado, ainda não feito: um usuário de banco só para migrations e o da
aplicação sem `TRIGGER`/`DROP`/`ALTER`; cópia dos manifestos para um bucket S3 com
Object Lock. O selo da plataforma prova a origem do manifesto; a manifestação de
vontade de cada participante é o aceite com a confirmação por código.

**Horário:** relógio do servidor, gravado em UTC e mostrado em Brasília, sempre
identificado como "registrado pelo servidor". Se for preciso prova independente de
tempo, o ponto de integração é `Finalizacao::gerar` (carimbar o hash do manifesto
num serviço de carimbo do tempo RFC 3161).

## PDF final e verificação

- O **PDF original** de cada versão fica intacto, como arquivo próprio.
- O **comprovante** traz participantes, papéis, CPF mascarado, datas, identificadores,
  método de confirmação e o SHA-256 do original, e o código e o endereço de verificação.
- O **PDF de conclusão** é o original seguido do comprovante (qpdf). O hash dele fica no
  **manifesto**, não dentro dele (um arquivo não contém o próprio hash).
- **`/verificar?c=CODIGO`** mostra situação, UUIDs, hashes, data de cada assinatura por
  papel e a conferência do selo. Não mostra nome, CPF, contato, o contrato nem as
  evidências pessoais. O hash de um PDF escolhido é calculado no navegador, sem envio.
- Arquivos completos só saem pela API da equipe (com permissão) e, para quem assina,
  o PDF da própria versão dentro da sessão.

## Permissões (todas conferidas no servidor)

| Ação | Quem |
|---|---|
| ver, criar, editar, anexar, participantes, enviar, gerar ou reenviar link, baixar arquivos e evidências | quem vê a Prospecção ou tem a chave `contratos` |
| cancelar, liberar bloqueio, reprocessar a finalização, ajustar a configuração | dono ou administrador, o responsável pelo contrato, ou a chave `contratos.gerenciar` |
| evidências com o CPF completo | os mesmos de "cancelar" |
| abrir convite, pedir e confirmar código, identificar, assinar, recusar, ver o PDF | só a sessão daquele convite |
| verificar | público, pelo código |

Toda consulta filtra `account_id`; o teste confere que outra conta não lê, não lista,
não cancela nem mexe em participante.

## Privacidade (LGPD)

- **Finalidade:** formar e provar o contrato e defendê-lo em processo.
- **Base legal:** execução de contrato e procedimentos preliminares (art. 7º, V) e
  exercício regular de direitos em processo (art. 7º, VI). O aceite do contrato **não**
  é consentimento genérico para outros usos.
- **Dados:** nome, CPF, WhatsApp, IP, navegador, horários. CPF e telefone cifrados em
  repouso (AES-256-GCM); telas e logs mostram só a máscara; evidência guarda o CPF
  mascarado e um HMAC.
- **Retenção:** enquanto o contrato puder ser exigido ou questionado (padrão
  recomendado: 10 anos após o fim do contrato, prazo geral do Código Civil, a confirmar
  com o jurídico de cada cliente). Sessões sem confirmação saem depois de 7 dias.
- **Descarte:** apagar assinatura e evento exige tirar os gatilhos, o que é ação de
  manutenção privilegiada, registrada fora da aplicação, depois do fim da retenção.
- **Acesso:** a equipe da conta com a permissão acima; o titular pode pedir pelo canal
  de privacidade da conta (`app/Lgpd`).

## Instalação em um servidor

1. Imagem com **qpdf** (o `Dockerfile` instala) e PHP com **sodium** (a imagem oficial tem).
2. `docker exec -i yuris_app php /var/www/html/database/migrations/run_151.php --dry-run`, depois sem `--dry-run`.
3. Chave do selo, fora do repositório: `php scripts/contratos_gerar_chave.php /run/yuris-chaves/contratos_ed25519.key`
   (no host: `/home/ubuntu/yuris-chaves`, montado só leitura pelo `docker-compose.yml`). Guarde a chave pública impressa.
4. `.env`: `CONTRATOS_CHAVE_ASSINATURA=/run/yuris-chaves/contratos_ed25519.key`. Opcionais:
   `CONTRATOS_ARMAZENAMENTO` (padrão `storage/contratos`), `CONTRATOS_SEGREDO` (padrão: derivado de
   `APP_ENCRYPTION_KEY`), `CONTRATOS_PROXIES_CONFIAVEIS`, `CONTRATOS_QPDF`.
5. Pasta de arquivos gravável pelo Apache: `mkdir -p storage/contratos && chown www-data:www-data storage/contratos`.
6. Cron: `* * * * * docker exec yuris_app php /var/www/html/bin/contratos_worker.php`.
7. A conta precisa do número de WhatsApp conectado para mandar convites e códigos.

Só em desenvolvimento: `CONTRATOS_WHATSAPP_ARQUIVO_TESTE` grava as mensagens num
arquivo em vez de mandar (ignorado com `APP_ENV=production`).
