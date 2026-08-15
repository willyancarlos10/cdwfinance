<div class="row mb-2 mb-xl-2">
  <div class="col text-start">
    <h3>Grupos de acesso</h3>
  </div>
</div>
<div class="card flex-fill">
  <div class="card-body">
    <div class="row flex-fill">
      <div class="col-12">
        <div class="tab">
          <ul class="nav nav-pills mt-2" role="tablist">
            <li class="nav-item"><a class="nav-link active" href="#tab-1" data-bs-toggle="tab" role="tab" aria-selected="true">Listagem</a></li>
            <li class="nav-item"><a class="nav-link" href="#tab-2" data-bs-toggle="tab" role="tab"><i class="fa fa-plus"></i> Novo</a></li>
          </ul>
          <div class="tab-content" style="box-shadow: none; padding: 10px 0 0 0;">
            <div class="tab-pane active" id="tab-1" role="tabpanel">
              <div class="table-responsive">
                <table class="table table-striped table-bordered table-hover table-checkable order-column">
                  <thead>
                    <tr>
                      <th class="text-center">Ações</th>
                      <th>Nome</th>
                      <th>Empresa</th>
                      <th>Última modif.</th>
                      <th>Usuário modif.</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($crm_user_groups)) { ?>
                      <tr>
                        <td colspan="6" class="text-center text-muted py-4">Nenhum grupo encontrado.</td>
                      </tr>
                    <?php } else { ?>
                      <?php foreach ($crm_user_groups as $c) { ?>
                        <tr>
                          <td align="center">
                            <div class="form-check form-switch d-flex justify-content-center m-0">
                              <input class="form-check-input js-toggle-status" type="checkbox" role="switch" data-table="crm_user_groups" data-id="<?php echo (int) $c->id; ?>" aria-label="Ativar ou desativar grupo" <?php echo ((int) $c->id_status === 1) ? 'checked' : ''; ?>>
                            </div>
                          </td>
                          <td><a href="<?php echo base_url('empresas/grupos_editar?id=' . $c->id); ?>"><?php echo $c->name; ?></a></td>
                          <td align="center"><?php
                                              $active = json_decode($c->companies);
                                              if (empty($active)) $active = []; ?>
                            <?php if (count($active) == 0) { ?>
                              <strong>-</strong>
                            <?php } else {
                              echo count($active);
                            } ?>
                          </td>
                          <td align="center"><?php echo data($c->modified); ?></td>
                          <td align="center"><?php echo primeiro_nome($c->modified_user); ?></td>
                          <td align="center"><span class="badge w-100 bg-<?php echo $c->status_color; ?>" data-status-badge><?php echo $c->status_name; ?></span></td>
                        </tr>
                      <?php } ?>
                    <?php } ?>
                  </tbody>
                </table>
              </div>
            </div>
            <div class="tab-pane" id="tab-2" role="tabpanel">
              <form method="POST" action="<?php echo current_url(); ?>" name="form" enctype="multipart/form-data">
                <div class="row">
                  <div class="col-xs-12 col-md-12">
                    <div class="form-group mb-3">
                      <label class="form-label"><span class="required" aria-required="true"> * </span> Nome</label>
                      <input type="text" class="form-control" name="name" required="">
                    </div>
                  </div>
                  <div class="col-xs-12 col-md-12">
                    <div class="form-group mb-3">
                      <label class="form-label">Selecionar empresas</label>
                      <select class="form-control select2 select2-multiple" multiple name="companies[]">
                        <?php foreach ($crm_companies_v as $c) { ?>
                          <option value="<?php echo $c->id; ?>"><?php echo $c->byname . ' - ' . $c->cnpj; ?></option>
                        <?php } ?>
                      </select>
                    </div>
                  </div>
                </div>
                <div class="row">
                  <div class="col-xs-12 col-md-12">
                    <hr>
                  </div>
                </div>
                <div class="row">
                  <div class="col"><button class="btn btn-primary w-100" type="submit"><i class="fa fa-save"></i> SALVAR</button></div>
                  <div class="col"></div>
                  <div class="col"></div>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  document.addEventListener("DOMContentLoaded", function() {
    function notifyStatus(type, message) {
      window.notyf.open({
        type: type,
        message: message,
        duration: 5000,
        ripple: true,
        dismissible: true,
        position: {
          x: 'top',
          y: 'top'
        }
      });
    }

    $(document).on('change', '.js-toggle-status', function() {
      var $sw = $(this);
      var $row = $sw.closest('tr');
      var ativar = $sw.is(':checked') ? 1 : 0;

      $sw.prop('disabled', true);
      $.ajax({
        type: 'POST',
        url: '<?php echo base_url('empresas/json_posttoggle_status'); ?>',
        data: {
          table: $sw.data('table'),
          id: $sw.data('id'),
          ativar: ativar
        },
        dataType: 'json',
        success: function(data) {
          if (data && data.redirect) {
            window.location.replace('<?php echo base_url('painel/sair_custom'); ?>');
            return;
          }
          if (!data || !data.return) {
            $sw.prop('checked', !ativar);
            notifyStatus('error', (data && data.message) ? data.message : 'Erro ao alterar status.');
            return;
          }
          if (data.data) {
            $row.find('[data-status-badge]').attr('class', 'badge w-100 bg-' + data.data.status_color).text(data.data.status_name);
          }
          notifyStatus('success', data.message || 'Status alterado com sucesso.');
        },
        error: function(xhr) {
          $sw.prop('checked', !ativar);
          console.log(xhr.responseText);
          notifyStatus('error', 'Erro ao alterar status.');
        },
        complete: function() {
          $sw.prop('disabled', false);
        }
      });
    });
  });
</script>
