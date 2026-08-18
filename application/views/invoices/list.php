<?php
$filtros = (array) $this->session->userdata('f_invoices');
$avancado = (array) $this->session->userdata($filtro_avancado);

$busca = isset($filtros['keyword']) ? (string) $filtros['keyword'] : '';

// Os chips mostram só o que está ESCONDIDO no offcanvas. A busca não vira chip:
// o campo está à vista e preenchido, e repetir viraria ruído.
$chips = [];

$situacaoAtual = isset($avancado['situation']) ? (string) $avancado['situation'] : '';
if ($situacaoAtual !== '' && isset($situations[$situacaoAtual])) {
  $chips[] = 'Situação: ' . $situations[$situacaoAtual];
}

$de = isset($avancado['vencimento_de']) ? (string) $avancado['vencimento_de'] : '';
$ate = isset($avancado['vencimento_ate']) ? (string) $avancado['vencimento_ate'] : '';
if ($de !== '') $chips[] = 'Vence a partir de ' . date('d/m/Y', strtotime($de));
if ($ate !== '') $chips[] = 'Vence até ' . date('d/m/Y', strtotime($ate));

$temFiltro = ($busca !== '' || !empty($chips));

$badgeSituacao = [
  'paga' => 'bg-success',
  'vencida' => 'bg-danger',
  'a_vencer' => 'bg-primary',
  'cancelada' => 'bg-secondary',
];
?>
<div class="row mb-2 mb-xl-3">
  <div class="col-auto d-none d-sm-block">
    <h3><strong>Faturas</strong></h3>
  </div>
</div>

<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-body">

        <div class="row mb-3">
          <div class="col-12 col-lg-9">
            <form method="POST" action="<?php echo base_url('faturas/post_filtrar'); ?>">
              <div class="input-group">
                <input type="text" class="form-control" name="f_invoices[keyword]" placeholder="Buscar por cliente, documento ou descrição" value="<?php echo htmlspecialchars($busca, ENT_QUOTES, 'UTF-8'); ?>">
                <button class="btn btn-primary" type="submit"><i class="mdi mdi-magnify"></i> BUSCAR</button>
              </div>
            </form>
          </div>
          <div class="col-12 col-lg-3 text-lg-end mt-2 mt-lg-0">
            <button class="btn btn-outline-primary" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvas_filtros_faturas">
              <i class="mdi mdi-filter-variant"></i> FILTROS
              <?php if (!empty($chips)) { ?><span class="badge bg-primary"><?php echo count($chips); ?></span><?php } ?>
            </button>
            <?php if ($temFiltro) { ?>
              <button class="btn btn-outline-secondary" type="submit" form="form_filtros_faturas" name="acao" value="limpar">
                <i class="mdi mdi-close"></i> LIMPAR
              </button>
            <?php } ?>
          </div>
        </div>

        <?php if (!empty($chips)) { ?>
          <div class="mb-3">
            <?php foreach ($chips as $chip) { ?>
              <span class="badge bg-light text-dark border me-1"><?php echo htmlspecialchars($chip, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php } ?>
          </div>
        <?php } ?>

        <?php if (!empty($results)) { ?>
          <div class="row mb-3">
            <div class="col-12 col-md-4">
              <div class="card bg-light mb-0">
                <div class="card-body py-2">
                  <small class="text-muted">Faturas no filtro</small>
                  <h4 class="mb-0"><?php echo (int) $totais['quantidade']; ?></h4>
                </div>
              </div>
            </div>
            <div class="col-12 col-md-4">
              <div class="card bg-light mb-0">
                <div class="card-body py-2">
                  <small class="text-muted">Valor total</small>
                  <h4 class="mb-0">R$ <?php echo reais($totais['total']); ?></h4>
                </div>
              </div>
            </div>
            <div class="col-12 col-md-4">
              <div class="card bg-light mb-0">
                <div class="card-body py-2">
                  <small class="text-muted">Vencido em aberto</small>
                  <h4 class="mb-0 <?php echo $totais['vencido'] > 0 ? 'text-danger' : ''; ?>">R$ <?php echo reais($totais['vencido']); ?></h4>
                </div>
              </div>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-striped table-bordered table-hover">
              <thead>
                <tr>
                  <th>Cliente</th>
                  <th class="text-center">Competência</th>
                  <th class="text-center">Parcela</th>
                  <th class="text-center">Vencimento</th>
                  <th class="text-end">Valor</th>
                  <th class="text-center">Situação</th>
                  <th>Descrição</th>
                  <th class="text-center">Ações</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($results as $fatura) {
                  $sit = (string) $fatura->situation;
                ?>
                  <tr>
                    <td>
                      <a href="<?php echo base_url('clientes/info?id=' . (int) $fatura->id_customer); ?>">
                        <?php echo htmlspecialchars((string) $fatura->customer_name, ENT_QUOTES, 'UTF-8'); ?>
                      </a><br />
                      <small class="text-muted"><?php echo cnpj((string) $fatura->customer_document); ?></small>
                    </td>
                    <td class="text-center"><?php echo date('m/Y', strtotime($fatura->competence)); ?></td>
                    <td class="text-center">
                      <?php
                      // "1/1" em toda linha de contrato mensal seria ruído que
                      // esconde as poucas linhas de fato parceladas.
                      $total = (int) $fatura->installments_total;
                      echo $total > 1
                        ? (int) $fatura->installment_number . '/' . $total
                        : '<span class="text-muted">—</span>';
                      ?>
                    </td>
                    <td class="text-center"><?php echo date('d/m/Y', strtotime($fatura->due_date)); ?></td>
                    <td class="text-end">R$ <?php echo reais($fatura->value); ?></td>
                    <td class="text-center">
                      <span class="badge <?php echo isset($badgeSituacao[$sit]) ? $badgeSituacao[$sit] : 'bg-secondary'; ?>">
                        <?php echo isset($situations[$sit]) ? $situations[$sit] : $sit; ?>
                      </span>
                    </td>
                    <td>
                      <small><?php echo htmlspecialchars((string) $fatura->description, ENT_QUOTES, 'UTF-8'); ?></small>
                      <?php if ((int) $fatura->id_charge > 0) { ?>
                        <span class="badge bg-light text-dark border">avulsa</span>
                      <?php } ?>
                    </td>
                    <td class="text-center text-nowrap">
                      <a class="btn btn-sm btn-outline-secondary" href="<?php echo base_url('contratos/info?id=' . (int) $fatura->id_contract); ?>" title="Abrir o contrato">
                        <i class="mdi mdi-file-document-outline"></i>
                      </a>
                      <?php if ((string) $fatura->status === 'aberta') { ?>
                        <button type="button" class="btn btn-sm btn-outline-success btn-status" data-id="<?php echo (int) $fatura->id; ?>" data-acao="pagar" title="Marcar como paga">
                          <i class="mdi mdi-check"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger btn-status" data-id="<?php echo (int) $fatura->id; ?>" data-acao="cancelar" title="Cancelar">
                          <i class="mdi mdi-close"></i>
                        </button>
                      <?php } elseif ((string) $fatura->status === 'paga') { ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-status" data-id="<?php echo (int) $fatura->id; ?>" data-acao="reabrir" title="Desfazer a baixa">
                          <i class="mdi mdi-undo"></i>
                        </button>
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
        <?php } else { ?>
          <div class="alert alert-secondary" role="alert">
            <div class="alert-message">
              <?php if ($temFiltro) { ?>
                Nenhuma fatura encontrada com os filtros aplicados.
              <?php } else { ?>
                Nenhuma fatura gerada ainda, as faturas nascem dos contratos com faturamento pelo CDW Finance.
              <?php } ?>
            </div>
          </div>
        <?php } ?>

      </div>
    </div>
  </div>
</div>

<!-- Offcanvas de filtros: selects sem select2 de propósito — o dropdown dele é
     anexado ao body e briga com o empilhamento do offcanvas. -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvas_filtros_faturas">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title">Filtros</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
  </div>
  <form method="POST" id="form_filtros_faturas" action="<?php echo base_url('faturas/post_filtrar'); ?>">
    <div class="offcanvas-body">
      <div class="mb-3">
        <label class="form-label">Situação</label>
        <select class="form-select" name="<?php echo $filtro_avancado; ?>[situation]">
          <option value="">Todas</option>
          <?php foreach ($situations as $slug => $rotulo) { ?>
            <option value="<?php echo $slug; ?>" <?php if ($situacaoAtual === $slug) echo 'selected=""'; ?>><?php echo $rotulo; ?></option>
          <?php } ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Vencimento a partir de</label>
        <input type="text" class="form-control" data-mask="00/00/0000" name="<?php echo $filtro_avancado; ?>[vencimento_de]" value="<?php echo $de !== '' ? date('d/m/Y', strtotime($de)) : ''; ?>">
      </div>
      <div class="mb-3">
        <label class="form-label">Vencimento até</label>
        <input type="text" class="form-control" data-mask="00/00/0000" name="<?php echo $filtro_avancado; ?>[vencimento_ate]" value="<?php echo $ate !== '' ? date('d/m/Y', strtotime($ate)) : ''; ?>">
      </div>
    </div>
    <div class="offcanvas-header border-top">
      <button type="submit" class="btn btn-primary w-100 me-2"><i class="mdi mdi-filter"></i> FILTRAR</button>
      <button type="submit" class="btn btn-outline-secondary w-100" name="acao" value="limpar">LIMPAR FILTROS</button>
    </div>
  </form>
</div>

<form method="POST" id="form_status_fatura" action="<?php echo base_url('faturas/post_status'); ?>">
  <input type="hidden" name="id" id="status_fatura_id">
  <input type="hidden" name="acao" id="status_fatura_acao">
</form>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    var textos = {
      pagar: {
        titulo: 'Marcar como paga?',
        html: 'A fatura passa a constar como quitada. A baixa automática pelo Bom Controle ainda não existe — esta é a marcação manual.',
        botao: 'Marcar como paga'
      },
      cancelar: {
        titulo: 'Cancelar a fatura?',
        html: 'Ela deixa de contar como valor a receber, mas permanece no histórico do contrato.',
        botao: 'Cancelar fatura'
      },
      reabrir: {
        titulo: 'Desfazer a baixa?',
        html: 'A fatura volta a constar como em aberto.',
        botao: 'Desfazer'
      }
    };

    $('.btn-status').on('click', function() {
      var acao = $(this).data('acao');
      var id = $(this).data('id');
      var texto = textos[acao];
      if (!texto) return;

      Swal.fire({
        title: texto.titulo,
        html: texto.html,
        icon: 'question',
        showCancelButton: true,
        cancelButtonText: 'Voltar',
        confirmButtonText: texto.botao
      }).then(function(result) {
        if (!result.value) return;
        $('#status_fatura_id').val(id);
        $('#status_fatura_acao').val(acao);
        $('#form_status_fatura').submit();
      });
    });
  });
</script>