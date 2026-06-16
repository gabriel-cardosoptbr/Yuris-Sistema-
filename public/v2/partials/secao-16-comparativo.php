<?php /* secao-16-comparativo.php, #comparativo — palco Antes × Com o Yuris.
   Padrão-ouro = cena Processos. Todas as 7 cenas seguem a MESMA arquitetura
   (viewBox 1440x520; Antes concreto à esquerda, rosa dos ventos no centro,
   mini-interface Yuris à direita) e têm versão vertical dedicada p/ o mobile.
   Animação (loop composto) em assets/v2/js/sections/comparativo.js. */
$antes = [
  'Processos espalhados em pastas e planilhas.',
  'Prazos lembrados de memória.',
  'Conversas perdidas no WhatsApp pessoal.',
  'Tarefas sem dono e sem prazo.',
  'Intimações sem histórico de vínculo.',
  'Equipe sem visibilidade da operação.',
  'Baixa rastreabilidade em qualquer auditoria.',
];
$depois = [
  'Processos centralizados, com vínculos e histórico.',
  'Prazos organizados, com responsáveis e alertas.',
  'Comunicação conectada a cliente e processo.',
  'Tarefas com responsáveis e status claros.',
  'Intimações vinculadas e auditáveis.',
  'Gestão por matriz, filial e advogado.',
  'Histórico e auditoria como parte da operação.',
];
$titulos = ['Processos', 'Prazos', 'Comunicação', 'Tarefas', 'Intimações', 'Gestão', 'Auditoria'];

/* Núcleo = rosa dos ventos da marca (mesmo símbolo do hero). */
if (!function_exists('lp2_rosa')) {
  function lp2_rosa($cx, $cy, $R) {
    $s = round($R / 95, 4);
    $L = '0,-92 4,-8 0,0 -4,-8'; $Sh = '0,-60 3,-7 0,0 -3,-7';
    $rays = '';
    foreach (array(0, 90, 180, 270) as $a) { $rays .= '<polygon class="s-ray s-ray-l" points="' . $L . '" transform="rotate(' . $a . ' 0 0)"/>'; }
    foreach (array(45, 135, 225, 315) as $a) { $rays .= '<polygon class="s-ray s-ray-s" points="' . $Sh . '" transform="rotate(' . $a . ' 0 0)"/>'; }
    return '<g class="scn-core" data-core><g class="s-core-inner" transform="translate(' . $cx . ' ' . $cy . ') scale(' . $s . ')">'
      . '<circle class="s-core-ring s-core-ring-out" cx="0" cy="0" r="95"/><circle class="s-core-ring s-core-ring-in" cx="0" cy="0" r="70"/>'
      . '<g class="s-core-rays">' . $rays . '</g><circle class="s-core-hub" cx="0" cy="0" r="9"/><circle class="s-core-hub-dot" cx="0" cy="0" r="4"/></g></g>';
  }
  /* Casca da mini-interface Yuris, idêntica em todas as cenas (consistência). */
  function lp2_yshell() {
    return '<rect class="s-win" x="822" y="120" width="494" height="288" rx="12"/>'
      . '<line class="s-win-head" x1="822" y1="158" x2="1316" y2="158"/>'
      . '<circle class="s-dot3" cx="842" cy="139" r="4"/><circle class="s-dot3" cx="858" cy="139" r="4"/><circle class="s-dot3" cx="874" cy="139" r="4"/>'
      . '<rect class="s-blue" x="902" y="134" width="120" height="10" rx="3"/>'
      . '<circle class="s-check-bg" cx="1294" cy="150" r="14"/><polyline class="s-check" points="1287 150 1293 156 1302 144"/>';
  }
  function lp2_yshell_v() {
    return '<rect class="s-win" x="40" y="360" width="320" height="232" rx="12"/>'
      . '<line class="s-win-head" x1="40" y1="396" x2="360" y2="396"/>'
      . '<circle class="s-dot3" cx="58" cy="378" r="3.4"/><circle class="s-dot3" cx="72" cy="378" r="3.4"/><circle class="s-dot3" cx="86" cy="378" r="3.4"/>'
      . '<rect class="s-blue" x="110" y="373" width="110" height="9" rx="3"/>'
      . '<circle class="s-check-bg" cx="336" cy="384" r="13"/><polyline class="s-check" points="329 384 335 390 344 378"/>';
  }
  /* Coluna de metadados Yuris (responsável + status + infos), reutilizável. */
  function lp2_ymeta() {
    return '<rect class="s-meta" x="1160" y="178" width="138" height="200" rx="8"/>'
      . '<circle class="s-avatar" cx="1182" cy="202" r="11"/><rect class="s-bluefaint" x="1200" y="197" width="78" height="7" rx="3"/>'
      . '<rect class="s-status" x="1176" y="226" width="106" height="22" rx="11"/><circle class="s-blue" cx="1190" cy="237" r="4"/><rect class="s-blue" x="1202" y="233" width="62" height="8" rx="3"/>'
      . '<rect class="s-bluefaint" x="1176" y="268" width="58" height="7" rx="3"/><rect class="s-bluefaint" x="1176" y="282" width="98" height="7" rx="3"/>'
      . '<rect class="s-bluefaint" x="1176" y="312" width="46" height="7" rx="3"/><rect class="s-blue" x="1176" y="326" width="84" height="11" rx="3"/>'
      . '<rect class="s-blue" x="1176" y="352" width="106" height="14" rx="5"/>';
  }
  function lp2_alert($cx, $cy, $r = 11) {
    return '<circle class="s-alert" cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '"/>'
      . '<line class="s-alert-i" x1="' . $cx . '" y1="' . ($cy - 5) . '" x2="' . $cx . '" y2="' . ($cy + 2) . '"/>'
      . '<circle class="s-alert-d" cx="' . $cx . '" cy="' . ($cy + 6) . '" r="1.5"/>';
  }
}
$nucleoP = lp2_rosa(645, 245, 50);
$nucleoV = lp2_rosa(200, 300, 46);

/* ─────────── CENA 0 — PROCESSOS (padrão-ouro, mantida) ─────────── */
$P_before =
    '<rect class="s-sheet" x="100" y="136" width="288" height="206" rx="9"/><path class="s-sheet-head" d="M100 145 a9 9 0 0 1 9 -9 h270 a9 9 0 0 1 9 9 v21 h-288 z"/>'
  . '<circle class="s-dim2" cx="118" cy="152" r="3"/><circle class="s-dim2" cx="130" cy="152" r="3"/><circle class="s-dim2" cx="142" cy="152" r="3"/>'
  . '<rect class="s-formula" x="116" y="178" width="184" height="14" rx="3"/><rect class="s-formula" x="312" y="178" width="64" height="14" rx="3"/>'
  . '<line class="s-grid" x1="100" y1="206" x2="388" y2="206"/><line class="s-grid" x1="138" y1="206" x2="138" y2="342"/><line class="s-grid" x1="214" y1="206" x2="214" y2="342"/><line class="s-grid" x1="300" y1="206" x2="300" y2="342"/><line class="s-grid" x1="100" y1="240" x2="388" y2="240"/><line class="s-grid" x1="100" y1="274" x2="388" y2="274"/><line class="s-grid" x1="100" y1="308" x2="388" y2="308"/>'
  . '<text class="s-lbl" x="170" y="201">A</text><text class="s-lbl" x="250" y="201">B</text><text class="s-lbl" x="338" y="201">C</text><text class="s-lbl" x="114" y="228">1</text><text class="s-lbl" x="114" y="262">2</text><text class="s-lbl" x="114" y="296">3</text><text class="s-lbl" x="114" y="330">4</text>'
  . '<rect class="s-cell" x="148" y="218" width="52" height="9" rx="2"/><rect class="s-cell" x="224" y="218" width="40" height="9" rx="2"/><rect class="s-cell" x="312" y="218" width="44" height="9" rx="2"/><rect class="s-cell" x="148" y="252" width="46" height="9" rx="2"/><rect class="s-cell" x="224" y="252" width="42" height="9" rx="2"/>'
  . '<rect class="s-hl" x="139" y="280" width="248" height="26" rx="2"/><rect class="s-cell" x="148" y="288" width="50" height="9" rx="2"/><rect class="s-cell" x="224" y="288" width="40" height="9" rx="2"/><rect class="s-cell" x="312" y="288" width="36" height="9" rx="2"/>'
  . '<rect class="s-sel" x="306" y="248" width="50" height="18" rx="2"/>'
  . '<rect class="s-tab" x="112" y="350" width="54" height="16" rx="3"/><rect class="s-tab" x="172" y="350" width="54" height="16" rx="3"/>'
  . '<path class="s-folder" d="M402 168 h40 l9 11 h47 a7 7 0 0 1 7 7 v56 a7 7 0 0 1 -7 7 h-96 a7 7 0 0 1 -7 -7 v-67 a7 7 0 0 1 7 -7 z"/>'
  . '<rect class="s-doc" x="410" y="262" width="50" height="64" rx="4"/><path class="s-doc-fold" d="M448 262 v12 h12"/><line class="s-docline" x1="418" y1="280" x2="452" y2="280"/><line class="s-docline" x1="418" y1="292" x2="452" y2="292"/><line class="s-docline" x1="418" y1="304" x2="444" y2="304"/>'
  . '<rect class="s-doc" x="466" y="284" width="44" height="56" rx="4" transform="rotate(9 488 312)"/>'
  . '<rect class="s-label" x="96" y="386" width="74" height="22" rx="11"/><line class="s-docline" x1="110" y1="397" x2="156" y2="397"/>'
  . lp2_alert(404, 246);
$P_after =
    '<rect class="s-card" x="844" y="178" width="300" height="88" rx="8"/><rect class="s-tag" x="858" y="190" width="48" height="9" rx="3"/><rect class="s-blue" x="858" y="208" width="184" height="9" rx="3"/><rect class="s-bluefaint" x="858" y="224" width="120" height="7" rx="2"/><rect class="s-bluefaint" x="858" y="238" width="152" height="7" rx="2"/>'
  . '<rect class="s-meta" x="1160" y="178" width="138" height="200" rx="8"/><circle class="s-avatar" cx="1182" cy="202" r="11"/><rect class="s-bluefaint" x="1200" y="197" width="78" height="7" rx="3"/><rect class="s-status" x="1176" y="228" width="106" height="22" rx="11"/><rect class="s-blue" x="1190" y="235" width="60" height="8" rx="3"/><rect class="s-bluefaint" x="1176" y="268" width="44" height="7" rx="3"/><rect class="s-blue" x="1228" y="266" width="54" height="11" rx="3"/>'
  . '<rect class="s-card" x="844" y="282" width="120" height="96" rx="8"/><rect class="s-doc-mini" x="868" y="300" width="48" height="60" rx="4"/><path class="s-doc-foldb" d="M904 300 v10 h10"/>'
  . '<rect class="s-bluefaint" x="992" y="296" width="80" height="7" rx="3"/><line class="s-timeline" x1="992" y1="324" x2="1130" y2="324"/><circle class="s-blue" cx="1004" cy="324" r="5"/><circle class="s-blue" cx="1050" cy="324" r="5"/><circle class="s-blue" cx="1096" cy="324" r="5"/>'
  . '<line class="s-link" x1="1144" y1="208" x2="1160" y2="208"/>';

/* ─────────── CENA 1 — PRAZOS ─────────── */
$b1 =
    '<rect class="s-sheet" x="100" y="150" width="248" height="186" rx="9"/><path class="s-sheet-head" d="M100 159 a9 9 0 0 1 9 -9 h230 a9 9 0 0 1 9 9 v22 h-248 z"/>'
  . '<rect class="s-cell" x="116" y="163" width="74" height="11" rx="3"/><circle class="s-dim2" cx="320" cy="169" r="3"/><circle class="s-dim2" cx="332" cy="169" r="3"/>'
  . '<rect class="s-formula" x="116" y="190" width="216" height="12" rx="2"/>'
  . '<line class="s-grid" x1="100" y1="214" x2="348" y2="214"/><line class="s-grid" x1="100" y1="244" x2="348" y2="244"/><line class="s-grid" x1="100" y1="274" x2="348" y2="274"/><line class="s-grid" x1="100" y1="304" x2="348" y2="304"/>'
  . '<line class="s-grid" x1="150" y1="206" x2="150" y2="336"/><line class="s-grid" x1="200" y1="206" x2="200" y2="336"/><line class="s-grid" x1="248" y1="206" x2="248" y2="336"/><line class="s-grid" x1="298" y1="206" x2="298" y2="336"/>'
  . '<rect class="s-cell" x="116" y="222" width="20" height="9" rx="2"/><rect class="s-cell" x="216" y="222" width="20" height="9" rx="2"/><rect class="s-cell" x="166" y="252" width="20" height="9" rx="2"/><rect class="s-cell" x="266" y="252" width="20" height="9" rx="2"/><rect class="s-cell" x="116" y="282" width="20" height="9" rx="2"/>'
  . '<rect class="s-hl" x="250" y="296" width="48" height="30" rx="4"/><rect class="s-cell" x="264" y="306" width="20" height="9" rx="2"/>'
  . '<circle class="s-doc" cx="452" cy="178" r="36" fill="none"/><line class="s-docline" x1="452" y1="178" x2="452" y2="152"/><line class="s-docline" x1="452" y1="178" x2="472" y2="188"/><circle class="s-dim2" cx="452" cy="178" r="3"/>'
  . '<rect class="s-doc" x="406" y="250" width="92" height="80" rx="5" transform="rotate(-6 452 290)"/><line class="s-docline" x1="420" y1="272" x2="478" y2="266" transform="rotate(-6 452 290)"/><line class="s-docline" x1="420" y1="288" x2="472" y2="283" transform="rotate(-6 452 290)"/>'
  . lp2_alert(360, 156);
$a1 =
    '<rect class="s-card" x="844" y="178" width="300" height="88" rx="8"/><rect class="s-tag" x="858" y="190" width="56" height="9" rx="3"/><rect class="s-blue" x="858" y="208" width="176" height="9" rx="3"/><rect class="s-bluefaint" x="858" y="224" width="118" height="7" rx="2"/><rect class="s-bluefaint" x="858" y="238" width="150" height="7" rx="2"/>'
  . '<circle class="s-doc-mini" cx="1116" cy="222" r="17" fill="none"/><line class="s-timeline" x1="1116" y1="222" x2="1116" y2="210"/><line class="s-timeline" x1="1116" y1="222" x2="1127" y2="228"/>'
  . lp2_ymeta()
  . '<rect class="s-card" x="844" y="282" width="120" height="96" rx="8"/><rect class="s-tag" x="858" y="294" width="38" height="8" rx="3"/><line class="s-timeline" x1="858" y1="318" x2="948" y2="318"/><line class="s-timeline" x1="858" y1="342" x2="948" y2="342"/><circle class="s-blue" cx="872" cy="330" r="4"/><circle class="s-blue" cx="904" cy="330" r="4"/><circle class="s-blue" cx="936" cy="354" r="4"/>'
  . '<rect class="s-bluefaint" x="992" y="296" width="96" height="7" rx="3"/><line class="s-timeline" x1="992" y1="324" x2="1130" y2="324"/><circle class="s-blue" cx="1004" cy="324" r="5"/><circle class="s-blue" cx="1050" cy="324" r="5"/><circle class="s-blue" cx="1096" cy="324" r="5"/><rect class="s-bluefaint" x="992" y="346" width="120" height="7" rx="3"/>'
  . '<line class="s-link" x1="1144" y1="208" x2="1160" y2="208"/>';

/* ─────────── CENA 2 — COMUNICAÇÃO ─────────── */
$b2 =
    '<rect class="s-doc" x="100" y="138" width="150" height="208" rx="18" fill="none"/><rect class="s-formula" x="118" y="156" width="48" height="9" rx="3"/><circle class="s-dim2" cx="175" cy="338" r="6" fill="none"/>'
  . '<rect class="s-cell" x="118" y="184" width="86" height="24" rx="11"/><rect class="s-formula" x="146" y="216" width="86" height="24" rx="11"/><rect class="s-cell" x="118" y="248" width="70" height="22" rx="10"/><rect class="s-formula" x="156" y="278" width="76" height="22" rx="10"/>'
  . '<rect class="s-formula" x="286" y="150" width="104" height="32" rx="15"/><rect class="s-cell" x="320" y="196" width="92" height="30" rx="14"/><rect class="s-formula" x="286" y="240" width="84" height="28" rx="13"/>'
  . '<rect class="s-doc" x="300" y="284" width="50" height="62" rx="4"/><path class="s-doc-fold" d="M338 284 v11 h11"/><line class="s-docline" x1="310" y1="302" x2="342" y2="302"/><line class="s-docline" x1="310" y1="314" x2="342" y2="314"/>'
  . lp2_alert(238, 150);
$a2 =
    '<rect class="s-card" x="844" y="178" width="300" height="100" rx="8"/><circle class="s-avatar" cx="866" cy="200" r="12"/><rect class="s-tag" x="886" y="190" width="60" height="9" rx="3"/><rect class="s-bluefaint" x="886" y="206" width="92" height="7" rx="3"/>'
  . '<rect class="s-blue" x="858" y="230" width="150" height="22" rx="11"/><rect class="s-status" x="1024" y="256" width="106" height="20" rx="10"/>'
  . lp2_ymeta()
  . '<rect class="s-card" x="844" y="294" width="120" height="84" rx="8"/><rect class="s-doc-mini" x="864" y="310" width="40" height="52" rx="4"/><path class="s-doc-foldb" d="M896 310 v9 h9"/><rect class="s-bluefaint" x="918" y="320" width="34" height="6" rx="3"/><rect class="s-bluefaint" x="918" y="332" width="30" height="6" rx="3"/>'
  . '<rect class="s-bluefaint" x="992" y="298" width="80" height="7" rx="3"/><line class="s-timeline" x1="992" y1="326" x2="1130" y2="326"/><circle class="s-blue" cx="1004" cy="326" r="5"/><circle class="s-blue" cx="1050" cy="326" r="5"/><circle class="s-blue" cx="1096" cy="326" r="5"/><rect class="s-bluefaint" x="992" y="348" width="116" height="7" rx="3"/>'
  . '<line class="s-link" x1="1144" y1="220" x2="1160" y2="220"/>';

/* ─────────── CENA 3 — TAREFAS ─────────── */
$b3 =
    '<rect class="s-doc" x="100" y="150" width="150" height="58" rx="7"/><rect class="s-doc" x="110" y="164" width="22" height="22" rx="4" fill="none"/><rect class="s-formula" x="142" y="168" width="92" height="9" rx="3"/><rect class="s-cell" x="142" y="186" width="60" height="7" rx="3"/>'
  . '<rect class="s-doc" x="280" y="138" width="150" height="58" rx="7"/><rect class="s-doc" x="290" y="152" width="22" height="22" rx="4" fill="none"/><line class="s-docline" x1="293" y1="163" x2="309" y2="163"/><rect class="s-formula" x="322" y="156" width="92" height="9" rx="3"/><rect class="s-cell" x="322" y="174" width="54" height="7" rx="3"/>'
  . '<rect class="s-doc" x="130" y="246" width="150" height="58" rx="7"/><rect class="s-doc" x="140" y="260" width="22" height="22" rx="4" fill="none"/><rect class="s-formula" x="172" y="264" width="92" height="9" rx="3"/><rect class="s-cell" x="172" y="282" width="48" height="7" rx="3"/>'
  . '<rect class="s-doc" x="320" y="252" width="120" height="84" rx="6" transform="rotate(5 380 294)"/><rect class="s-formula" x="334" y="270" width="80" height="8" rx="3" transform="rotate(5 380 294)"/><rect class="s-cell" x="334" y="286" width="56" height="7" rx="3" transform="rotate(5 380 294)"/>'
  . lp2_alert(258, 244);
$a3 =
    '<rect class="s-card" x="844" y="178" width="300" height="62" rx="8"/><rect class="s-doc-mini" x="858" y="194" width="26" height="26" rx="5" fill="none"/><polyline class="s-check" points="864 207 869 213 879 200"/><rect class="s-blue" x="898" y="196" width="150" height="9" rx="3"/><rect class="s-bluefaint" x="898" y="214" width="96" height="7" rx="3"/><circle class="s-avatar" cx="1118" cy="209" r="11"/>'
  . '<rect class="s-card" x="844" y="252" width="300" height="62" rx="8"/><rect class="s-doc-mini" x="858" y="268" width="26" height="26" rx="5" fill="none"/><rect class="s-blue" x="898" y="270" width="132" height="9" rx="3"/><rect class="s-bluefaint" x="898" y="288" width="110" height="7" rx="3"/><circle class="s-avatar" cx="1118" cy="283" r="11"/>'
  . '<rect class="s-card" x="844" y="326" width="300" height="56" rx="8"/><rect class="s-doc-mini" x="858" y="340" width="26" height="26" rx="5" fill="none"/><rect class="s-blue" x="898" y="344" width="120" height="9" rx="3"/><circle class="s-avatar" cx="1118" cy="353" r="11"/>'
  . lp2_ymeta()
  . '<line class="s-link" x1="1144" y1="209" x2="1160" y2="209"/>';

/* ─────────── CENA 4 — INTIMAÇÕES ─────────── */
$b4 =
    '<rect class="s-sheet" x="104" y="138" width="206" height="208" rx="8"/><path class="s-sheet-head" d="M104 146 a8 8 0 0 1 8 -8 h190 a8 8 0 0 1 8 8 v20 h-206 z"/><circle class="s-dim2" cx="207" cy="190" r="20" fill="none"/><path class="s-docline" d="M199 190 l6 6 l11 -13"/>'
  . '<line class="s-docline" x1="122" y1="232" x2="292" y2="232"/><line class="s-docline" x1="122" y1="250" x2="292" y2="250"/><line class="s-docline" x1="122" y1="268" x2="262" y2="268"/><line class="s-docline" x1="122" y1="296" x2="292" y2="296"/><line class="s-docline" x1="122" y1="314" x2="248" y2="314"/>'
  . '<rect class="s-doc" x="346" y="170" width="108" height="78" rx="6"/><polyline class="s-doc-fold" points="346 176 400 214 454 176"/>'
  . '<rect class="s-doc" x="360" y="276" width="86" height="64" rx="5" transform="rotate(-7 403 308)"/><line class="s-docline" x1="372" y1="298" x2="430" y2="294" transform="rotate(-7 403 308)"/><line class="s-docline" x1="372" y1="314" x2="424" y2="310" transform="rotate(-7 403 308)"/>'
  . lp2_alert(320, 152);
$a4 =
    '<rect class="s-card" x="844" y="178" width="300" height="100" rx="8"/><rect class="s-doc-mini" x="858" y="194" width="40" height="52" rx="4"/><path class="s-doc-foldb" d="M890 194 v9 h9"/><rect class="s-tag" x="912" y="196" width="58" height="9" rx="3"/><rect class="s-blue" x="912" y="214" width="160" height="9" rx="3"/><rect class="s-bluefaint" x="912" y="230" width="120" height="7" rx="3"/><rect class="s-status" x="912" y="248" width="120" height="20" rx="10"/>'
  . lp2_ymeta()
  . '<rect class="s-card" x="844" y="294" width="120" height="84" rx="8"/><path class="s-doc-foldb" d="M904 312 l18 6 v14 q0 15 -18 20 q-18 -5 -18 -20 v-14 z" fill="none"/><polyline class="s-check" points="896 338 903 345 916 330"/>'
  . '<rect class="s-bluefaint" x="992" y="298" width="80" height="7" rx="3"/><line class="s-timeline" x1="992" y1="326" x2="1130" y2="326"/><circle class="s-blue" cx="1004" cy="326" r="5"/><circle class="s-blue" cx="1050" cy="326" r="5"/><circle class="s-blue" cx="1096" cy="326" r="5"/><rect class="s-bluefaint" x="992" y="348" width="112" height="7" rx="3"/>'
  . '<line class="s-link" x1="1144" y1="220" x2="1160" y2="220"/>';

/* ─────────── CENA 5 — GESTÃO ─────────── */
$b5 =
    '<circle class="s-doc" cx="150" cy="170" r="22" fill="none"/><circle class="s-dim2" cx="150" cy="162" r="6"/><path class="s-docline" d="M139 184 a11 9 0 0 1 22 0"/>'
  . '<circle class="s-doc" cx="300" cy="150" r="22" fill="none"/><circle class="s-dim2" cx="300" cy="142" r="6"/><path class="s-docline" d="M289 164 a11 9 0 0 1 22 0"/>'
  . '<circle class="s-doc" cx="430" cy="196" r="22" fill="none"/><circle class="s-dim2" cx="430" cy="188" r="6"/><path class="s-docline" d="M419 210 a11 9 0 0 1 22 0"/>'
  . '<circle class="s-doc" cx="170" cy="300" r="22" fill="none"/><circle class="s-dim2" cx="170" cy="292" r="6"/><path class="s-docline" d="M159 314 a11 9 0 0 1 22 0"/>'
  . '<circle class="s-doc" cx="338" cy="306" r="22" fill="none"/><circle class="s-dim2" cx="338" cy="298" r="6"/><path class="s-docline" d="M327 320 a11 9 0 0 1 22 0"/>'
  . '<line class="s-docline" x1="172" y1="172" x2="278" y2="152" stroke-dasharray="4 6"/><line class="s-docline" x1="316" y1="300" x2="408" y2="214" stroke-dasharray="4 6"/>'
  . lp2_alert(258, 232);
$a5 =
    '<circle class="s-doc-mini" cx="1015" cy="180" r="20" fill="none"/><circle class="s-blue" cx="1015" cy="180" r="7"/>'
  . '<line class="s-link" x1="1015" y1="200" x2="900" y2="252"/><line class="s-link" x1="1015" y1="200" x2="1015" y2="248"/><line class="s-link" x1="1015" y1="200" x2="1130" y2="252"/>'
  . '<circle class="s-doc-mini" cx="900" cy="270" r="18" fill="none"/><circle class="s-blue" cx="900" cy="270" r="6"/><circle class="s-doc-mini" cx="1015" cy="270" r="18" fill="none"/><circle class="s-blue" cx="1015" cy="270" r="6"/><circle class="s-doc-mini" cx="1130" cy="270" r="18" fill="none"/><circle class="s-blue" cx="1130" cy="270" r="6"/>'
  . '<rect class="s-card" x="856" y="312" width="88" height="64" rx="8"/><rect class="s-blue" x="870" y="326" width="36" height="14" rx="3"/><rect class="s-bluefaint" x="870" y="350" width="60" height="7" rx="3"/><rect class="s-bluefaint" x="870" y="362" width="44" height="7" rx="3"/>'
  . '<rect class="s-card" x="958" y="312" width="88" height="64" rx="8"/><rect class="s-blue" x="972" y="326" width="36" height="14" rx="3"/><rect class="s-bluefaint" x="972" y="350" width="60" height="7" rx="3"/><rect class="s-bluefaint" x="972" y="362" width="40" height="7" rx="3"/>'
  . '<rect class="s-card" x="1060" y="312" width="88" height="64" rx="8"/><rect class="s-blue" x="1074" y="326" width="36" height="14" rx="3"/><rect class="s-bluefaint" x="1074" y="350" width="60" height="7" rx="3"/><rect class="s-bluefaint" x="1074" y="362" width="48" height="7" rx="3"/>'
  . '<rect class="s-tag" x="982" y="150" width="58" height="10" rx="3"/>';

/* ─────────── CENA 6 — AUDITORIA ─────────── */
$b6 =
    '<rect class="s-doc" x="104" y="150" width="220" height="40" rx="6"/><circle class="s-dim2" cx="126" cy="170" r="7"/><rect class="s-formula" x="146" y="160" width="120" height="8" rx="3"/><rect class="s-cell" x="146" y="174" width="80" height="6" rx="3"/>'
  . '<rect class="s-doc" x="124" y="208" width="220" height="40" rx="6"/><circle class="s-dim2" cx="146" cy="228" r="7"/><rect class="s-formula" x="166" y="218" width="120" height="8" rx="3"/>'
  . '<rect class="s-doc" x="104" y="266" width="200" height="40" rx="6"/><circle class="s-dim2" cx="126" cy="286" r="7"/><rect class="s-formula" x="146" y="276" width="100" height="8" rx="3"/><rect class="s-cell" x="146" y="290" width="64" height="6" rx="3"/>'
  . '<line class="s-docline" x1="126" y1="190" x2="126" y2="208" stroke-dasharray="3 5"/><line class="s-docline" x1="146" y1="248" x2="126" y2="266" stroke-dasharray="3 5"/>'
  . '<circle class="s-dim2" cx="360" cy="200" r="3"/><circle class="s-dim2" cx="392" cy="232" r="3"/><circle class="s-dim2" cx="356" cy="268" r="3"/>'
  . lp2_alert(330, 158);
$a6 =
    '<line class="s-timeline" x1="884" y1="184" x2="884" y2="372"/>'
  . '<circle class="s-blue" cx="884" cy="200" r="7"/><circle class="s-blue" cx="884" cy="262" r="7"/><circle class="s-blue" cx="884" cy="324" r="7"/>'
  . '<rect class="s-card" x="916" y="180" width="230" height="50" rx="8"/><rect class="s-tag" x="930" y="192" width="50" height="8" rx="3"/><rect class="s-blue" x="930" y="208" width="150" height="8" rx="3"/><rect class="s-bluefaint" x="1096" y="196" width="36" height="7" rx="3"/>'
  . '<rect class="s-card" x="916" y="242" width="230" height="50" rx="8"/><rect class="s-tag" x="930" y="254" width="50" height="8" rx="3"/><rect class="s-blue" x="930" y="270" width="132" height="8" rx="3"/><rect class="s-bluefaint" x="1096" y="258" width="36" height="7" rx="3"/>'
  . '<rect class="s-card" x="916" y="304" width="230" height="50" rx="8"/><rect class="s-tag" x="930" y="316" width="50" height="8" rx="3"/><rect class="s-blue" x="930" y="332" width="142" height="8" rx="3"/><rect class="s-bluefaint" x="1096" y="320" width="36" height="7" rx="3"/>'
  . '<path class="s-doc-foldb" d="M1234 196 l26 9 v20 q0 22 -26 30 q-26 -8 -26 -30 v-20 z" fill="none"/><polyline class="s-check" points="1222 232 1232 242 1248 224"/><rect class="s-bluefaint" x="1198" y="276" width="74" height="7" rx="3"/>';

$cenas = [
  ['core' => $nucleoP, 'before' => $P_before, 'after' => lp2_yshell() . $P_after],
  ['core' => $nucleoP, 'before' => $b1, 'after' => lp2_yshell() . $a1],
  ['core' => $nucleoP, 'before' => $b2, 'after' => lp2_yshell() . $a2],
  ['core' => $nucleoP, 'before' => $b3, 'after' => lp2_yshell() . $a3],
  ['core' => $nucleoP, 'before' => $b4, 'after' => lp2_yshell() . $a4],
  ['core' => $nucleoP, 'before' => $b5, 'after' => lp2_yshell() . $a5],
  ['core' => $nucleoP, 'before' => $b6, 'after' => lp2_yshell() . $a6],
];

/* ═════════ Versões VERTICAIS (mobile), viewBox 0 0 400 600 ═════════
   Antes no topo (y 36-250), núcleo (200,300), Yuris embaixo (lp2_yshell_v). */
$P_beforeV =
    '<rect class="s-sheet" x="44" y="40" width="210" height="150" rx="8"/><path class="s-sheet-head" d="M44 48 a8 8 0 0 1 8 -8 h194 a8 8 0 0 1 8 8 v14 h-210 z"/>'
  . '<circle class="s-dim2" cx="60" cy="52" r="2.6"/><circle class="s-dim2" cx="72" cy="52" r="2.6"/><circle class="s-dim2" cx="84" cy="52" r="2.6"/>'
  . '<rect class="s-formula" x="58" y="72" width="130" height="10" rx="2"/><rect class="s-formula" x="200" y="72" width="46" height="10" rx="2"/>'
  . '<line class="s-grid" x1="44" y1="92" x2="254" y2="92"/><line class="s-grid" x1="104" y1="92" x2="104" y2="190"/><line class="s-grid" x1="164" y1="92" x2="164" y2="190"/><line class="s-grid" x1="214" y1="92" x2="214" y2="190"/><line class="s-grid" x1="44" y1="118" x2="254" y2="118"/><line class="s-grid" x1="44" y1="144" x2="254" y2="144"/>'
  . '<text class="s-lbl" x="128" y="110">A</text><text class="s-lbl" x="184" y="110">B</text><text class="s-lbl" x="230" y="110">C</text>'
  . '<rect class="s-cell" x="114" y="128" width="38" height="8" rx="2"/><rect class="s-cell" x="174" y="128" width="32" height="8" rx="2"/><rect class="s-cell" x="222" y="128" width="26" height="8" rx="2"/>'
  . '<rect class="s-hl" x="46" y="152" width="206" height="24" rx="2"/><rect class="s-cell" x="114" y="160" width="40" height="8" rx="2"/><rect class="s-cell" x="174" y="160" width="30" height="8" rx="2"/>'
  . '<rect class="s-tab" x="52" y="198" width="50" height="14" rx="3"/><rect class="s-tab" x="108" y="198" width="50" height="14" rx="3"/>'
  . '<rect class="s-doc" x="296" y="120" width="46" height="58" rx="4" transform="rotate(9 319 149)"/><rect class="s-doc" x="284" y="98" width="50" height="64" rx="4"/><path class="s-doc-fold" d="M322 98 v12 h12"/><line class="s-docline" x1="292" y1="118" x2="326" y2="118"/><line class="s-docline" x1="292" y1="130" x2="326" y2="130"/>'
  . lp2_alert(286, 80, 10) . '<rect class="s-label" x="46" y="220" width="74" height="20" rx="10"/><line class="s-docline" x1="58" y1="230" x2="106" y2="230"/>';
$P_afterV =
    '<rect class="s-card" x="58" y="412" width="284" height="78" rx="8"/><rect class="s-tag" x="74" y="424" width="46" height="8" rx="3"/><rect class="s-blue" x="74" y="440" width="200" height="9" rx="3"/><rect class="s-bluefaint" x="74" y="458" width="150" height="7" rx="2"/><rect class="s-bluefaint" x="74" y="472" width="180" height="7" rx="2"/>'
  . '<circle class="s-avatar" cx="76" cy="516" r="11"/><rect class="s-bluefaint" x="94" y="511" width="96" height="8" rx="3"/><rect class="s-status" x="210" y="504" width="132" height="24" rx="12"/><rect class="s-blue" x="226" y="511" width="80" height="9" rx="3"/>'
  . '<rect class="s-doc-mini" x="58" y="544" width="46" height="44" rx="4"/><path class="s-doc-foldb" d="M92 544 v10 h10"/><rect class="s-bluefaint" x="120" y="540" width="80" height="7" rx="3"/><line class="s-timeline" x1="120" y1="566" x2="300" y2="566"/><circle class="s-blue" cx="134" cy="566" r="5"/><circle class="s-blue" cx="200" cy="566" r="5"/><circle class="s-blue" cx="266" cy="566" r="5"/>';

/* genéricos verticais: Antes no topo + Yuris embaixo (cartões empilhados) */
$b1V = '<rect class="s-sheet" x="64" y="44" width="190" height="150" rx="8"/><path class="s-sheet-head" d="M64 52 a8 8 0 0 1 8 -8 h174 a8 8 0 0 1 8 8 v14 h-190 z"/><rect class="s-cell" x="78" y="64" width="60" height="9" rx="3"/><rect class="s-formula" x="78" y="84" width="160" height="10" rx="2"/><line class="s-grid" x1="64" y1="104" x2="254" y2="104"/><line class="s-grid" x1="64" y1="134" x2="254" y2="134"/><line class="s-grid" x1="64" y1="164" x2="254" y2="164"/><line class="s-grid" x1="112" y1="98" x2="112" y2="194"/><line class="s-grid" x1="160" y1="98" x2="160" y2="194"/><line class="s-grid" x1="208" y1="98" x2="208" y2="194"/><rect class="s-cell" x="78" y="112" width="22" height="8" rx="2"/><rect class="s-cell" x="126" y="142" width="22" height="8" rx="2"/><rect class="s-hl" x="158" y="158" width="48" height="28" rx="4"/><circle class="s-doc" cx="306" cy="92" r="32" fill="none"/><line class="s-docline" x1="306" y1="92" x2="306" y2="70"/><line class="s-docline" x1="306" y1="92" x2="322" y2="100"/>' . lp2_alert(248, 56, 10);
$a1V = '<rect class="s-card" x="58" y="412" width="284" height="74" rx="8"/><rect class="s-tag" x="74" y="424" width="54" height="8" rx="3"/><rect class="s-blue" x="74" y="440" width="190" height="9" rx="3"/><rect class="s-bluefaint" x="74" y="458" width="150" height="7" rx="2"/><circle class="s-doc-mini" cx="316" cy="448" r="15" fill="none"/><circle class="s-avatar" cx="76" cy="514" r="11"/><rect class="s-bluefaint" x="94" y="509" width="90" height="8" rx="3"/><rect class="s-status" x="210" y="502" width="132" height="24" rx="12"/><rect class="s-blue" x="226" y="509" width="80" height="9" rx="3"/><rect class="s-bluefaint" x="58" y="548" width="70" height="7" rx="3"/><line class="s-timeline" x1="64" y1="572" x2="300" y2="572"/><circle class="s-blue" cx="80" cy="572" r="5"/><circle class="s-blue" cx="182" cy="572" r="5"/><circle class="s-blue" cx="284" cy="572" r="5"/>';
$b2V = '<rect class="s-doc" x="70" y="44" width="120" height="160" rx="16" fill="none"/><rect class="s-cell" x="84" y="66" width="70" height="20" rx="9"/><rect class="s-formula" x="108" y="92" width="70" height="20" rx="9"/><rect class="s-cell" x="84" y="118" width="60" height="18" rx="8"/><rect class="s-formula" x="220" y="58" width="96" height="28" rx="13"/><rect class="s-cell" x="244" y="98" width="84" height="26" rx="12"/><rect class="s-formula" x="220" y="138" width="76" height="24" rx="11"/><rect class="s-doc" x="236" y="172" width="44" height="30" rx="4"/>' . lp2_alert(190, 60, 10);
$a2V = '<rect class="s-card" x="58" y="412" width="284" height="86" rx="8"/><circle class="s-avatar" cx="80" cy="434" r="12"/><rect class="s-tag" x="100" y="424" width="60" height="9" rx="3"/><rect class="s-bluefaint" x="100" y="440" width="90" height="7" rx="3"/><rect class="s-blue" x="74" y="462" width="150" height="20" rx="10"/><rect class="s-status" x="238" y="464" width="100" height="20" rx="10"/><rect class="s-doc-mini" x="58" y="516" width="40" height="50" rx="4"/><path class="s-doc-foldb" d="M90 516 v9 h9"/><rect class="s-bluefaint" x="112" y="524" width="80" height="7" rx="3"/><line class="s-timeline" x1="112" y1="548" x2="300" y2="548"/><circle class="s-blue" cx="126" cy="548" r="5"/><circle class="s-blue" cx="206" cy="548" r="5"/><circle class="s-blue" cx="286" cy="548" r="5"/>';
$b3V = '<rect class="s-doc" x="64" y="48" width="150" height="50" rx="7"/><rect class="s-doc" x="74" y="60" width="20" height="20" rx="4" fill="none"/><rect class="s-formula" x="104" y="64" width="92" height="8" rx="3"/><rect class="s-cell" x="104" y="80" width="56" height="6" rx="3"/><rect class="s-doc" x="220" y="92" width="130" height="50" rx="7"/><rect class="s-doc" x="230" y="104" width="20" height="20" rx="4" fill="none"/><rect class="s-formula" x="260" y="108" width="80" height="8" rx="3"/><rect class="s-doc" x="84" y="138" width="150" height="50" rx="7"/><rect class="s-doc" x="94" y="150" width="20" height="20" rx="4" fill="none"/><rect class="s-formula" x="124" y="154" width="92" height="8" rx="3"/>' . lp2_alert(330, 78, 10);
$a3V = '<rect class="s-card" x="58" y="410" width="284" height="50" rx="8"/><rect class="s-doc-mini" x="72" y="422" width="24" height="24" rx="5" fill="none"/><polyline class="s-check" points="78 434 83 440 92 428"/><rect class="s-blue" x="108" y="424" width="150" height="9" rx="3"/><circle class="s-avatar" cx="320" cy="435" r="11"/><rect class="s-card" x="58" y="468" width="284" height="50" rx="8"/><rect class="s-doc-mini" x="72" y="480" width="24" height="24" rx="5" fill="none"/><rect class="s-blue" x="108" y="482" width="132" height="9" rx="3"/><circle class="s-avatar" cx="320" cy="493" r="11"/><rect class="s-card" x="58" y="526" width="284" height="50" rx="8"/><rect class="s-doc-mini" x="72" y="538" width="24" height="24" rx="5" fill="none"/><rect class="s-blue" x="108" y="540" width="120" height="9" rx="3"/><circle class="s-avatar" cx="320" cy="551" r="11"/>';
$b4V = '<rect class="s-sheet" x="70" y="44" width="170" height="160" rx="8"/><path class="s-sheet-head" d="M70 52 a8 8 0 0 1 8 -8 h154 a8 8 0 0 1 8 8 v18 h-170 z"/><circle class="s-dim2" cx="155" cy="100" r="18" fill="none"/><path class="s-docline" d="M147 100 l6 6 l11 -13"/><line class="s-docline" x1="86" y1="140" x2="224" y2="140"/><line class="s-docline" x1="86" y1="158" x2="224" y2="158"/><line class="s-docline" x1="86" y1="176" x2="194" y2="176"/><rect class="s-doc" x="268" y="70" width="92" height="64" rx="6"/><polyline class="s-doc-fold" points="268 76 314 106 360 76"/>' . lp2_alert(240, 60, 10);
$a4V = '<rect class="s-card" x="58" y="412" width="284" height="90" rx="8"/><rect class="s-doc-mini" x="74" y="426" width="38" height="50" rx="4"/><path class="s-doc-foldb" d="M104 426 v9 h9"/><rect class="s-tag" x="126" y="428" width="56" height="9" rx="3"/><rect class="s-blue" x="126" y="446" width="150" height="9" rx="3"/><rect class="s-status" x="126" y="466" width="120" height="20" rx="10"/><circle class="s-avatar" cx="320" cy="437" r="11"/><path class="s-doc-foldb" d="M86 524 l16 5 v13 q0 13 -16 18 q-16 -5 -16 -18 v-13 z" fill="none"/><polyline class="s-check" points="78 548 85 555 97 540"/><rect class="s-bluefaint" x="120" y="528" width="80" height="7" rx="3"/><line class="s-timeline" x1="120" y1="552" x2="300" y2="552"/><circle class="s-blue" cx="134" cy="552" r="5"/><circle class="s-blue" cx="210" cy="552" r="5"/><circle class="s-blue" cx="286" cy="552" r="5"/>';
$b5V = '<circle class="s-doc" cx="110" cy="80" r="20" fill="none"/><circle class="s-dim2" cx="110" cy="73" r="5"/><path class="s-docline" d="M100 92 a10 8 0 0 1 20 0"/><circle class="s-doc" cx="250" cy="64" r="20" fill="none"/><circle class="s-dim2" cx="250" cy="57" r="5"/><path class="s-docline" d="M240 76 a10 8 0 0 1 20 0"/><circle class="s-doc" cx="320" cy="150" r="20" fill="none"/><circle class="s-dim2" cx="320" cy="143" r="5"/><path class="s-docline" d="M310 162 a10 8 0 0 1 20 0"/><circle class="s-doc" cx="130" cy="180" r="20" fill="none"/><circle class="s-dim2" cx="130" cy="173" r="5"/><path class="s-docline" d="M120 192 a10 8 0 0 1 20 0"/><line class="s-docline" x1="130" y1="80" x2="230" y2="66" stroke-dasharray="4 6"/>' . lp2_alert(210, 150, 10);
$a5V = '<circle class="s-doc-mini" cx="200" cy="418" r="18" fill="none"/><circle class="s-blue" cx="200" cy="418" r="6"/><line class="s-link" x1="200" y1="436" x2="96" y2="476"/><line class="s-link" x1="200" y1="436" x2="200" y2="474"/><line class="s-link" x1="200" y1="436" x2="304" y2="476"/><circle class="s-doc-mini" cx="96" cy="492" r="15" fill="none"/><circle class="s-blue" cx="96" cy="492" r="5"/><circle class="s-doc-mini" cx="200" cy="492" r="15" fill="none"/><circle class="s-blue" cx="200" cy="492" r="5"/><circle class="s-doc-mini" cx="304" cy="492" r="15" fill="none"/><circle class="s-blue" cx="304" cy="492" r="5"/><rect class="s-card" x="66" y="528" width="120" height="50" rx="8"/><rect class="s-blue" x="80" y="540" width="34" height="13" rx="3"/><rect class="s-bluefaint" x="80" y="562" width="60" height="7" rx="3"/><rect class="s-card" x="214" y="528" width="120" height="50" rx="8"/><rect class="s-blue" x="228" y="540" width="34" height="13" rx="3"/><rect class="s-bluefaint" x="228" y="562" width="60" height="7" rx="3"/>';
$b6V = '<rect class="s-doc" x="64" y="48" width="210" height="38" rx="6"/><circle class="s-dim2" cx="84" cy="67" r="6"/><rect class="s-formula" x="102" y="58" width="120" height="8" rx="3"/><rect class="s-cell" x="102" y="72" width="80" height="6" rx="3"/><rect class="s-doc" x="84" y="104" width="210" height="38" rx="6"/><circle class="s-dim2" cx="104" cy="123" r="6"/><rect class="s-formula" x="122" y="114" width="120" height="8" rx="3"/><rect class="s-doc" x="64" y="160" width="190" height="38" rx="6"/><circle class="s-dim2" cx="84" cy="179" r="6"/><rect class="s-formula" x="102" y="170" width="100" height="8" rx="3"/><line class="s-docline" x1="84" y1="86" x2="84" y2="104" stroke-dasharray="3 5"/>' . lp2_alert(312, 60, 10);
$a6V = '<line class="s-timeline" x1="92" y1="416" x2="92" y2="580"/><circle class="s-blue" cx="92" cy="430" r="6"/><circle class="s-blue" cx="92" cy="496" r="6"/><circle class="s-blue" cx="92" cy="562" r="6"/><rect class="s-card" x="118" y="412" width="180" height="44" rx="8"/><rect class="s-tag" x="132" y="422" width="44" height="8" rx="3"/><rect class="s-blue" x="132" y="436" width="120" height="8" rx="3"/><rect class="s-card" x="118" y="478" width="180" height="44" rx="8"/><rect class="s-tag" x="132" y="488" width="44" height="8" rx="3"/><rect class="s-blue" x="132" y="502" width="110" height="8" rx="3"/><rect class="s-card" x="118" y="544" width="180" height="44" rx="8"/><rect class="s-tag" x="132" y="554" width="44" height="8" rx="3"/><rect class="s-blue" x="132" y="568" width="120" height="8" rx="3"/><path class="s-doc-foldb" d="M326 430 l16 5 v13 q0 13 -16 18 q-16 -5 -16 -18 v-13 z" fill="none"/><polyline class="s-check" points="318 454 325 461 337 446"/>';

$cenasV = [
  ['before' => $P_beforeV, 'after' => lp2_yshell_v() . $P_afterV],
  ['before' => $b1V, 'after' => lp2_yshell_v() . $a1V],
  ['before' => $b2V, 'after' => lp2_yshell_v() . $a2V],
  ['before' => $b3V, 'after' => lp2_yshell_v() . $a3V],
  ['before' => $b4V, 'after' => lp2_yshell_v() . $a4V],
  ['before' => $b5V, 'after' => lp2_yshell_v() . $a5V],
  ['before' => $b6V, 'after' => lp2_yshell_v() . $a6V],
];
?>
<section id="comparativo" class="lp2-section">
  <div class="lp2-container">
    <?php lp2_head('Antes &amp; depois', 'A rotina jurídica que existe hoje, e a que pode existir amanhã.'); ?>

    <div class="cmp-mg lp2-reveal" data-cmp-mg tabindex="0" role="group" aria-label="Comparativo: antes e com o Yuris">
      <p class="cmp-scene-title" data-cmp-title aria-live="polite"><?= $titulos[0] ?></p>
      <div class="cmp-track">
        <?php foreach ($titulos as $i => $t): ?>
        <div class="cmp-slide<?= $i === 0 ? ' is-active' : '' ?> is-proc" data-title="<?= $t ?>">
          <div class="cmp-stage">
            <span class="cmp-tag cmp-tag-before">Antes</span>
            <span class="cmp-tag cmp-tag-after">Com o Yuris</span>
            <svg class="cmp-scn cmp-scn-h" viewBox="0 0 1440 520" preserveAspectRatio="xMidYMid meet" aria-hidden="true">
              <g class="scn-before"><?= $cenas[$i]['before'] ?></g>
              <?= $cenas[$i]['core'] ?>
              <g class="scn-after"><?= $cenas[$i]['after'] ?></g>
            </svg>
            <svg class="cmp-scn cmp-scn-v" viewBox="0 0 400 600" preserveAspectRatio="xMidYMid meet" aria-hidden="true">
              <g class="scn-before"><?= $cenasV[$i]['before'] ?></g>
              <?= $nucleoV ?>
              <g class="scn-after"><?= $cenasV[$i]['after'] ?></g>
            </svg>
          </div>
          <div class="cmp-texts">
            <p class="cmp-zt-before"><?= $antes[$i] ?></p>
            <p class="cmp-zt-after"><?= $depois[$i] ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="cmp-controls">
        <button type="button" class="cmp-nav" data-cmp-prev aria-label="Cena anterior"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
        <div class="cmp-dots" data-cmp-dots role="tablist" aria-label="Selecionar cena"></div>
        <button type="button" class="cmp-nav" data-cmp-next aria-label="Próxima cena"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></button>
      </div>
      <div class="cmp-progress" aria-hidden="true"><span data-cmp-bar></span></div>
    </div>

    <div class="lp2-section-cta lp2-reveal"><?= lp2_wa_btn('Olá Bruno, quero transformar a rotina do meu escritório com o Yuris!', 'Quero essa transformação') ?></div>
  </div>
</section>
