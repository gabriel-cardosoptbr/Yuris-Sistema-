<?php
/**
 * secao-04-dores.php, #dores
 * 8 dores do escritório (texto preservado), em composição editorial: o título
 * fica parado numa coluna enquanto a lista numerada corre na outra, separada por
 * linhas finas. De propósito não são cards: é a seção que quebra o ritmo da
 * grade.
 */
$pains = [
  ['Prazos espalhados em planilhas', 'Várias abas, várias datas e nenhum responsável claro pelo que vence amanhã.'],
  ['Intimações sem rastreabilidade', 'Publicação recebida, processada por quem? Vinculada a qual processo? Ninguém sabe.'],
  ['Processos sem histórico claro', 'Sem registro de quem editou o quê e quando, qualquer questionamento vira caça ao tesouro.'],
  ['Clientes perdidos no WhatsApp', 'Conversa importante perdida no privado de um celular pessoal, e o histórico se foi.'],
  ['Equipe sem visibilidade', 'Quem está fazendo o quê? Quanto está em aberto? A gestão vê o tempo passar, não o trabalho.'],
  ['Matriz e filial sem controle', 'Cada unidade trabalha do seu jeito, sem padrão e sem o que a matriz precisa enxergar.'],
  ['Falta de auditoria', 'Em qualquer questionamento, interno ou externo, não há registro confiável do que aconteceu.'],
  ['Retrabalho na operação', 'Mesma informação digitada três vezes em três sistemas. O tempo do advogado merece mais.'],
];
?>
<section id="dores" class="ys-section">
  <div class="ys-container">
    <div class="ys-dores">
      <div class="ys-dores-side ys-reveal">
        <span class="ys-eyebrow">Por que existir</span>
        <h2 class="ys-h2">Quando a operação jurídica cresce sem sistema, o controle se perde.</h2>
        <p class="ys-lede">Com o Yuris, cada processo, tarefa, intimação e atendimento ganha contexto, responsável e histórico.</p>
        <?= lp2_wa_btn('Olá Bruno, quero resolver essas dores no meu escritório com o Yuris!', 'Resolver isso no meu escritório') ?>
      </div>

      <ol class="ys-dores-list">
        <?php foreach ($pains as $i => [$titulo, $desc]): ?>
        <li class="ys-reveal">
          <span class="ys-dores-n"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
          <h3><?= $titulo ?></h3>
          <p><?= $desc ?></p>
        </li>
        <?php endforeach; ?>
      </ol>
    </div>
  </div>
</section>
