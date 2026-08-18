<?php
$hoje = date('m/Y');
?>
<div class="row mb-2 mb-xl-3">
  <div class="col-auto d-none d-sm-block">
    <h3><strong>Índices de reajuste</strong></h3>
  </div>
</div>

<?php if (!empty($lacunas)) { ?>
  <div class="alert alert-warning" role="alert">
    <div class="alert-message">
      <h4 class="alert-heading">Faltam lançamentos</h4>
      <p class="mb-2">Há contratos ativos usando estes índices, e a janela de 12 meses está incompleta. <strong>Enquanto faltar qualquer mês, o reajuste desses contratos é adiado</strong> — o sistema não aplica percentual calculado sobre janela parcial.</p>
      <?php foreach ($lacunas as $slug => $lacuna) { ?>
        <p class="mb-1">
          <strong><?php echo htmlspecialchars($lacuna['rotulo'], ENT_QUOTES, 'UTF-8'); ?>:</strong>
          <?php
          $meses = [];
          foreach ($lacuna['faltando'] as $competencia) {
            $partes = explode('-', $competencia);
            $meses[] = $partes[1] . '/' . $partes[0];
          }
          echo htmlspecialchars(implode(', ', $meses), ENT_QUOTES, 'UTF-8');
          ?>
        </p>
      <?php } ?>
    </div>
  </div>
<?php } ?>

<div class="row">
  <div class="col-12 col-lg-4">
    <div class="card">
      <div class="card-body">
        <h5 class="card-title mb-1">Lançar variação</h5>
        <p class="text-muted mb-3 lh-1"><small>Informe a variação <strong>do mês</strong>, não o acumulado — o sistema compõe os 12 meses da janela de cada contrato.</small></p>

        <form method="POST" action="<?php echo base_url('indices/post_salvar'); ?>">
          <div class="form-group mb-3">
            <label class="form-label">* Índice</label>
            <select class="form-control" name="indice[index_slug]" required>
              <?php foreach ($indexes as $slug => $rotulo) { ?>
                <option value="<?php echo $slug; ?>"><?php echo $rotulo; ?></option>
              <?php } ?>
            </select>
          </div>
          <div class="form-group mb-3">
            <label class="form-label">* Competência</label>
            <input type="text" class="form-control" name="indice[competence]" data-mask="00/0000" placeholder="mm/aaaa" value="<?php echo $hoje; ?>" required>
          </div>
          <div class="form-group mb-3">
            <label class="form-label">* Variação do mês (%)</label>
            <input type="text" class="form-control" name="indice[rate]" placeholder="0,52" required>
            <small class="form-text text-muted">Aceita vírgula e valor negativo (deflação).</small>
          </div>
          <button type="submit" class="btn btn-primary w-100"><i class="mdi mdi-content-save"></i> LANÇAR</button>
        </form>

        <hr>
        <p class="text-muted mb-0"><small>
          Relançar um mês que já existe <strong>atualiza</strong> o valor — índices são republicados com frequência.
        </small></p>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-8">
    <div class="card">
      <div class="card-body">
        <div class="row mb-3">
          <div class="col">
            <h5 class="card-title mb-1">Lançamentos</h5>
            <p class="text-muted mb-0 lh-1"><small><?php echo (int) $total_results; ?> registro(s).</small></p>
          </div>
          <div class="col-auto">
            <form method="POST" action="<?php echo base_url('indices/post_filtrar'); ?>">
              <div class="input-group">
                <select class="form-select" name="index_slug" onchange="this.form.submit()">
                  <option value="">Todos os índices</option>
                  <?php foreach ($indexes as $slug => $rotulo) { ?>
                    <option value="<?php echo $slug; ?>" <?php if ($filtro_slug === $slug) echo 'selected=""'; ?>><?php echo $rotulo; ?></option>
                  <?php } ?>
                </select>
              </div>
            </form>
          </div>
        </div>

        <?php if (!empty($results)) { ?>
          <div class="table-responsive">
            <table class="table table-striped table-bordered table-hover">
              <thead>
                <tr>
                  <th>Índice</th>
                  <th class="text-center">Competência</th>
                  <th class="text-end">Variação</th>
                  <th class="text-center">Ações</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($results as $linha) {
                  $rate = (float) $linha->rate;
                ?>
                  <tr>
                    <td><?php echo htmlspecialchars(isset($indexes[$linha->index_slug]) ? $indexes[$linha->index_slug] : $linha->index_slug, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-center"><?php echo date('m/Y', strtotime($linha->competence)); ?></td>
                    <td class="text-end <?php echo $rate < 0 ? 'text-danger' : ''; ?>">
                      <?php echo number_format($rate, 4, ',', '.'); ?>%
                    </td>
                    <td class="text-center">
                      <button type="button" class="btn btn-sm btn-outline-danger btn-excluir-indice"
                        data-id="<?php echo (int) $linha->id; ?>"
                        data-rotulo="<?php echo htmlspecialchars((isset($indexes[$linha->index_slug]) ? $indexes[$linha->index_slug] : $linha->index_slug) . ' ' . date('m/Y', strtotime($linha->competence)), ENT_QUOTES, 'UTF-8'); ?>">
                        <i class="mdi mdi-trash-can-outline"></i>
                      </button>
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
              Nenhum lançamento ainda. Sem os 12 meses da janela, nenhum contrato é reajustado.
            </div>
          </div>
        <?php } ?>
      </div>
    </div>
  </div>
</div>

<form method="POST" id="form_excluir_indice" action="<?php echo base_url('indices/post_excluir'); ?>">
  <input type="hidden" name="id" id="excluir_indice_id">
</form>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    $('.btn-excluir-indice').on('click', function() {
      var id = $(this).data('id');
      var rotulo = $(this).data('rotulo');

      Swal.fire({
        title: 'Excluir o lançamento?',
        html: 'O mês <strong>' + rotulo + '</strong> deixa de existir, e os contratos que dependem dele param de ser reajustados até que ele seja lançado de novo.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonText: 'Cancelar',
        confirmButtonText: 'Excluir'
      }).then(function(result) {
        if (!result.value) return;
        $('#excluir_indice_id').val(id);
        $('#form_excluir_indice').submit();
      });
    });
  });
</script>
