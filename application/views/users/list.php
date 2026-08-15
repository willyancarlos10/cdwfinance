<div class="row mb-2 mb-xl-2">
    <div class="col text-start">
        <h3>Usuários</h3>
    </div>
    <div class="col-auto ms-auto text-end mt-n1"></div>
</div>
<div class="card flex-fill">
    <div class="card-body">
        <form method="POST" name="form" action="<?php echo base_url('usuarios/filtrar'); ?>">
            <div class="row">
                <div class="col-md-9">
                    <div class="form-group mb-3">
                        <label for="keyword" class="form-label mb-0">Buscar</label>
                        <div class="form-group mb-3">
                            <input type="text" class="form-control" name="f_users[keyword]" value="<?php echo $this->session->userdata('f_users')['keyword']; ?>" autocomplete="off">
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
        <div class="tab">
            <ul class="nav nav-pills mt-2" role="tab-list">
                <li class="nav-item"><a class="nav-link active" href="#tab1" data-bs-toggle="tab" role="tab" aria-selected="true">Listagem</a></li>
                <li class="nav-item"><a class="nav-link" href="#tab2" data-bs-toggle="tab" role="tab"><i class="fa fa-plus"></i> Novo</a></li>
            </ul>
            <div class="tab-content" style="box-shadow: none; padding: 10px 0 0 0;">
                <div class="tab-pane active" id="tab1" role="tabpanel">
                    <?php if (!empty($results)) { ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th class="text-center">Ações</th>
                                        <th class="text-left">Nome</th>
                                        <th class="text-left">Permissão</th>
                                        <th class="text-center">Telefone</th>
                                        <th class="text-center">Data criação</th>
                                        <th class="text-center">Último acesso</th>
                                        <th class="text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($results as $row) { ?>
                                        <tr>
                                            <td align="center">
                                                <div class="form-check form-switch d-flex justify-content-center m-0">
                                                    <input class="form-check-input switch-user-status" type="checkbox" role="switch" id="switch_usuario_<?php echo $row->id; ?>" data-id="<?php echo $row->id; ?>" aria-label="Ativar ou desativar usuário" <?php echo ($row->id_status == 1) ? 'checked' : ''; ?>>
                                                </div>
                                            </td>
                                            <td align="left"><a href="<?php echo base_url('usuarios/editar?id=' . $row->id . '&source=dashboard'); ?>"><?php echo $row->name; ?></a><br /><small><?php echo $row->email; ?></small></td>
                                            <td align="left"><?php echo $row->permission_name; ?></td>
                                            <td align="center"><?php echo $row->cellphone; ?></td>
                                            <td align="center"><?php echo nice_date($row->created, 'd/m/Y H:i'); ?></td>
                                            <td align="center"><?php echo (!empty($row->last_login)) ? nice_date($row->last_login, 'd/m/Y H:i') : "<span class='text-muted'>Nunca acessou</span>"; ?></td>
                                            <td><span class="badge badge-xs w-100 badge bg-<?php echo $row->status_color; ?>" data-user-status-badge data-base-class="badge badge-xs w-100 badge"><?php echo $row->status_name; ?></span></td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
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
                <div class="tab-pane" id="tab2" role="tabpanel">
                    <form method="POST" action="<?php echo base_url('usuarios/post_usernew'); ?>" name="form" enctype="multipart/form-data">
                        <input type="hidden" name="user[id_company]" value="<?php echo (int) ($this->session->userdata('f_company')['id_company'] ?? $this->session->userdata('company')->id); ?>">
                        <input type="hidden" name="url" value="<?php echo current_url(); ?>">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group mb-3">
                                    <label class="form-label">* Nome completo</label>
                                    <input type="text" class="form-control" name="user[name]" maxlength="150" value="" required="">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group mb-3">
                                    <label class="form-label">* E-mail</label>
                                    <input type="email" class="form-control" name="user[email]" value="">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group mb-3">
                                    <label class="form-label">Celular</label>
                                    <input type="text" class="form-control phonemask" name="user[cellphone]" value="">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group mb-3">
                                    <label class="form-label">Grupo de empresas</label>
                                    <select class="form-control select2" name="user[id_group]" style="width: 100%;">
                                    <option value="0">-- NÃO SE APLICA --</option>
                                        <?php foreach ($crm_user_groups as $c) { ?>
                                            <option value="<?php echo $c->id; ?>"><?php echo $c->name; ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group mb-3">
                                    <label class="form-label">Permissão de acesso</label>
                                    <select class="form-control select2" name="user[id_permission]" style="width: 100%;">
                                        <?php foreach ($crm_user_permissions as $c) { ?>
                                            <option value="<?php echo $c->id; ?>"><?php echo $c->name; ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-12">
                                <hr>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group mb-3">
                                    <label class="form-label"><span class="required" aria-required="true"> * </span> Senha</label>
                                    <input minlength="8" type="password" class="form-control" name="passdf">
                                    <small class="text-muted">(mínimo 8 caracteres com letras e números)</small>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group mb-3">
                                    <label class="form-label"><span class="required" aria-required="true"> * </span> Confirmar senha</label>
                                    <input minlength="8" type="password" class="form-control" name="passdf2">
                                    <small class="text-muted">(mínimo 8 caracteres com letras e números)</small>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-12">
                                <hr>
                            </div>
                            <div class="col">
                                <button type="submit" class="btn w-100 btn-primary"><i class="fa fa-save"></i> SALVAR</button>
                            </div>
                            <div class="col"></div>
                            <div class="col"></div>
                        </div>

                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        function notifyUserStatus(type, message) {
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

        $(document).on('change', '.switch-user-status', function() {
            var $sw = $(this);
            var $row = $sw.closest('tr');
            var id = $sw.data('id');
            var ativar = $sw.is(':checked') ? 1 : 0;

            $sw.prop('disabled', true);
            $.ajax({
                type: 'POST',
                url: '<?php echo base_url('usuarios/json_postchangestatus'); ?>',
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
                        notifyUserStatus('error', (data && data.message) ? data.message : 'Erro ao alterar situação.');
                        return;
                    }

                    if (data.data) {
                        var $badge = $row.find('[data-user-status-badge]');
                        var baseClass = $badge.data('baseClass') || 'badge w-100';
                        $badge.attr('class', baseClass + ' bg-' + data.data.status_color);
                        $badge.text(data.data.status_name);
                    }

                    notifyUserStatus('success', data.message || 'Situação alterada com sucesso.');
                },
                error: function(xhr) {
                    $sw.prop('checked', !ativar);
                    console.log(xhr.responseText);
                    notifyUserStatus('error', 'Erro ao alterar situação.');
                },
                complete: function() {
                    $sw.prop('disabled', false);
                }
            });
        });
    });
</script>