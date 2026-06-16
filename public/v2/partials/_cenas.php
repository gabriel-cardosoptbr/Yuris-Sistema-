<?php
/**
 * _cenas.php — markup dos "palcos" das mini-demonstrações de produto.
 *
 * lp2_scene($slug) devolve a janela de interface (dados FICTÍCIOS, isolados no
 * frontend). Os elementos .dm-el são animados em sequência por sections/demos.js.
 * Estado final é visível por padrão → fallback seguro sem JS / reduced-motion.
 */
if (!function_exists('lp2_scene')) {
    function lp2_scene(string $slug): string {
        ob_start();
        switch ($slug) {

        // ───────────────────────── JURÍDICO ─────────────────────────
        case 'juridico': ?>
          <div class="lp2-demo" data-feature-scene="juridico" aria-hidden="true">
            <div class="dm-card dm-proc dm-el">
              <span class="dm-tag">Processo</span>
              <div class="dm-num">Nº 1028473-22.2026.8.26.0100</div>
              <div class="dm-row"><span>Cliente</span><b>Mariana Souza</b></div>
              <div class="dm-row"><span>Tribunal</span><b>TJSP</b></div>
              <div class="dm-row"><span>Responsável</span><b>Dra. Camila Andrade</b></div>
            </div>
            <ul class="dm-timeline">
              <li class="dm-el"><time>09:32</time> Processo criado</li>
              <li class="dm-el"><time>10:14</time> Advogado vinculado</li>
              <li class="dm-el"><time>11:46</time> Documento anexado</li>
              <li class="dm-el dm-hot" data-hot><time>14:20</time> Intimação recebida</li>
            </ul>
            <div class="dm-detail dm-el" data-detail>
              <span class="dm-tag">Intimação recebida</span>
              <div class="dm-row"><span>Prazo calculado</span><b>5 dias</b></div>
              <div class="dm-row"><span>Responsável</span><b>Dra. Camila</b></div>
              <span class="dm-flag">Tarefa criada automaticamente</span>
            </div>
            <div class="dm-badges">
              <span class="dm-badge dm-el">Matriz</span>
              <span class="dm-badge dm-el">Filial São Paulo</span>
              <span class="dm-badge dm-resp dm-el">Dra. Camila · responsável</span>
              <span class="dm-badge dm-el">Dr. Rafael · associado</span>
            </div>
          </div>
        <?php break;

        // ───────────────────────── INTIMAÇÕES ─────────────────────────
        case 'intimacoes': ?>
          <div class="lp2-demo" data-feature-scene="intimacoes" aria-hidden="true">
            <div class="dm-monitor dm-el"><span class="dm-pulse"></span> Monitoramento de intimações ativo</div>
            <div class="dm-card dm-intim dm-el">
              <div class="dm-row"><span>Processo</span><b>Nº 1004821-55.2026.8.26.0011</b></div>
              <div class="dm-row"><span>Cliente</span><b>Construtora Lima</b></div>
              <div class="dm-meta"><span class="dm-pill">Prazo 5 dias</span><span class="dm-flag">Tarefa criada</span></div>
            </div>
            <div class="dm-card dm-intim dm-el">
              <div class="dm-row"><span>Processo</span><b>Nº 2008934-12.2026.8.26.0100</b></div>
              <div class="dm-row"><span>Cliente</span><b>Mariana Souza</b></div>
              <div class="dm-meta"><span class="dm-pill">Prazo 10 dias</span><span class="dm-flag">Tarefa criada</span></div>
            </div>
            <div class="dm-card dm-intim dm-el">
              <div class="dm-row"><span>Processo</span><b>Nº 5012007-88.2026.8.26.0224</b></div>
              <div class="dm-row"><span>Cliente</span><b>João Pereira</b></div>
              <div class="dm-meta"><span class="dm-pill">Prazo 15 dias</span><span class="dm-flag">Tarefa criada</span></div>
            </div>
          </div>
        <?php break;

        // ───────────────────────── COMUNICAÇÃO ─────────────────────────
        case 'comunicacao': ?>
          <div class="lp2-demo dm-chat" data-feature-scene="comunicacao" aria-hidden="true">
            <div class="dm-chat-head dm-el">
              <span class="dm-avatar">MS</span>
              <div><b>Mariana Souza</b><span>Cliente, Proc. 1028473-22</span></div>
            </div>
            <div class="dm-msg dm-in dm-el">Bom dia! Tem novidade no meu processo?</div>
            <div class="dm-chip dm-el">Conversa vinculada ao processo, Dra. Camila notificada</div>
            <div class="dm-msg dm-out dm-el">Bom dia, Mariana! Saiu uma movimentação hoje, já estou cuidando do prazo.</div>
            <div class="dm-note dm-el">Nota interna: cliente prefere contato pela manhã</div>
          </div>
        <?php break;

        // ───────────────────────── OPERAÇÃO (funil) ─────────────────────────
        case 'operacao': ?>
          <div class="lp2-demo dm-funnel" data-feature-scene="operacao" aria-hidden="true">
            <div class="dm-cols">
              <div class="dm-col"><span class="dm-coltag">Contato</span></div>
              <div class="dm-col"><span class="dm-coltag">Qualificação</span></div>
              <div class="dm-col"><span class="dm-coltag">Proposta</span></div>
              <div class="dm-col"><span class="dm-coltag">Cliente</span></div>
            </div>
            <div class="dm-lead dm-el">Construtora Lima<small>Lead, R$ 12.000</small></div>
            <span class="dm-flag dm-funnel-done dm-el">Convertido em novo processo</span>
          </div>
        <?php break;

        // ───────────────────────── GESTÃO (escopo) ─────────────────────────
        case 'gestao': ?>
          <div class="lp2-demo dm-scope" data-feature-scene="gestao" aria-hidden="true">
            <div class="dm-seg">
              <span class="dm-seg-thumb"></span>
              <span class="dm-seg-i is-on">Matriz</span>
              <span class="dm-seg-i">Filial SP</span>
              <span class="dm-seg-i">Advogado</span>
            </div>
            <div class="dm-kpis">
              <div class="dm-kpi"><b data-k="proc">248</b><span>Processos ativos</span></div>
              <div class="dm-kpi"><b data-k="prazo">31</b><span>Prazos na semana</span></div>
              <div class="dm-kpi"><b data-k="adv">12</b><span>Advogados</span></div>
            </div>
          </div>
        <?php break;

        // ───────────────────────── AUTOMAÇÕES ─────────────────────────
        case 'automacoes': ?>
          <div class="lp2-demo dm-auto" data-feature-scene="automacoes" aria-hidden="true">
            <div class="dm-event dm-el"><span class="dm-tag">Evento no Yuris</span>Processo atualizado</div>
            <div class="dm-flow">
              <span class="dm-node dm-el">CRM</span>
              <span class="dm-node dm-el">Financeiro</span>
              <span class="dm-node dm-el">Notificações</span>
            </div>
            <span class="dm-flag dm-el">Ações executadas automaticamente</span>
          </div>
        <?php break;

        // ───────────────────────── VÍNCULOS (organograma) ─────────────────────────
        case 'vinculos': ?>
          <div class="lp2-demo dm-org" data-feature-scene="vinculos" aria-hidden="true">
            <span class="dm-onode dm-matriz dm-el">Matriz</span>
            <div class="dm-branch">
              <span class="dm-onode dm-el">Filial São Paulo</span>
              <span class="dm-onode dm-el">Filial Campinas</span>
            </div>
            <div class="dm-branch">
              <span class="dm-onode dm-adv dm-el">Dra. Camila</span>
              <span class="dm-onode dm-adv dm-el">Dr. Rafael</span>
            </div>
            <div class="dm-bind dm-el">
              <span class="dm-proccard">Proc. 1028473-22</span>
              <span class="dm-flag">Dra. Camila vinculada, permissão por escopo</span>
            </div>
          </div>
        <?php break;

        // ───────────────────────── DASHBOARDS ─────────────────────────
        case 'dashboards': ?>
          <div class="lp2-demo dm-dash" data-feature-scene="dashboards" aria-hidden="true">
            <div class="dm-dash-kpis">
              <div class="dm-stat dm-el"><i class="dm-trend">+12%</i><b data-c="156">156</b><span>Processos ativos</span></div>
              <div class="dm-stat dm-el"><i class="dm-trend">+5%</i><b data-c="28">28</b><span>Prazos na semana</span></div>
            </div>
            <div class="dm-chart dm-el">
              <span class="dm-tag">Comparativo por período</span>
              <div class="dm-bars">
                <span class="dm-bar" style="--h:44%"><b></b><i>Jan</i></span>
                <span class="dm-bar" style="--h:60%"><b></b><i>Fev</i></span>
                <span class="dm-bar" style="--h:52%"><b></b><i>Mar</i></span>
                <span class="dm-bar" style="--h:74%"><b></b><i>Abr</i></span>
                <span class="dm-bar" style="--h:66%"><b></b><i>Mai</i></span>
                <span class="dm-bar dm-bar-hot" style="--h:92%"><b></b><i>Jun</i></span>
              </div>
            </div>
          </div>
        <?php break;

        // ──────────────── SHOWCASE (trailer: todas as funções em sequência) ────────────────
        case 'showcase': ?>
          <div class="lp2-demo dm-show" data-feature-scene="showcase" aria-hidden="true">
            <div class="dm-show-stage">
              <div class="dm-frame" data-label="Matriz e filiais">
                <div class="dm-fr-org">
                  <span class="dm-onode dm-matriz">Matriz</span>
                  <div class="dm-fr-branch"><span class="dm-onode">Filial São Paulo</span><span class="dm-onode">Filial Campinas</span></div>
                </div>
              </div>
              <div class="dm-frame" data-label="Dashboard e indicadores">
                <div class="dm-fr-dash">
                  <div class="dm-fr-kpis"><div class="dm-stat"><b>156</b><span>Processos</span></div><div class="dm-stat"><b>28</b><span>Prazos</span></div></div>
                  <div class="dm-fr-bars"><span style="--h:48%"></span><span style="--h:70%"></span><span style="--h:58%"></span><span style="--h:88%"></span><span style="--h:64%"></span></div>
                </div>
              </div>
              <div class="dm-frame" data-label="Processos">
                <div class="dm-card dm-proc">
                  <span class="dm-tag">Processo</span>
                  <div class="dm-num">Nº 1028473-22.2026.8.26.0100</div>
                  <div class="dm-row"><span>Cliente</span><b>Mariana Souza</b></div>
                  <div class="dm-row"><span>Tribunal</span><b>TJSP</b></div>
                  <div class="dm-row"><span>Responsável</span><b>Dra. Camila</b></div>
                </div>
              </div>
              <div class="dm-frame" data-label="Intimações e prazos">
                <div class="dm-card">
                  <span class="dm-tag">Intimação recebida</span>
                  <div class="dm-row"><span>Prazo calculado</span><b>5 dias</b></div>
                  <span class="dm-flag">Tarefa criada automaticamente</span>
                </div>
              </div>
              <div class="dm-frame" data-label="Comunicação">
                <div class="dm-fr-chat">
                  <div class="dm-msg dm-in">Bom dia! Tem novidade no meu processo?</div>
                  <div class="dm-msg dm-out">Saiu uma movimentação hoje, já cuidei do prazo!</div>
                </div>
              </div>
              <div class="dm-frame" data-label="Funil de prospecção">
                <div class="dm-fr-funnel"><span>Contato</span><span>Qualificação</span><span class="on">Proposta</span><span>Cliente</span></div>
              </div>
              <div class="dm-frame" data-label="Automações">
                <div class="dm-fr-auto">
                  <span class="dm-event"><span class="dm-tag">Evento</span>Processo atualizado</span>
                  <div class="dm-fr-nodes"><span class="dm-node">CRM</span><span class="dm-node">Financeiro</span><span class="dm-node">Notificações</span></div>
                </div>
              </div>
              <div class="dm-frame" data-label="Usuários e equipe">
                <div class="dm-fr-users">
                  <div class="dm-user"><span class="dm-avatar">CA</span><div><b>Dra. Camila</b><span>Responsável</span></div></div>
                  <div class="dm-user"><span class="dm-avatar">RF</span><div><b>Dr. Rafael</b><span>Associado</span></div></div>
                </div>
              </div>
            </div>
            <div class="dm-show-cap">
              <span class="dm-show-label">Matriz e filiais</span>
              <div class="dm-show-dots">
                <span class="dm-show-dot on"></span><span class="dm-show-dot"></span><span class="dm-show-dot"></span><span class="dm-show-dot"></span><span class="dm-show-dot"></span><span class="dm-show-dot"></span><span class="dm-show-dot"></span><span class="dm-show-dot"></span>
              </div>
            </div>
          </div>
        <?php break;

        // ════════ Abas "O Yuris por dentro" (telas interativas, data-tab-scene) ════════
        case 'tab-dashboard': ?>
          <div class="lp2-demo dm-tab-dash" data-tab-scene="dashboard" aria-hidden="true">
            <div class="dm-dash-kpis">
              <div class="dm-stat"><i class="dm-trend">+9%</i><b data-c="38">38</b><span>Processos ativos</span></div>
              <div class="dm-stat"><i class="dm-trend">+4%</i><b data-c="12">12</b><span>Prazos hoje</span></div>
            </div>
            <div class="dm-chart">
              <span class="dm-tag">Produtividade da semana</span>
              <svg class="dm-line" viewBox="0 0 280 84" preserveAspectRatio="none" aria-hidden="true">
                <polyline class="dm-line-area" points="0,66 40,52 80,57 120,36 160,42 200,22 240,30 280,12 280,84 0,84"/>
                <polyline class="dm-line-path" points="0,66 40,52 80,57 120,36 160,42 200,22 240,30 280,12"/>
              </svg>
            </div>
            <div class="dm-bars">
              <span class="dm-bar" style="--h:52%"><b></b><i>seg</i></span>
              <span class="dm-bar" style="--h:70%"><b></b><i>ter</i></span>
              <span class="dm-bar" style="--h:58%"><b></b><i>qua</i></span>
              <span class="dm-bar dm-bar-hot" style="--h:88%"><b></b><i>qui</i></span>
              <span class="dm-bar" style="--h:64%"><b></b><i>sex</i></span>
            </div>
          </div>
        <?php break;

        case 'tab-processos': ?>
          <div class="lp2-demo dm-tab-list" data-tab-scene="processos" aria-hidden="true">
            <div class="dm-lhead"><span>Processo</span><span>Cliente</span><span>Status</span></div>
            <div class="dm-lrow"><span class="dm-lnum">1028473-22</span><span>Mariana Souza</span><span class="dm-spill dm-s-ok">Em dia</span></div>
            <div class="dm-lrow"><span class="dm-lnum">2008934-12</span><span>Construtora Lima</span><span class="dm-spill dm-s-warn">Prazo 3d</span></div>
            <div class="dm-lrow"><span class="dm-lnum">5012007-88</span><span>João Pereira</span><span class="dm-spill dm-s-ok">Em dia</span></div>
            <div class="dm-lrow"><span class="dm-lnum">3004511-09</span><span>Lima &amp; Costa</span><span class="dm-spill dm-s-new">Novo</span></div>
          </div>
        <?php break;

        case 'tab-tarefas': ?>
          <div class="lp2-demo dm-tab-kan" data-tab-scene="tarefas" aria-hidden="true">
            <div class="dm-kan">
              <div class="dm-kcol"><span class="dm-ktag">A fazer</span><div class="dm-task">Contestação, proc. 1028</div><div class="dm-task">Reunião com cliente</div></div>
              <div class="dm-kcol"><span class="dm-ktag">Em andamento</span><div class="dm-task">Petição inicial</div><div class="dm-task">Cálculo de prazo</div></div>
              <div class="dm-kcol"><span class="dm-ktag">Concluído</span><div class="dm-task dm-done-task">Protocolo enviado</div></div>
            </div>
          </div>
        <?php break;

        case 'tab-intimacoes': ?>
          <div class="lp2-demo dm-tab-list" data-tab-scene="intimacoes" aria-hidden="true">
            <div class="dm-lhead"><span>Publicação</span><span>Vínculo</span><span>Ação</span></div>
            <div class="dm-lrow"><span>Intimação, TJSP</span><span class="dm-lnum">Proc. 1028473</span><span class="dm-spill dm-s-warn">Vira prazo</span></div>
            <div class="dm-lrow"><span>Despacho</span><span class="dm-lnum">Proc. 2008934</span><span class="dm-spill dm-s-ok">Lida</span></div>
            <div class="dm-lrow"><span>Publicação</span><span class="dm-lnum">Proc. 5012007</span><span class="dm-spill dm-s-new">Nova</span></div>
          </div>
        <?php break;

        case 'tab-comunicacao': ?>
          <div class="lp2-demo dm-tab-chat" data-tab-scene="comunicacao" aria-hidden="true">
            <div class="dm-conv"><span class="dm-avatar">MS</span><div class="dm-conv-b"><b>Mariana Souza</b><span>Saiu alguma novidade?</span></div><i class="dm-unread">2</i></div>
            <div class="dm-conv"><span class="dm-avatar">CL</span><div class="dm-conv-b"><b>Construtora Lima</b><span>Obrigado, doutor!</span></div></div>
            <div class="dm-conv"><span class="dm-avatar">JP</span><div class="dm-conv-b"><b>João Pereira</b><span>Podemos marcar?</span></div><i class="dm-unread">1</i></div>
            <span class="dm-chip">Cada conversa vinculada ao cliente e ao processo</span>
          </div>
        <?php break;

        case 'tab-automacao': ?>
          <div class="lp2-demo dm-tab-auto" data-tab-scene="automacao" aria-hidden="true">
            <div class="dm-event"><span class="dm-tag">Evento no Yuris</span>Processo atualizado</div>
            <div class="dm-hook"><span class="dm-pulse"></span> Webhook dispara automaticamente</div>
            <div class="dm-fr-nodes"><span class="dm-node">CRM</span><span class="dm-node">ERP</span><span class="dm-node">n8n</span><span class="dm-node">Make</span></div>
            <span class="dm-flag">Integrado aos seus outros sistemas</span>
          </div>
        <?php break;

        case 'tab-matriz': ?>
          <div class="lp2-demo dm-tab-share" data-tab-scene="matriz" aria-hidden="true">
            <div class="dm-share-top"><span class="dm-onode dm-matriz">Matriz</span></div>
            <div class="dm-share-doc"><span class="dm-tag">Compartilhado</span>Processo 1028473-22</div>
            <div class="dm-fr-branch dm-share-row">
              <span class="dm-onode">Filial São Paulo</span>
              <span class="dm-onode">Filial Campinas</span>
              <span class="dm-onode">Filial Rio</span>
            </div>
            <span class="dm-flag">Acesso por escopo, cada filial vê o que é seu</span>
          </div>
        <?php break;

        }
        return ob_get_clean();
    }
}
