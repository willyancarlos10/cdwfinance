<?php
$filtro = (array) $this->session->userdata('f_servers');
$badgeTipo = [
  'whm' => 'bg-secondary',
  'directadmin' => 'bg-primary',
  'cloudpanel' => 'bg-info',
];
?>
<div class="row mb-2 mb-xl-2">
  <div class="col-auto text-start">
    <h1 class="h3 mb-3">Servidores</h1>
  </div>
  <div class="col-auto ms-auto text-end mt-n1">
    <a class="btn btn-outline-secondary" href="<?php echo base_url('servidores/dominios'); ?>"><i class="fa fa-globe"></i> DOMÍNIOS</a>
    <a class="btn btn-primary" href="<?php echo base_url('servidores/novo'); ?>"><i class="fa fa-plus"></i> NOVO</a>
  </div>
</div>

<div class="card flex-fill">
  <div class="card-body py-3">
    <form method="POST" name="form" action="<?php echo base_url('servidores/post_filtrar'); ?>" enctype="multipart/form-data">
      <div class="row">
        <div class="col-md-5">
          <div class="form-group mb-3">
            <label class="form-label">Buscar</label>
            <input type="text" placeholder="Nome, endereço ou usuário..." class="form-control" name="f_servers[keyword]" value="<?php echo htmlspecialchars(isset($filtro['keyword']) ? $filtro['keyword'] : '', ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
          </div>
        </div>
        <div class="col-md-3">
          <div class="form-group mb-3">
            <label class="form-label">Tipo</label>
            <select name="f_servers[type]" class="form-control select2">
              <option value="">-- MOSTRAR TUDO --</option>
              <?php foreach ($server_types as $alias => $rotulo) { ?>
                <option <?php if (isset($filtro['type']) && $filtro['type'] === $alias) echo 'selected=""'; ?> value="<?php echo $alias; ?>"><?php echo $rotulo; ?></option>
              <?php } ?>
            </select>
          </div>
        </div>
        <div class="col-md-2">
          <div class="form-group mb-3">
            <label class="form-label">Status</label>
            <select name="f_servers[id_status]" class="form-control select2">
              <option value="">-- TODOS --</option>
              <?php foreach ($crm_status as $c) { ?>
                <option <?php if (isset($filtro['id_status']) && (string) $filtro['id_status'] === (string) $c->id) echo 'selected=""'; ?> value="<?php echo $c->id; ?>"><?php echo $c->name; ?></option>
              <?php } ?>
            </select>
          </div>
        </div>
        <div class="col-md-2">
          <label class="form-label">&nbsp;</label><br />
          <button type="submit" class="btn w-100 btn-primary btn-md"><i class="fa fa-filter"></i> FILTRAR</button>
        </div>
      </div>
    </form>

    <?php if (!empty($results)) { ?>
      <hr>
      <div class="table-responsive">
        <table class="table table-striped table-bordered table-hover">
          <thead>
            <tr>
              <th class="text-center" style="width: 210px;">Ações</th>
              <th class="text-center" style="width: 70px;">Ativo</th>
              <th>Servidor</th>
              <th class="text-center">Tipo</th>
              <th class="text-center">Domínios</th>
              <th class="text-center">Último teste</th>
              <th class="text-center">Última sincronização</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($results as $row) { ?>
              <tr>
                <td align="center">
                  <button type="button" class="btn btn-sm btn-outline-primary js-testar" data-id="<?php echo (int) $row->id; ?>" title="Testar conexão"><i class="fa fa-plug"></i></button>
                  <button type="button" class="btn btn-sm btn-outline-success js-sincronizar" data-id="<?php echo (int) $row->id; ?>" data-nome="<?php echo htmlspecialchars($row->name, ENT_QUOTES, 'UTF-8'); ?>" title="Sincronizar domínios"><i class="fa fa-sync"></i></button>
                  <a class="btn btn-sm btn-outline-secondary" href="<?php echo base_url('servidores/editar?id=' . (int) $row->id); ?>" title="Editar"><i class="fa fa-edit"></i></a>
                </td>
                <td align="center">
                  <div class="form-check form-switch d-flex justify-content-center m-0">
                    <input class="form-check-input js-toggle-status" type="checkbox" role="switch" data-table="crm_servers" data-id="<?php echo (int) $row->id; ?>" aria-label="Ativar ou desativar servidor" <?php echo ((int) $row->id_status === 1) ? 'checked' : ''; ?>>
                  </div>
                </td>
                <td>
                  <a href="<?php echo base_url('servidores/editar?id=' . (int) $row->id); ?>"><?php echo htmlspecialchars($row->name, ENT_QUOTES, 'UTF-8'); ?></a><br />
                  <small class="text-muted"><?php echo htmlspecialchars($row->username, ENT_QUOTES, 'UTF-8'); ?>@<?php echo htmlspecialchars($row->host, ENT_QUOTES, 'UTF-8'); ?><?php if ($row->type === 'cloudpanel' && !empty($row->port)) echo ':' . (int) $row->port; ?></small>
                </td>
                <td align="center">
                  <span class="badge <?php echo isset($badgeTipo[$row->type]) ? $badgeTipo[$row->type] : 'bg-dark'; ?>"><?php echo isset($server_types[$row->type]) ? $server_types[$row->type] : $row->type; ?></span>
                </td>
                <td align="center"><?php echo numero((int) $row->domains_count); ?></td>
                <td align="center" data-test-cell="<?php echo (int) $row->id; ?>">
                  <?php if (empty($row->last_test)) { ?>
                    <span class="badge bg-secondary" data-test-badge>Nunca testado</span>
                  <?php } else { ?>
                    <span class="badge bg-<?php echo ($row->last_test_status === 'conectado') ? 'success' : 'danger'; ?>" data-test-badge><?php echo ($row->last_test_status === 'conectado') ? 'Conectado' : 'Erro'; ?></span>
                    <br /><small class="text-muted" data-test-date><?php echo data($row->last_test); ?><?php if (!empty($row->last_test_ms)) echo ' · ' . numero((int) $row->last_test_ms) . 'ms'; ?></small>
                  <?php } ?>
                  <?php if (!empty($row->last_test_message) && $row->last_test_status !== 'conectado') { ?>
                    <br /><small class="text-danger d-inline-block" style="max-width: 260px;"><?php echo htmlspecialchars($row->last_test_message, ENT_QUOTES, 'UTF-8'); ?></small>
                  <?php } ?>
                </td>
                <td align="center" data-sync-cell="<?php echo (int) $row->id; ?>">
                  <?php if (empty($row->last_sync)) { ?>
                    <span class="text-muted">—</span>
                  <?php } else { ?>
                    <span class="badge bg-<?php echo ($row->last_sync_status === 'sucesso') ? 'success' : (($row->last_sync_status === 'parcial') ? 'warning' : 'danger'); ?>"><?php echo ucfirst((string) $row->last_sync_status); ?></span>
                    <br /><small class="text-muted"><?php echo data($row->last_sync); ?></small>
                    <?php if (!empty($row->last_sync_message)) { ?>
                      <br /><small class="text-muted d-inline-block" style="max-width: 280px;"><?php echo htmlspecialchars($row->last_sync_message, ENT_QUOTES, 'UTF-8'); ?></small>
                    <?php } ?>
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
          <div class="row">
            <div class="col-12 col-sm-6">
              <p class="mb-0">Total: <strong><?php echo numero($est_count); ?></strong> servidor(es).</p>
            </div>
          </div>
        </div>
      </div>
    <?php } else { ?>
      <div class="alert alert-secondary alert-dismissible" role="alert">
        <button type="button" class="btn-close d-none" data-bs-dismiss="alert" aria-label="Close"></button>
        <div class="alert-message">Nenhum servidor encontrado. Clique em <strong>NOVO</strong> para cadastrar o primeiro.</div>
      </div>
    <?php } ?>
  </div>
</div>

<script>
  document.addEventListener("DOMContentLoaded", function() {
    function notificar(tipo, mensagem) {
      window.notyf.open({
        type: tipo,
        message: mensagem,
        duration: 7000,
        ripple: true,
        dismissible: true,
        position: {
          x: 'top',
          y: 'top'
        }
      });
    }

    // Sessão expirada: o MY_Controller responde 200 com {redirect:true}, então
    // a checagem precisa ser feita dentro do success, não pelo status HTTP.
    function sessaoExpirou(data) {
      if (data && data.redirect) {
        window.location.replace('<?php echo base_url('painel/sair_custom'); ?>');
        return true;
      }
      return false;
    }

    $(document).on('change', '.js-toggle-status', function() {
      var $sw = $(this);
      var $row = $sw.closest('tr');
      var id = $sw.data('id');
      var ativar = $sw.is(':checked') ? 1 : 0;

      $sw.prop('disabled', true);

      $.ajax({
        type: 'POST',
        url: '<?php echo base_url('servidores/json_posttoggle_status'); ?>',
        data: {
          table: $sw.data('table'),
          id: id,
          ativar: ativar
        },
        dataType: 'json',
        success: function(data) {
          if (sessaoExpirou(data)) return;
          if (!data || !data.return) {
            $sw.prop('checked', !ativar);
            notificar('error', (data && data.message) ? data.message : 'Erro ao alterar status.');
            return;
          }
          notificar('success', data.message || 'Status alterado com sucesso.');
        },
        error: function(xhr) {
          $sw.prop('checked', !ativar);
          console.log(xhr.responseText);
          notificar('error', 'Erro ao alterar status.');
        },
        complete: function() {
          $sw.prop('disabled', false);
        }
      });
    });

    $(document).on('click', '.js-testar', function() {
      var $btn = $(this);
      var id = $btn.data('id');
      var $icone = $btn.find('i');

      $btn.prop('disabled', true);
      $icone.removeClass('fa-plug').addClass('fa-spinner fa-spin');

      $.ajax({
        type: 'POST',
        url: '<?php echo base_url('servidores/json_posttestar'); ?>',
        data: {
          id: id
        },
        dataType: 'json',
        success: function(data) {
          if (sessaoExpirou(data)) return;

          if (!data || !data.return) {
            notificar('error', (data && data.message) ? data.message : 'Falha no teste de conexão.');
          } else {
            notificar('success', data.message);
          }

          // Recarrega para refletir badge, data e tempo de resposta na linha.
          setTimeout(function() {
            window.location.reload();
          }, 1200);
        },
        error: function(xhr) {
          console.log(xhr.responseText);
          notificar('error', 'Falha ao testar a conexão.');
        },
        complete: function() {
          $btn.prop('disabled', false);
          $icone.removeClass('fa-spinner fa-spin').addClass('fa-plug');
        }
      });
    });

    $(document).on('click', '.js-sincronizar', function() {
      var $btn = $(this);
      var id = $btn.data('id');
      var nome = $btn.data('nome');

      Swal.fire({
        title: 'Sincronizar domínios?',
        html: 'Serão importados os domínios de <strong>' + nome + '</strong>.<br><small>Domínios que não existirem mais no servidor serão removidos daqui.</small>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#ccc',
        cancelButtonText: 'Cancelar',
        confirmButtonText: 'Sincronizar'
      }).then(function(result) {
        if (!result.value) return;

        var $icone = $btn.find('i');
        $btn.prop('disabled', true);
        $icone.addClass('fa-spin');
        $('#modal_loading').modal('show');

        $.ajax({
          type: 'POST',
          url: '<?php echo base_url('servidores/json_postsincronizar'); ?>',
          data: {
            id: id
          },
          dataType: 'json',
          success: function(data) {
            if (sessaoExpirou(data)) return;

            if (!data || !data.return) {
              $('#modal_loading').modal('hide');
              Swal.fire('Falha na sincronização', (data && data.message) ? data.message : 'Não foi possível sincronizar.', 'error');
              return;
            }

            notificar('success', data.message);
            setTimeout(function() {
              window.location.reload();
            }, 1200);
          },
          error: function(xhr) {
            $('#modal_loading').modal('hide');
            console.log(xhr.responseText);
            Swal.fire('Erro', 'Falha ao sincronizar os domínios.', 'error');
          },
          complete: function() {
            $btn.prop('disabled', false);
            $icone.removeClass('fa-spin');
          }
        });
      });
    });
  });
</script>
