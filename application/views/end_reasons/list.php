<div class="row mb-2 mb-xl-2">
  <div class="col text-start">
    <h3>Motivos de cancelamento</h3>
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
                      <th class="text-center">Cor</th>
                      <th class="text-center">Ordem</th>
                      <th class="text-center">Em uso</th>
                      <th>Última modif.</th>
                      <th>Usuário modif.</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($crm_end_reasons)) { ?>
                      <tr>
                        <td colspan="8" class="text-center text-muted py-4">Nenhum motivo de cancelamento encontrado.</td>
                      </tr>
                    <?php } else { ?>
                      <?php foreach ($crm_end_reasons as $c) { ?>
                        <tr>
                          <td align="center">
                            <div class="d-flex justify-content-center align-items-center gap-2">
                              <div class="form-check form-switch m-0">
                                <input class="form-check-input js-toggle-status" type="checkbox" role="switch" data-table="crm_end_reasons" data-id="<?php echo (int) $c->id; ?>" aria-label="Ativar ou desativar motivo de cancelamento" <?php echo ((int) $c->id_status === 1) ? 'checked' : ''; ?>>
                              </div>
                              <?php // Motivo em uso não pode ser excluído (o contrato guarda o slug); o botão já avisa antes do clique. ?>
                              <button type="button" class="btn btn-sm btn-outline-danger js-excluir" data-id="<?php echo (int) $c->id; ?>" data-name="<?php echo htmlspecialchars($c->name, ENT_QUOTES, 'UTF-8'); ?>" data-uso="<?php echo (int) $c->contracts_count; ?>" title="<?php echo ((int) $c->contracts_count > 0) ? 'Motivo em uso — inative em vez de excluir' : 'Excluir motivo'; ?>"><i class="fa fa-trash"></i></button>
                            </div>
                          </td>
                          <td>
                            <a href="<?php echo base_url('motivos-cancelamento/editar?id=' . $c->id); ?>"><?php echo htmlspecialchars($c->name, ENT_QUOTES, 'UTF-8'); ?></a>
                            <small class="d-block text-muted"><?php echo htmlspecialchars($c->slug, ENT_QUOTES, 'UTF-8'); ?></small>
                          </td>
                          <td align="center">
                            <?php // A mesma cor da fatia da pizza e da barra no card de cancelamentos do Dashboard. ?>
                            <span class="d-inline-block rounded" style="width: 18px; height: 18px; background-color: <?php echo htmlspecialchars($c->color, ENT_QUOTES, 'UTF-8'); ?>;" title="<?php echo htmlspecialchars($c->color, ENT_QUOTES, 'UTF-8'); ?>"></span>
                          </td>
                          <td align="center"><?php echo (int) $c->sort_order; ?></td>
                          <td align="center">
                            <?php if ((int) $c->contracts_count > 0) { ?>
                              <span class="badge bg-light text-dark border" title="Contratos encerrados com este motivo"><?php echo numero($c->contracts_count); ?></span>
                            <?php } else { ?>
                              <span class="text-muted">—</span>
                            <?php } ?>
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
              <p class="text-muted mb-0">
                <small>
                  O identificador abaixo do nome é o que fica gravado no contrato encerrado — ele não muda quando o
                  nome é editado. Motivo já usado não pode ser excluído: inative-o para tirá-lo do modal de
                  encerramento sem perder o histórico.
                </small>
              </p>
            </div>
            <div class="tab-pane" id="tab-2" role="tabpanel">
              <form method="POST" action="<?php echo current_url(); ?>" name="form" enctype="multipart/form-data">
                <div class="row">
                  <div class="col-xs-12 col-md-12">
                    <div class="form-group mb-3">
                      <label class="form-label"><span class="required" aria-required="true"> * </span> Nome</label>
                      <input type="text" class="form-control" name="name" maxlength="150" required="">
                      <small class="form-text text-muted">O identificador interno é gerado a partir do nome e não muda depois.</small>
                    </div>
                  </div>
                </div>
                <div class="row">
                  <div class="col-xs-12 col-md-6">
                    <div class="form-group mb-3">
                      <label class="form-label">Cor no gráfico</label>
                      <div class="input-group">
                        <span class="input-group-text p-1"><span class="d-inline-block rounded js-cor-preview" style="width: 24px; height: 24px; background-color: #3f80ea;"></span></span>
                        <select class="form-control js-cor-select" name="color">
                          <?php foreach ($paleta as $hex => $rotulo) { ?>
                            <option value="<?php echo $hex; ?>"><?php echo $rotulo; ?></option>
                          <?php } ?>
                        </select>
                      </div>
                      <small class="form-text text-muted">Usada na pizza e na barra do card de cancelamentos do Dashboard.</small>
                    </div>
                  </div>
                  <div class="col-xs-12 col-md-6">
                    <div class="form-group mb-3">
                      <label class="form-label">Ordem</label>
                      <input type="number" class="form-control" name="sort_order" min="0" max="999" value="0">
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

<form method="POST" id="form_excluir" action="<?php echo base_url('motivos-cancelamento/post_excluir'); ?>">
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

    // Amostra da cor ao lado do select, para a escolha não ser só pelo nome.
    $(document).on('change', '.js-cor-select', function() {
      $(this).closest('.input-group').find('.js-cor-preview').css('background-color', $(this).val());
    });

    $(document).on('change', '.js-toggle-status', function() {
      var $sw = $(this);
      var $row = $sw.closest('tr');
      var ativar = $sw.is(':checked') ? 1 : 0;

      $sw.prop('disabled', true);
      $.ajax({
        type: 'POST',
        url: '<?php echo base_url('motivos-cancelamento/json_posttoggle_status'); ?>',
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
      var uso = parseInt($(this).data('uso'), 10) || 0;

      // O servidor recusa de qualquer jeito; avisar antes evita o ida-e-volta
      // e explica a saída (inativar).
      if (uso > 0) {
        Swal.fire({
          title: 'Motivo em uso',
          html: 'O motivo <strong>' + name + '</strong> está em <strong>' + uso + '</strong> contrato(s) encerrado(s) e não pode ser excluído.<br><small>Use o botão de status para inativá-lo — ele sai do modal de encerramento e o histórico continua com o rótulo.</small>',
          icon: 'info',
          confirmButtonColor: '#3085d6',
          confirmButtonText: 'Entendi'
        });
        return;
      }

      Swal.fire({
        title: 'Excluir motivo de cancelamento?',
        html: 'O motivo <strong>' + name + '</strong> será excluído <strong>definitivamente</strong>.<br><small>Esta ação não pode ser desfeita.</small>',
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
