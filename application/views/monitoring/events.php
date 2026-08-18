<?php
/**
 * Feed de eventos do monitoramento — o que se lê todo dia.
 *
 * Mesmo padrão de listagem do Clientes: busca visível no topo, o resto dos
 * filtros no offcanvas e chips do que está aplicado. Filtro escondido sem chip
 * vira listagem inexplicavelmente vazia.
 *
 * Tudo que veio da rede (título de site, nameserver, URL de redirecionamento)
 * entra escapado: é conteúdo de terceiro.
 */
$filtro = (array) $this->session->userdata('f_monitor_events');

$severidades = ['critico' => 'Crítico', 'alerta' => 'Alerta', 'info' => 'Aviso'];
$badgeSeveridade = ['critico' => 'bg-danger', 'alerta' => 'bg-warning text-dark', 'info' => 'bg-secondary'];
$vistos = ['0' => 'Somente os não vistos', '1' => 'Somente os já vistos'];

$fKeyword = isset($filtro['keyword']) ? trim((string) $filtro['keyword']) : '';
$fTipo = isset($filtro['type']) ? (string) $filtro['type'] : '';
$fSeveridade = isset($filtro['severity']) ? (string) $filtro['severity'] : '';
// Fora do array do filtro genérico: `empty('0')` é TRUE em PHP e o
// Global_model descartaria "somente os não vistos" em silêncio.
$fVisto = (string) $filtro_visto;

$chips = [];
if ($fTipo !== '' && isset($tipos[$fTipo])) $chips[] = 'Tipo: ' . $tipos[$fTipo]['rotulo'];
if ($fSeveridade !== '' && isset($severidades[$fSeveridade])) $chips[] = 'Severidade: ' . $severidades[$fSeveridade];
if ($fVisto !== '' && isset($vistos[$fVisto])) $chips[] = $vistos[$fVisto];

$temFiltro = ($fKeyword !== '' || !empty($chips));

/** Cartões do topo: só as faixas que pedem ação. */
$cartoes = [
  'fora' => ['rotulo' => 'Fora do ar', 'cor' => 'danger', 'icone' => 'mdi-lan-disconnect'],
  'marcador' => ['rotulo' => 'Página com problema', 'cor' => 'danger', 'icone' => 'mdi-alert-octagon'],
  'ssl_problema' => ['rotulo' => 'Certificado inválido', 'cor' => 'warning', 'icone' => 'mdi-lock-alert'],
  'ssl_vencendo' => ['rotulo' => 'Certificado vencendo', 'cor' => 'warning', 'icone' => 'mdi-lock-clock'],
];
?>
<div class="row mb-2 mb-xl-2">
  <div class="col-auto text-start">
    <h1 class="h3 mb-3">Monitoramento</h1>
  </div>
  <div class="col-auto ms-auto text-end mt-n1">
    <a class="btn btn-outline-secondary" href="<?php echo base_url('monitoramento/dominios'); ?>"><i class="fa fa-list"></i> DOMÍNIOS MONITORADOS</a>
  </div>
</div>

<div class="row">
  <?php foreach ($cartoes as $faixa => $cartao) {
    $total = isset($resumo_situacao[$faixa]) ? (int) $resumo_situacao[$faixa] : 0; ?>
    <div class="col-6 col-lg-3 mb-3">
      <div class="card flex-fill h-100">
        <div class="card-body py-3">
          <div class="d-flex align-items-center">
            <div class="flex-grow-1">
              <h4 class="mb-1 <?php echo $total > 0 ? 'text-' . $cartao['cor'] : 'text-muted'; ?>"><?php echo $total; ?></h4>
              <small class="text-muted"><?php echo $cartao['rotulo']; ?></small>
            </div>
            <i class="mdi <?php echo $cartao['icone']; ?> mdi-24px <?php echo $total > 0 ? 'text-' . $cartao['cor'] : 'text-muted'; ?>"></i>
          </div>
        </div>
      </div>
    </div>
  <?php } ?>
</div>

<div class="card flex-fill">
  <div class="card-body py-3">
    <div class="row g-2 align-items-center">
      <div class="col-12 col-md-6">
        <form method="POST" action="<?php echo base_url('monitoramento/post_filtrar_eventos'); ?>" role="search">
          <div class="input-group">
            <input type="text" class="form-control" name="f_monitor_events[keyword]" value="<?php echo htmlspecialchars($fKeyword, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Domínio, valor novo ou detalhe..." autocomplete="off" aria-label="Buscar eventos">
            <button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> BUSCAR</button>
          </div>
        </form>
      </div>
      <div class="col-12 col-md-6 text-md-end">
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="offcanvas" data-bs-target="#offcanvas_filtros_eventos">
          <i class="fa fa-filter"></i> FILTROS
          <?php if (!empty($chips)) { ?><span class="badge bg-primary ms-1"><?php echo count($chips); ?></span><?php } ?>
        </button>
        <?php if ($temFiltro) { ?>
          <button type="submit" form="form_filtros_eventos" name="acao" value="limpar" class="btn btn-outline-secondary"><i class="fa fa-times"></i> LIMPAR</button>
        <?php } ?>
      </div>
    </div>

    <?php if (!empty($chips)) { ?>
      <div class="mt-3">
        <?php foreach ($chips as $chip) { ?>
          <span class="badge bg-light text-dark border me-1"><?php echo htmlspecialchars($chip, ENT_QUOTES, 'UTF-8'); ?></span>
        <?php } ?>
      </div>
    <?php } ?>

    <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvas_filtros_eventos">
      <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title"><i class="fa fa-filter"></i> Filtros</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
      </div>
      <form id="form_filtros_eventos" method="POST" action="<?php echo base_url('monitoramento/post_filtrar_eventos'); ?>">
        <div class="offcanvas-body">
          <div class="form-group mb-3">
            <label class="form-label">Situação do evento</label>
            <select name="<?php echo $filtro_visto_campo; ?>" class="form-select">
              <option value="">-- MOSTRAR TODOS --</option>
              <?php foreach ($vistos as $valor => $rotulo) { ?>
                <option value="<?php echo $valor; ?>" <?php if ($fVisto === (string) $valor) echo 'selected=""'; ?>><?php echo $rotulo; ?></option>
              <?php } ?>
            </select>
            <small class="form-text text-muted">O feed abre nos não vistos: é o que ainda precisa de atenção.</small>
          </div>

          <div class="form-group mb-3">
            <label class="form-label">Tipo de alteração</label>
            <select name="f_monitor_events[type]" class="form-select">
              <option value="">-- MOSTRAR TODOS --</option>
              <?php foreach ($tipos as $slug => $tipo) { ?>
                <option value="<?php echo $slug; ?>" <?php if ($fTipo === $slug) echo 'selected=""'; ?>><?php echo htmlspecialchars($tipo['rotulo'], ENT_QUOTES, 'UTF-8'); ?></option>
              <?php } ?>
            </select>
          </div>

          <div class="form-group mb-3">
            <label class="form-label">Severidade</label>
            <select name="f_monitor_events[severity]" class="form-select">
              <option value="">-- MOSTRAR TODAS --</option>
              <?php foreach ($severidades as $slug => $rotulo) { ?>
                <option value="<?php echo $slug; ?>" <?php if ($fSeveridade === $slug) echo 'selected=""'; ?>><?php echo $rotulo; ?></option>
              <?php } ?>
            </select>
          </div>
        </div>
        <div class="offcanvas-header border-top d-block">
          <button type="submit" class="btn btn-primary w-100 mb-2"><i class="fa fa-filter"></i> FILTRAR</button>
          <button type="submit" name="acao" value="limpar" class="btn btn-outline-secondary w-100"><i class="fa fa-times"></i> LIMPAR FILTROS</button>
        </div>
      </form>
    </div>

    <?php if (!empty($results)) { ?>
      <hr>
      <div class="table-responsive">
        <table class="table table-striped table-bordered table-hover">
          <thead>
            <tr>
              <th style="width: 90px;">Ações</th>
              <th>Quando</th>
              <th>Domínio</th>
              <th>Cliente</th>
              <th>Alteração</th>
              <th>Detalhe</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($results as $evento) {
              $rotulo = isset($tipos[$evento->type]['rotulo']) ? $tipos[$evento->type]['rotulo'] : $evento->type;
              $badge = isset($badgeSeveridade[$evento->severity]) ? $badgeSeveridade[$evento->severity] : 'bg-secondary';
              $cliente = isset($customers_by_domain[$evento->domain]) ? $customers_by_domain[$evento->domain] : '';
              $noResumo = in_array($evento->type, $tipos_no_resumo, TRUE);
              ?>
              <tr class="js-linha-evento" data-id="<?php echo (int) $evento->id; ?>">
                <td>
                  <?php if (empty($evento->acknowledged)) { ?>
                    <button type="button" class="btn btn-sm btn-outline-success js-ciente" data-id="<?php echo (int) $evento->id; ?>" title="Marcar como visto">
                      <i class="fa fa-check"></i>
                    </button>
                  <?php } else { ?>
                    <span class="text-success" title="Visto por <?php echo htmlspecialchars((string) $evento->acknowledged_user, ENT_QUOTES, 'UTF-8'); ?> em <?php echo empty($evento->acknowledged_at) ? '' : date('d/m/Y H:i', strtotime($evento->acknowledged_at)); ?>">
                      <i class="fa fa-check-circle"></i>
                    </span>
                  <?php } ?>
                </td>
                <td class="text-nowrap"><?php echo date('d/m/Y H:i', strtotime($evento->detected)); ?></td>
                <td>
                  <a href="http://<?php echo htmlspecialchars($evento->domain, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
                    <?php echo htmlspecialchars($evento->domain, ENT_QUOTES, 'UTF-8'); ?>
                  </a>
                  <?php if (!empty($evento->muted)) { ?>
                    <br /><small class="text-muted"><i class="fa fa-bell-slash"></i> silenciado</small>
                  <?php } ?>
                </td>
                <td><?php echo htmlspecialchars($cliente, ENT_QUOTES, 'UTF-8'); ?></td>
                <td>
                  <span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($rotulo, ENT_QUOTES, 'UTF-8'); ?></span>
                  <?php if (!$noResumo) { ?>
                    <br /><small class="text-muted" title="Este tipo fica só no painel: título de home muda sozinho com promoção e plugin de SEO.">não vai por e-mail</small>
                  <?php } ?>
                </td>
                <td>
                  <?php if (!empty($evento->detail)) { ?>
                    <?php echo htmlspecialchars($evento->detail, ENT_QUOTES, 'UTF-8'); ?><br />
                  <?php } ?>
                  <?php if (!empty($evento->old_value) || !empty($evento->new_value)) { ?>
                    <small class="text-muted" style="word-break: break-all;">
                      <?php if (!empty($evento->old_value)) { ?>
                        <s><?php echo htmlspecialchars($evento->old_value, ENT_QUOTES, 'UTF-8'); ?></s>
                      <?php } ?>
                      <?php if (!empty($evento->old_value) && !empty($evento->new_value)) echo ' &rarr; '; ?>
                      <?php if (!empty($evento->new_value)) { ?>
                        <strong><?php echo htmlspecialchars($evento->new_value, ENT_QUOTES, 'UTF-8'); ?></strong>
                      <?php } ?>
                    </small>
                  <?php } ?>
                </td>
              </tr>
            <?php } ?>
          </tbody>
        </table>
      </div>

      <div class="clearfix text-center">
        <?php echo $this->pagination->create_links(); ?>
      </div>

      <div class="alert alert-primary mb-0 alert-outline alert-dismissible" role="alert">
        <button type="button" class="btn-close d-none" data-bs-dismiss="alert" aria-label="Close"></button>
        <div class="alert-message">
          <h4 class="alert-heading">Resumo</h4>
          <hr>
          <p class="mb-0"><strong><?php echo numero($total_results); ?></strong> evento(s) no filtro atual.</p>
        </div>
      </div>
    <?php } else { ?>
      <div class="text-center py-5">
        <i class="mdi mdi-shield-check mdi-48px text-muted"></i>
        <p class="text-muted mt-2 mb-0">
          Nenhum evento no filtro atual.
          <?php if ($temFiltro) { ?>Limpe os filtros para ver o histórico completo.<?php } else { ?>Nada mudou desde a última checagem.<?php } ?>
        </p>
      </div>
    <?php } ?>
  </div>
</div>

<script>
  document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll('.js-ciente').forEach(function(botao) {
      botao.addEventListener('click', function() {
        var id = this.getAttribute('data-id');
        var $btn = $(this);
        $btn.prop('disabled', true);

        $.ajax({
          url: '<?php echo base_url('monitoramento/json_postciente'); ?>',
          type: 'POST',
          dataType: 'json',
          data: { id_event: id },
          success: function(data) {
            if (!data.success) {
              Swal.fire('Erro', data.message, 'error');
              $btn.prop('disabled', false);
              return;
            }
            // Troca o botão pelo ícone, sem recarregar: a página pode ter 30
            // linhas e recarregar perderia a posição da rolagem.
            $btn.replaceWith('<span class="text-success"><i class="fa fa-check-circle"></i></span>');
          },
          error: function() {
            Swal.fire('Erro', 'Falha ao marcar o evento.', 'error');
            $btn.prop('disabled', false);
          }
        });
      });
    });
  });
</script>
