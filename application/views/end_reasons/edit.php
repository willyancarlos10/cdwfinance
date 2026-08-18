<div class="row mb-2 mb-xl-2">
  <div class="col text-start">
    <h3>Editando: <?php echo htmlspecialchars($result->name, ENT_QUOTES, 'UTF-8'); ?></h3>
  </div>
</div>
<div class="card flex-fill">
  <div class="card-body">
    <div class="row">
      <div class="col-12 col-sm-8">
        <form method="POST" action="<?php echo current_url(); ?>" name="form" enctype="multipart/form-data">
          <input type="hidden" name="id" value="<?php echo (int) $result->id; ?>" />
          <div class="row">
            <div class="col-xs-12 col-md-12">
              <div class="form-group mb-3">
                <label class="form-label"><span class="required" aria-required="true"> * </span> Nome</label>
                <input type="text" class="form-control" name="name" maxlength="150" required="" value="<?php echo htmlspecialchars($result->name, ENT_QUOTES, 'UTF-8'); ?>">
                <small class="form-text text-muted">
                  Renomear é seguro: os contratos já encerrados passam a exibir o nome novo, porque o que eles
                  guardam é o identificador abaixo.
                </small>
              </div>
            </div>
          </div>
          <div class="row">
            <div class="col-xs-12 col-md-12">
              <div class="form-group mb-3">
                <label class="form-label">Identificador</label>
                <?php // Somente leitura: é o valor gravado em crm_contracts.ended_reason. Trocá-lo órfãnaria o histórico. ?>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($result->slug, ENT_QUOTES, 'UTF-8'); ?>" readonly disabled>
                <small class="form-text text-muted">
                  Gerado na criação e imutável — é o que fica gravado no contrato encerrado.
                  <?php if ((int) $result->contracts_count > 0) { ?>
                    Em uso por <strong><?php echo numero($result->contracts_count); ?></strong> contrato(s).
                  <?php } ?>
                </small>
              </div>
            </div>
          </div>
          <div class="row">
            <div class="col-xs-12 col-md-6">
              <div class="form-group mb-3">
                <label class="form-label">Cor no gráfico</label>
                <div class="input-group">
                  <span class="input-group-text p-1"><span class="d-inline-block rounded js-cor-preview" style="width: 24px; height: 24px; background-color: <?php echo htmlspecialchars($result->color, ENT_QUOTES, 'UTF-8'); ?>;"></span></span>
                  <select class="form-control js-cor-select" name="color">
                    <?php foreach ($paleta as $hex => $rotulo) { ?>
                      <option value="<?php echo $hex; ?>" <?php if ($result->color === $hex) echo 'selected=""'; ?>><?php echo $rotulo; ?></option>
                    <?php } ?>
                  </select>
                </div>
                <small class="form-text text-muted">Usada na pizza e na barra do card de cancelamentos do Dashboard.</small>
              </div>
            </div>
            <div class="col-xs-12 col-md-6">
              <div class="form-group mb-3">
                <label class="form-label">Ordem</label>
                <input type="number" class="form-control" name="sort_order" min="0" max="999" value="<?php echo (int) $result->sort_order; ?>">
                <small class="form-text text-muted">Posição no select do encerramento e na legenda do gráfico.</small>
              </div>
            </div>
          </div>
          <div class="row">
            <div class="col-xs-12 col-md-12">
              <hr>
            </div>
          </div>
          <div class="row">
            <div class="col">
              <button class="btn w-100 btn-primary" type="submit"><i class="fa fa-save"></i> SALVAR</button>
            </div>
            <div class="col"></div>
            <div class="col">
              <a href="<?php echo base_url('motivos-cancelamento'); ?>" class="btn w-100 btn-outline-secondary"><i class="fa fa-arrow-left"></i> VOLTAR</a>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
  document.addEventListener("DOMContentLoaded", function() {
    $(document).on('change', '.js-cor-select', function() {
      $(this).closest('.input-group').find('.js-cor-preview').css('background-color', $(this).val());
    });
  });
</script>
