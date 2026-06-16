<?php
/**
 * secao-04-dores.php, #dores
 * 8 dores do escritório (copy preservada da v1), em grid de cards "pain".
 */
$pains = [
  ['<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>', 'Prazos espalhados em planilhas', 'Várias abas, várias datas e nenhum responsável claro pelo que vence amanhã.'],
  ['<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>', 'Intimações sem rastreabilidade', 'Publicação recebida, processada por quem? Vinculada a qual processo? Ninguém sabe.'],
  ['<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>', 'Processos sem histórico claro', 'Sem registro de quem editou o quê e quando, qualquer questionamento vira caça ao tesouro.'],
  ['<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>', 'Clientes perdidos no WhatsApp', 'Conversa importante perdida no privado de um celular pessoal, e o histórico se foi.'],
  ['<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>', 'Equipe sem visibilidade', 'Quem está fazendo o quê? Quanto está em aberto? A gestão vê o tempo passar, não o trabalho.'],
  ['<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>', 'Matriz e filial sem controle', 'Cada unidade trabalha do seu jeito, sem padrão e sem o que a matriz precisa enxergar.'],
  ['<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>', 'Falta de auditoria', 'Em qualquer questionamento, interno ou externo, não há registro confiável do que aconteceu.'],
  ['<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>', 'Retrabalho na operação', 'Mesma informação digitada três vezes em três sistemas. O tempo do advogado merece mais.'],
];
?>
<section id="dores" class="lp2-section">
  <div class="lp2-container">
    <div class="lp2-section-head lp2-reveal">
      <span class="lp2-eyebrow">Por que existir</span>
      <h2>Quando a operação jurídica cresce sem sistema, o controle se perde.</h2>
      <p>Com o Yuris, cada processo, tarefa, intimação e atendimento ganha contexto, responsável e histórico.</p>
    </div>

    <div class="lp2-grid lp2-grid-4 lp2-reveal">
      <?php foreach ($pains as [$icon, $titulo, $desc]): ?>
      <div class="lp2-card lp2-pain">
        <div class="lp2-card-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $icon ?></svg></div>
        <h3><?= $titulo ?></h3>
        <p><?= $desc ?></p>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="lp2-section-cta lp2-reveal">
      <a href="<?= wa('Olá Bruno, quero resolver essas dores no meu escritório com o Yuris!') ?>" target="_blank" rel="noopener" class="lp2-btn lp2-btn-primary lp2-btn-wa">
        <?= $waSvg ?>
        Resolver isso no meu escritório
      </a>
    </div>
  </div>
</section>
