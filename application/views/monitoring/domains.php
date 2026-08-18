<?php
/**
 * Estado atual de cada domínio monitorado.
 *
 * Complementa o feed: aqui não se olha "o que mudou", e sim "como está agora".
 * É também de onde se silencia um domínio que não deve mais avisar — o site do
 * cliente que está sabidamente quebrado, ou o subdomínio de API que responde
 * 404 na raiz por natureza.
 */
$filtro = (array) $this->session->userdata('f_monitor_domains');

$fKeyword = isset($filtro['keyword']) ? trim((string) $filtro['keyword']) : '';
$fSituacao = isset($filtro['situation']) ? (string) $filtro['situation'] : '';

$chips = [];
if ($fSituacao !== '' && isset($situacoes[$fSituacao])) $chips[] = 'Situação: ' . $situacoes[$fSituacao];

$temFiltro = ($fKeyword !== '' || !empty($chips));

/** Cor de cada situação — os limiares vivem no CASE da view, aqui só a cor. */
$badgeSituacao = [
  'fora' => 'bg-danger',
  'marcador' => 'bg-danger',
  'ssl_problema' => 'bg-warning text-dark',
  'ssl_vencendo' => 'bg-warning text-dark',
  'nunca_respondeu' => 'bg-dark',
  'bloqueado' => 'bg-info',
  'pendente' => 'bg-secondary',
  'ok' => 'bg-success',
  'silenciado' => 'bg-light text-dark border',
  'inativo' => 'bg-light text-dark border',
];
?>
<div class="row mb-2 mb-xl-2">
  <div class="col-auto text-start">
    <h1 class="h3 mb-3">Domínios monitorados</h1>
  </div>
  <div class="col-auto ms-auto text-end mt-n1">
    <a class="btn btn-outline-secondary" href="<?php echo base_url('monitoramento'); ?>"><i class="fa fa-bell"></i> ALTERAÇÕES</a>
  </div>
</div>

<div class="card flex-fill">
  <div class="card-body py-3">
    <div class="row g-2 align-items-center">
      <div class="col-12 col-md-6">
        <form method="POST" action="<?php echo base_url('monitoramento/post_filtrar_dominios'); ?>" role="search">
          <div class="input-group">
            <input type="text" class="form-control" name="f_monitor_domains[keyword]" value="<?php echo htmlspecialchars($fKeyword, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Domínio, título da home ou nameserver..." autocomplete="off" aria-label="Buscar domínios monitorados">
            <button class="btn btn-primary" type="submit"><i class="fa fa-search"></i> BUSCAR</button>
          </div>
        </form>
      </div>
      <div class="col-12 col-md-6 text-md-end">
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="offcanvas" data-bs-target="#offcanvas_filtros_mon_dominios">
          <i class="fa fa-filter"></i> FILTROS
          <?php if (!empty($chips)) { ?><span class="badge bg-primary ms-1"><?php echo count($chips); ?></span><?php } ?>
        </button>
        <?php if ($temFiltro) { ?>
          <button type="submit" form="form_filtros_mon_dominios" name="acao" value="limpar" class="btn btn-outline-secondary"><i class="fa fa-times"></i> LIMPAR</button>
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

    <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvas_filtros_mon_dominios">
      <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title"><i class="fa fa-filter"></i> Filtros</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
      </div>
      <form id="form_filtros_mon_dominios" method="POST" action="<?php echo base_url('monitoramento/post_filtrar_dominios'); ?>">
        <div class="offcanvas-body">
          <div class="form-group mb-3">
            <label class="form-label">Situação</label>
            <select name="f_monitor_domains[situation]" class="form-select">
              <option value="">-- MOSTRAR TODAS --</option>
              <?php foreach ($situacoes as $slug => $rotulo) {
                $qtd = isset($resumo_situacao[$slug]) ? (int) $resumo_situacao[$slug] : 0; ?>
                <option value="<?php echo $slug; ?>" <?php if ($fSituacao === $slug) echo 'selected=""'; ?>>
                  <?php echo $rotulo; ?> (<?php echo $qtd; ?>)
                </option>
              <?php } ?>
            </select>
            <small class="form-text text-muted">A listagem já abre com os problemas em cima.</small>
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
              <th style="width: 100px;">Ações</th>
              <th>Domínio</th>
              <th>Cliente</th>
              <th>Situação</th>
              <th>Título da home</th>
              <th>Nameservers</th>
              <th>SSL</th>
              <th>Checado</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($results as $d) {
              $badge = isset($badgeSituacao[$d->situation]) ? $badgeSituacao[$d->situation] : 'bg-secondary';
              $rotulo = isset($situacoes[$d->situation]) ? $situacoes[$d->situation] : $d->situation;
              $cliente = isset($customers_by_domain[$d->domain]) ? $customers_by_domain[$d->domain] : '';
              ?>
              <tr>
                <td class="text-nowrap">
                  <button type="button" class="btn btn-sm btn-outline-primary js-checar" data-id="<?php echo (int) $d->id; ?>" title="Checar agora">
                    <i class="fa fa-sync"></i>
                  </button>
                  <button type="button" class="btn btn-sm <?php echo empty($d->muted) ? 'btn-outline-secondary' : 'btn-secondary'; ?> js-silenciar"
                    data-id="<?php echo (int) $d->id; ?>"
                    data-muted="<?php echo empty($d->muted) ? '0' : '1'; ?>"
                    data-domain="<?php echo htmlspecialchars($d->domain, ENT_QUOTES, 'UTF-8'); ?>"
                    title="<?php echo empty($d->muted) ? 'Silenciar: para de entrar no resumo' : 'Reativar os avisos'; ?>">
                    <i class="fa <?php echo empty($d->muted) ? 'fa-bell' : 'fa-bell-slash'; ?>"></i>
                  </button>
                </td>
                <td>
                  <a href="<?php echo htmlspecialchars(empty($d->http_final_url) ? 'http://' . $d->domain : $d->http_final_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
                    <?php echo htmlspecialchars($d->domain, ENT_QUOTES, 'UTF-8'); ?>
                  </a>
                  <?php if (!empty($d->check_host) && $d->check_host !== $d->domain) { ?>
                    <br /><small class="text-muted">responde em <?php echo htmlspecialchars($d->check_host, ENT_QUOTES, 'UTF-8'); ?></small>
                  <?php } ?>
                </td>
                <td><?php echo htmlspecialchars($cliente, ENT_QUOTES, 'UTF-8'); ?></td>
                <td>
                  <span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($rotulo, ENT_QUOTES, 'UTF-8'); ?></span>
                  <?php if (!empty($d->flag) && isset($marcadores[$d->flag])) { ?>
                    <br /><small class="text-danger"><?php echo htmlspecialchars($marcadores[$d->flag], ENT_QUOTES, 'UTF-8'); ?></small>
                  <?php } ?>
                  <?php if ($d->days_down !== NULL) { ?>
                    <br /><small class="text-danger">há <?php echo (int) $d->days_down; ?> dia(s)</small>
                  <?php } ?>
                  <?php if (!empty($d->http_status)) { ?>
                    <br /><small class="text-muted">HTTP <?php echo (int) $d->http_status; ?></small>
                  <?php } ?>
                </td>
                <td><small><?php echo htmlspecialchars((string) $d->title, ENT_QUOTES, 'UTF-8'); ?></small></td>
                <td>
                  <small class="text-muted" style="word-break: break-all;">
                    <?php echo htmlspecialchars((string) $d->ns_list, ENT_QUOTES, 'UTF-8'); ?>
                  </small>
                  <?php if (!empty($d->ns_changed)) { ?>
                    <br /><small class="text-warning">alterado em <?php echo date('d/m/Y', strtotime($d->ns_changed)); ?></small>
                  <?php } ?>
                  <?php if (!empty($d->ns_pending)) { ?>
                    <br /><small class="text-muted" title="Conjunto novo aguardando confirmação na próxima checagem — durante a propagação o DNS alterna entre o antigo e o novo.">
                      <i class="fa fa-clock"></i> mudança em confirmação
                    </small>
                  <?php } ?>
                </td>
                <td class="text-nowrap">
                  <?php if (!empty($d->ssl_expiration_date)) { ?>
                    <small><?php echo date('d/m/Y', strtotime($d->ssl_expiration_date)); ?></small>
                  <?php } else { ?>
                    <small class="text-muted">—</small>
                  <?php } ?>
                  <?php if (!empty($d->ssl_status) && $d->ssl_status !== 'ok') { ?>
                    <br /><small class="text-danger"><?php echo htmlspecialchars($d->ssl_status, ENT_QUOTES, 'UTF-8'); ?></small>
                  <?php } ?>
                  <?php if (!empty($d->ssl_issuer)) { ?>
                    <br /><small class="text-muted"><?php echo htmlspecialchars($d->ssl_issuer, ENT_QUOTES, 'UTF-8'); ?></small>
                  <?php } ?>
                </td>
                <td class="text-nowrap">
                  <?php if (!empty($d->last_check)) { ?>
                    <small><?php echo date('d/m/Y H:i', strtotime($d->last_check)); ?></small>
                  <?php } else { ?>
                    <small class="text-muted">nunca</small>
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
          <p class="mb-0">
            <strong><?php echo numero($total_results); ?></strong> domínio(s) no filtro atual. Entram no
            monitoramento os domínios de contrato <strong>vigente</strong> cujo tipo de serviço esteja marcado
            como <em>tem site</em> em GESTÃO &rsaquo; Tipos de serviços.
          </p>
        </div>
      </div>
    <?php } else { ?>
      <div class="text-center py-5">
        <i class="mdi mdi-radar mdi-48px text-muted"></i>
        <p class="text-muted mt-2 mb-0">
          Nenhum domínio no filtro atual.
          <?php if (!$temFiltro) { ?>
            A rotina <code>cron_monitorar_sites</code> ainda não rodou, ou nenhum tipo de serviço está marcado como
            <em>tem site</em>.
          <?php } ?>
        </p>
      </div>
    <?php } ?>
  </div>
</div>

<script>
  document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll('.js-checar').forEach(function(botao) {
      botao.addEventListener('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).find('i').addClass('fa-spin');

        $.ajax({
          url: '<?php echo base_url('monitoramento/json_postchecar'); ?>',
          type: 'POST',
          dataType: 'json',
          data: { id_monitor: $btn.data('id') },
          success: function(data) {
            if (!data.success) {
              Swal.fire('Erro', data.message, 'error');
              $btn.prop('disabled', false).find('i').removeClass('fa-spin');
              return;
            }
            // Recarrega: a checagem mexe em situação, título, NS e SSL de uma
            // vez, e reescrever tudo in-place duplicaria as regras de cor que
            // já vivem no PHP.
            location.reload();
          },
          error: function() {
            Swal.fire('Erro', 'Falha ao checar o domínio.', 'error');
            $btn.prop('disabled', false).find('i').removeClass('fa-spin');
          }
        });
      });
    });

    document.querySelectorAll('.js-silenciar').forEach(function(botao) {
      botao.addEventListener('click', function() {
        var $btn = $(this);
        var silenciado = $btn.data('muted') == 1;
        var acao = silenciado ? 'reativar' : 'silenciar';

        Swal.fire({
          title: silenciado ? 'Reativar os avisos?' : 'Silenciar este domínio?',
          text: silenciado
            ? $btn.data('domain') + ' volta a entrar no resumo diário.'
            : $btn.data('domain') + ' continua sendo checado, mas para de entrar no resumo por e-mail.',
          icon: 'question',
          showCancelButton: true,
          confirmButtonText: 'Confirmar',
          cancelButtonText: 'Cancelar'
        }).then(function(r) {
          if (!r.isConfirmed) return;

          $.ajax({
            url: '<?php echo base_url('monitoramento/json_postsilenciar'); ?>',
            type: 'POST',
            dataType: 'json',
            data: { id_monitor: $btn.data('id'), acao: acao },
            success: function(data) {
              if (!data.success) {
                Swal.fire('Erro', data.message, 'error');
                return;
              }
              location.reload();
            },
            error: function() {
              Swal.fire('Erro', 'Falha ao alterar o domínio.', 'error');
            }
          });
        });
      });
    });
  });
</script>
