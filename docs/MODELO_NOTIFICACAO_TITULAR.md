# Modelo de Comunicação ao Titular — Incidente de Segurança

**Base legal:** LGPD Art. 48 §1 — comunicação direta aos titulares afetados por incidente que possa acarretar risco ou dano relevante.

> Este é um **modelo de partida**.  O Encarregado (DPO) deve adaptar tom, nível de detalhe e canal de acordo com o perfil do destinatário e a natureza do incidente.  Comunicação em **linguagem clara, simples, sem jargão técnico** — Art. 6º VI.
> Envio por e-mail individualizado é o canal preferencial.

---

## Assunto sugerido

> **Aviso importante sobre seus dados pessoais — Yuris** (incidente INC-[NNNNNN])

---

## Corpo da mensagem

Olá, [PRIMEIRO NOME DO TITULAR],

Estamos entrando em contato para informar você sobre um incidente de segurança que pode ter afetado seus dados pessoais cadastrados na Yuris.  **Nosso compromisso com transparência exige que você saiba o que aconteceu, o que estamos fazendo e o que recomendamos que você faça.**

### O que aconteceu

[Descrição em 2-4 frases, em linguagem acessível.  Exemplo:
*"No dia DD/MM/AAAA, identificamos um acesso não autorizado a uma parte do nosso sistema.  Nossa equipe técnica detectou e bloqueou o acesso em poucas horas, mas durante esse período os dados listados abaixo podem ter sido visualizados por terceiros."*]

### Quais dados podem ter sido expostos

Em relação ao seu cadastro especificamente, podem ter sido acessados:

- [marcar os que se aplicam]
- [ ] Nome, e-mail e telefone
- [ ] CPF / RG / OAB
- [ ] Dados bancários ou financeiros
- [ ] Dados de processos jurídicos vinculados ao seu cadastro
- [ ] Senha (**não em texto aberto** — armazenamos apenas a versão criptografada; mesmo assim, recomendamos troca preventiva)
- [ ] Outros: [...]

**O que NÃO foi afetado:**

- [Liste para tranquilizar — ex.: "documentos enviados como anexos não foram acessados", "histórico de pagamentos completo permaneceu seguro".]

### O que já fizemos

- Bloqueamos o vetor de acesso imediatamente após a detecção.
- [Listar 2-3 medidas — ex.: revogamos sessões ativas, rotacionamos chaves de acesso, contratamos auditoria externa.]
- Comunicamos a **Autoridade Nacional de Proteção de Dados (ANPD)** sob protocolo [NÚMERO ANPD, se já houver].
- Reforçamos os controles para evitar repetição.

### O que recomendamos que você faça

1. **Troque sua senha da Yuris** assim que possível — acesse `https://[DOMÍNIO]/configuracoes/seguranca.php`.
2. Se você usa a mesma senha em outros serviços, **troque também nesses outros serviços** (boa prática geral de segurança).
3. **Ative a verificação em dois fatores (2FA)**, caso ainda não tenha — aumenta significativamente a proteção da sua conta.
4. **Desconfie de contatos suspeitos**: golpistas podem tentar se passar pela Yuris citando este incidente.  Nunca pediremos sua senha por e-mail, telefone ou WhatsApp.
5. [Se houver risco de fraude financeira/identidade:] **Monitore extratos bancários e cadastros como Serasa, SPC e Receita Federal nas próximas semanas.**

### Onde tirar dúvidas

- E-mail do Encarregado pela Proteção de Dados (DPO): **[E-MAIL DPO]**
- Canal LGPD: **[https://[DOMÍNIO]/lgpd]**
- Você também pode exercer seus direitos (Art. 18) em: **[https://[DOMÍNIO]/lgpd/solicitar.php]**

### Nossas desculpas

Sabemos do impacto que uma situação dessas gera.  Pedimos desculpas e nos comprometemos com você a manter total transparência sobre os próximos passos.  Caso a investigação revele novos detalhes que afetem você, entraremos em contato novamente.

Atenciosamente,

**[NOME DO ENCARREGADO]**
Encarregado de Proteção de Dados — Yuris
**[E-MAIL DPO]**

---

## Versão curta para SMS / WhatsApp (caracteres limitados)

> *Yuris: detectamos incidente de segurança que pode ter afetado seu cadastro. Troque sua senha em [URL CURTA] e ative 2FA. Dúvidas: [E-MAIL DPO]. Detalhes completos foram enviados ao seu e-mail.*

(Limite ~250 caracteres — adaptar.)

---

## Versão para aviso público (quando individualização for impossível)

> **Comunicado de Incidente de Segurança — Yuris**
> No período entre [DATA INICIAL] e [DATA FINAL], identificamos [descrição sucinta].  A investigação indica que [breve avaliação de impacto].  Já implementamos medidas de contenção e notificamos a ANPD sob protocolo [NÚMERO].
> Recomendamos a todos os usuários: trocar senha, ativar 2FA e ficar atentos a contatos suspeitos.
> Dúvidas e exercício de direitos: dpo@[DOMÍNIO] · [https://[DOMÍNIO]/lgpd]
> **Data desta publicação:** [DD/MM/AAAA]

---

> **Notas para o DPO ao usar este modelo:**
> - Personalizar com fatos reais — não enviar a versão "modelo" preenchida só parcialmente.
> - **NUNCA** revelar IPs, hashes, vulnerabilidades técnicas específicas ou nomes de outros titulares afetados.
> - Em incidentes envolvendo **dados sensíveis** (Art. 5 II), aumentar o nível de detalhe sobre mitigações e oferecer canal direto de atendimento (telefone, não só e-mail).
> - Manter cópia idêntica do que foi enviado anexada ao registro do incidente em `Painel Master → Incidentes → adicionar evento "comunicado_titular"`.
