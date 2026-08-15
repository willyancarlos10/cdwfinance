<div class="row mb-2 mb-xl-2">
    <div class="col-auto text-start">
        <h1 class="h3 mb-3">Empresas</h1>
    </div>
    <div class="col-auto ms-auto text-end mt-n1">
        <a target="_blank" class="btn btn-primary" href="<?php echo base_url('login/cadastro?partner=true'); ?>"><i class="fa fa-external-link-alt"></i> NOVA</a>
    </div>
</div>
<div class="card flex-fill">
    <div class="card-body py-3">
        <form method="POST" name="form" action="<?php echo base_url('empresas/post_filtrar'); ?>" enctype="multipart/form-data">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label class="form-label">Buscar</label>
                        <input type="text" placeholder="Digite sua busca: razão social, nome fantasia, cnpj..." class="form-control" name="f_companies[keyword]" value="<?php echo $this->session->userdata('f_companies')['keyword']; ?>" autocomplete="off">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group mb-3">
                        <label class="form-label">Status</label>
                        <select name="f_companies[id_status]" class="form-control select2">
                            <option value="">-- MOSTRAR TUDO --</option>
                            <?php foreach ($crm_status as $c) { ?>
                                <option <?php if ($c->id == $this->session->userdata('f_companies')['id_status']) echo 'selected=""'; ?> value="<?php echo $c->id ?>"><?php echo $c->name; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;&nbsp;&nbsp;&nbsp;</label><Br />
                    <button type="submit" class="btn w-100 btn-primary btn-md"><i class="fa fa-filter"></i> FILTRAR</button>
                </div>
            </div>
        </form>
        <div class="row">
            <div class="col-12">
                <?php if (!empty($results)) { ?>
                    <hr>
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th class="text-center">Ações</th>
                                    <th class="text-center">ID</th>
                                    <th class="text-center">Nome</th>
                                    <th class="text-center">Responsável</th>
                                    <th class="text-center">Telefone</th>
                                    <th class="text-center">Último login</th>
                                    <th class="text-center">Localização</th>
                                    <th class="text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $row) { ?>
                                    <tr>
                                        <td align="center">
                                            <div class="form-check form-switch d-flex justify-content-center m-0">
                                                <input class="form-check-input switch-empresa-status" type="checkbox" role="switch" id="switch_empresa_<?php echo $row->id; ?>" data-id="<?php echo $row->id; ?>" <?php echo ($row->id_status == 1) ? 'checked' : ''; ?>>
                                            </div>
                                        </td>
                                        <td align="center"><?php echo $row->id; ?></td>
                                        <td align="left"><a href="<?php echo base_url('empresas/info?id=' . $row->id); ?>"><?php echo $row->name; ?></a><br /><small><?php echo $row->byname; ?></small></td>
                                        <td align="left"><?php echo $row->owner; ?></td>
                                        <td align="center"><?php echo $row->phone; ?></td>
                                        <td align="center"><?php echo (!empty($row->last_login)) ? date("d/m/Y H:i", strtotime($row->last_login)) : "-"; ?></td>
                                        <td align="center"><?php echo $row->city_name . '/' . $row->state_uf; ?></td>
                                        <td><span class="badge badge-xs w-100 badge bg-<?php echo $row->status_color; ?>" data-status-badge><?php echo $row->status_name; ?></span></td>
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
                                    <p class="mb-0">
                                        Total: <strong><?php echo numero($est_count); ?></strong> registros.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php } else { ?>
                    <div class="alert alert-secondary alert-dismissible" role="alert">
                        <button type="button" class="btn-close d-none" data-bs-dismiss="alert" aria-label="Close"></button>
                        <div class="alert-message">
                            Nenhum registro encontrado.
                        </div>
                    </div>
                <?php } ?>
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

        $(document).on('change', '.switch-empresa-status', function() {
            var $sw = $(this);
            var $row = $sw.closest('tr');
            var id = $sw.data('id');
            var ativar = $sw.is(':checked') ? 1 : 0;

            $sw.prop('disabled', true);

            $.ajax({
                type: 'POST',
                url: '<?php echo base_url('empresas/json_postchangestatus'); ?>',
                data: {
                    id: id,
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
                        $row.find('[data-status-badge]').attr('class', 'badge badge-xs w-100 badge bg-' + data.data.status_color).text(data.data.status_name);
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
