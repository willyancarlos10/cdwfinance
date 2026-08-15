<div class="row mb-2 mb-xl-2">
  <div class="col text-start">
    <h3>Permissões de acesso</h3>
  </div>
  <div class="col-auto ms-auto text-end mt-n1"></div>
</div>
<div class="card flex-fill">
  <div class="card-body">
    <form method="POST" name="form" action="<?php echo base_url('permissoes/filtrar'); ?>">
      <div class="row">
        <div class="col-md-9">
          <div class="form-group mb-3">
            <label for="keyword" class="form-label mb-0">Buscar</label>
            <div class="form-group mb-3">
              <input type="text" class="form-control" name="f_permissoes[keyword]" value="<?php echo $this->session->userdata('f_permissoes')['keyword']; ?>" autocomplete="off">
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="text-start">
            <label class="form-label mb-0">&nbsp;&nbsp;&nbsp;&nbsp;</label><br />
            <button type="submit" class="btn w-100 btn-primary"><i class="fa fa-filter"></i> FILTRAR</button>
          </div>
        </div>
      </div>
      <div class="row">
        <div class="col-md-12">
          <hr>
        </div>
      </div>
    </form>
    <div class="row">
      <?php
      $allow = TRUE;
      $currentCompanyId = (int) ($this->session->userdata('f_company')['id_company'] ?? $this->session->userdata('company')->id);
      if ($currentCompanyId == 1) {
        if (!$this->global_model->checkPermission('10')) $allow = FALSE;
      } ?>
      <?php if ($allow) { ?>
        <div class="tab">
          <ul class="nav nav-pills mt-2" role="tablist">
            <li class="nav-item"><a class="nav-link active" href="#tab-1" data-bs-toggle="tab" role="tab" aria-selected="true">Listagem</a></li>
            <li class="nav-item"><a class="nav-link" href="#tab-2" data-bs-toggle="tab" role="tab"><i class="fa fa-plus"></i> Novo</a></li>
          </ul>
          <div class="tab-content" style="box-shadow: none; padding: 10px 0 0 0;">
            <div class="tab-pane active" id="tab-1" role="tabpanel">
              <div class="table-responsive">
                <?php if (!empty($results)) { ?>
                  <table class="table table-striped table-bordered table-hover table-checkable order-column">
                    <thead>
                      <tr>
                        <th class="text-center">Ações</th>
                        <th>Nome</th>
                        <th>Permissões</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($results as $c) { ?>
                        <tr class="odd gradeX">
                          <td class="text-center">
                            <div class="form-check form-switch d-flex justify-content-center m-0">
                              <input class="form-check-input js-toggle-status" type="checkbox" role="switch" data-table="crm_user_permissions" data-id="<?php echo (int) $c->id; ?>" aria-label="Ativar ou desativar permissão" <?php echo ((int) $c->id_status === 1) ? 'checked' : ''; ?>>
                            </div>
                          </td>
                          <td><a href="<?php echo base_url('permissoes/editar/' . $c->id); ?>"><?php echo $c->name; ?></a></td>
                          <td>
                            <?php
                            $currentItens = json_decode($c->permissions, true);
                            $identificadores = $this->global_model->getWhereOrderBy_off('crm_user_permissions_items', "1=1", 'name', 'asc', FALSE);
                            if (empty($identificadores)) { ?>
                              <span class="badge w-100 bg-danger">Nenhuma permissão selecionada</span>
                            <?php } else { ?>
                              <?php foreach ($identificadores as $row) { ?>
                                <?php if (in_array($row->id, $currentItens)) { ?>
                                  <i class="mdi mdi-check" title="<?php echo $row->name; ?>"></i> <?php echo $row->name; ?><br />
                                <?php } else { ?>
                                  <i class="mdi mdi-close" title="<?php echo $row->name; ?>"></i> <?php echo $row->name; ?><br />
                            <?php }
                              }
                            }
                            ?>
                          </td>
                          <td><span class="badge w-100 bg-<?php echo $c->status_color; ?>" data-status-badge><?php echo $c->status; ?></span></td>
                        </tr>
                      <?php } ?>
                    </tbody>
                  </table>
                  <div class="row">
                    <div class="col-12">
                      <div class="mb-3">
                        <div class="alert alert-primary alert-outline alert-dismissible" role="alert">
                          <button type="button" class="btn-close d-none" data-bs-dismiss="alert" aria-label="Close"></button>
                          <div class="alert-message">
                            <h4 class="alert-heading">Resumo</h4>
                            <hr>
                            <p class="mb-0">
                              Total: <strong><?php echo count($results); ?></strong><br>
                            </p>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                <?php } else { ?>
                  <div class="alert alert-default">Nenhum registro encontrado.</div>
                <?php } ?>
              </div>
            </div>
            <div class="tab-pane" id="tab-2" role="tabpanel">
              <form method="POST" action="<?php echo base_url('permissoes/novo'); ?>" name="form" id="form_permission_new" enctype="multipart/form-data">
                <div class="row">
                  <div class="col-md-4">
                    <div class="form-group mb-3">
                      <label class="form-label mb-0"><span class="required" aria-required="true"> * </span> Nome </label>
                      <input type="text" class="form-control js-origin-lock-readonly-new" name="name" required="">
                    </div>
                  </div>
                  <div class="col-12 mt-0">
                    <table class="table table-striped table-bordered table-hover">
                      <thead>
                        <tr>
                          <th>Permissão</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($crm_permissions_items as $c) { ?>
                          <tr>
                            <td>
                              <div class="form-check form-switch mb-2 ">
                                <input class="form-check-input js-origin-lock-disable-new" id="<?php echo 'id' . $c->id; ?>" type="checkbox" name="permissions[]" value="<?php echo $c->id; ?>">
                                <label for="<?php echo 'id' . $c->id; ?>" class="form-check-label"><?php echo $c->id . ' - ' . $c->name; ?></label>
                              </div>
                            </td>
                          </tr>
                        <?php }  ?>
                      </tbody>
                    </table>
                  </div>
                </div>
                <div class="row">
                  <div class="col-xs-12 col-md-12">
                    <hr>
                  </div>
                </div>
                <div class="row">
                  <div class="col">
                    <button class="btn btn-primary w-100" type="submit"><i class="fa fa-save"></i> SALVAR</button>
                  </div>
                  <div class="col"></div>
                  <div class="col"></div>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php } else { ?>
        <div class="col-12 text-start">
          <div class="alert alert-warning alert-dismissible" role="alert">
            <button type="button" class="btn-close d-none" data-bs-dismiss="alert" aria-label="Close"></button>
            <div class="alert-message">
              Sem permissão de acesso para modificar permissões.
            </div>
          </div>
        </div>
      <?php } ?>
    </div>
  </div>
</div>

<script type="text/javascript">
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
        url: '<?php echo base_url('permissoes/json_posttoggle_status'); ?>',
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
