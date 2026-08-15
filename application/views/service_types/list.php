<div class="row mb-2 mb-xl-2">
  <div class="col text-start">
    <h3>Tipos de serviços</h3>
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
                      <th>Última modif.</th>
                      <th>Usuário modif.</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($crm_service_types)) { ?>
                      <tr>
                        <td colspan="5" class="text-center text-muted py-4">Nenhum tipo de serviço encontrado.</td>
                      </tr>
                    <?php } else { ?>
                      <?php foreach ($crm_service_types as $c) { ?>
                        <tr>
                          <td align="center">
                            <div class="d-flex justify-content-center align-items-center gap-2">
                              <div class="form-check form-switch m-0">
                                <input class="form-check-input js-toggle-status" type="checkbox" role="switch" data-table="crm_service_types" data-id="<?php echo (int) $c->id; ?>" aria-label="Ativar ou desativar tipo de serviço" <?php echo ((int) $c->id_status === 1) ? 'checked' : ''; ?>>
                              </div>
                              <button type="button" class="btn btn-sm btn-outline-danger js-excluir" data-id="<?php echo (int) $c->id; ?>" data-name="<?php echo htmlspecialchars($c->name, ENT_QUOTES, 'UTF-8'); ?>" title="Excluir tipo de serviço"><i class="fa fa-trash"></i></button>
                            </div>
                          </td>
                          <td><a href="<?php echo base_url('tipos-servicos/editar?id=' . $c->id); ?>"><?php echo $c->name; ?></a></td>
                          <td align="center"><?php echo data($c->modified); ?></td>
                          <td align="center"><?php echo primeiro_nome($c->modified_user); ?></td>
                          <td align="center"><span class="badge w-100 bg-<?php echo $c->status_color; ?>" data-status-badge><?php echo $c->status_name; ?></span></td>
                        </tr>
                      <?php } ?>
                    <?php } ?>
                  </tbody>
                </table>
              </div>
              <p class="text-muted mb-0"><small>A exclusão é definitiva. Quando o módulo de Contratos existir, tipos vinculados a contratos não poderão ser excluídos.</small></p>
            </div>
            <div class="tab-pane" id="tab-2" role="tabpanel">
              <form method="POST" action="<?php echo current_url(); ?>" name="form" enctype="multipart/form-data">
                <div class="row">
                  <div class="col-xs-12 col-md-12">
                    <div class="form-group mb-3">
                      <label class="form-label"><span class="required" aria-required="true"> * </span> Nome</label>
                      <input type="text" class="form-control" name="name" maxlength="150" required="">
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

<form method="POST" id="form_excluir" action="<?php echo base_url('tipos-servicos/post_excluir'); ?>">
  <input type="hidden" name="id" value="">
</form>

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
        url: '<?php echo base_url('tipos-servicos/json_posttoggle_status'); ?>',
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

    $(document).on('click', '.js-excluir', function() {
      var id = $(this).data('id');
      var name = $(this).data('name');
      Swal.fire({
        title: 'Excluir tipo de serviço?',
        html: 'O tipo <strong>' + name + '</strong> será excluído <strong>definitivamente</strong>.<br><small>Esta ação não pode ser desfeita.</small>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#ccc',
        cancelButtonText: 'Cancelar',
        confirmButtonText: 'Excluir'
      }).then(function(result) {
        if (result.value) {
          $('#form_excluir input[name="id"]').val(id);
          $('#form_excluir').submit();
        }
      });
    });
  });
</script>
