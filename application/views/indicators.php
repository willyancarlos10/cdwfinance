<?php $tabs_default = 'tabs1'; ?>
<div class="row mb-2 mb-xl-2">
    <div class="col text-start">
        <h1 class="h3 mb-0">Indicador CRON</h1>
        <small>Versãdo do migration: <?php echo $migrations->version; ?></small>
    </div>
</div>
<div class="row">
    <div class="col-12">
        <div class="card w-100">
            <div class="card-body pt-0">
                <ul class="nav nav-pills mt-2">
                    <li class="nav-item"><a class="nav-link tabs1 <?php if ($tabs_default == 'tabs1') echo 'active'; ?>" href="#tabs1" data-bs-toggle="tab" role="tab" aria-selected="true">Crontab</a></li>
                    <li class="nav-item"><a class="nav-link tabs2 <?php if ($tabs_default == 'tabs2') echo 'active'; ?>" href="#tabs2" data-bs-toggle="tab" role="tab" aria-selected="true">Fila de e-mails <?php if (count($crm_cron_email) > 0) echo '<i class="mdi mdi-alert-circle text-warning"></i> (' . count($crm_cron_email) . ')'; ?></a></li>
                </ul>
                <div class="tab-content p-0" style="box-shadow: none;">
                    <div class="tab-pane <?php if ($tabs_default == 'tabs1') echo 'active'; ?>" id="tabs1" role="tabpanel">
                        <?php if ($cron_token === '') { ?>
                            <div class="alert alert-warning mt-3 mb-0" role="alert">
                                <i class="mdi mdi-alert-circle"></i>
                                Botão EXECUTAR indisponível: defina <code>$config['cron_token']</code> na <code>config.php</code> deste ambiente.
                                As rotinas disparadas por URL (inclusive pelo crontab) respondem <strong>403</strong> enquanto o token não existir.
                            </div>
                        <?php } ?>
                        <div class="table-responsive mt-3">
                            <table class="table table-striped table-bordered table-hover">
                                <tr>
                                    <td align="center">Ações</td>
                                    <td align="center">Ativo</td>
                                    <td>Nome</td>
                                    <td>Última exec.</td>
                                    <td>Erros</td>
                                </tr>
                                <?php foreach ($crm_cron_logs as $row) { ?>
                                    <tr>
                                        <td align="center">
                                            <?php if ($cron_token !== '') { ?>
                                                <a target="_blank" rel="noopener noreferrer" href="<?php echo base_url('cron/' . rawurlencode($row->name)) . '?token=' . rawurlencode($cron_token); ?>" class="btn btn-sm btn-outline-warning" title="Executar agora"><i class="fa fa-play"></i></a>
                                            <?php } else { ?>
                                                <span class="btn btn-sm btn-outline-secondary disabled" title="Defina $config['cron_token'] na config.php deste ambiente."><i class="fa fa-play"></i></span>
                                            <?php } ?>
                                        </td>
                                        <td align="center">
                                            <div class="form-check form-switch d-flex justify-content-center mb-0">
                                                <input class="form-check-input js-toggle-cron-active" type="checkbox" role="switch" data-id="<?php echo (int) $row->id; ?>" aria-label="Ativar ou desativar <?php echo htmlspecialchars($row->name, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($row->active === 'S') ? 'checked' : ''; ?>>
                                            </div>
                                        </td>
                                        <td><?php echo $row->name; ?></td>
                                        <td><?php echo (!empty($row->modified)) ? nice_date($row->modified, 'd/m/Y H:i') : ""; ?></td>
                                        <td><?php echo $row->errors; ?></td>
                                    </tr>
                                <?php } ?>
                            </table>
                        </div>
                    </div>
                    <div class="tab-pane <?php if ($tabs_default == 'tabs2') echo 'active'; ?>" id="tabs2" role="tabpanel">
                        <?php if (!empty($crm_cron_email)) { ?>
                            <div class="table-responsive mt-3">
                                <table class="table table-striped table-bordered table-hover">
                                    <tr>
                                        <td align="center">Ações</td>
                                        <td>ID</td>
                                        <td>Data</td>
                                        <td>Titulo</td>
                                        <td>E-mails</td>
                                    </tr>
                                    <?php foreach ($crm_cron_email as $row) {
                                        $title = "";
                                        $mails = [];
                                        $active = json_decode($row->parameters);
                                        if (!empty($active)) {
                                            $title = $active->title;
                                            $mails = (array) $active->to;
                                        }
                                    ?>
                                        <tr>
                                            <td align="center"><button data-value="<?php echo $row->id; ?>" data-bs-toggle="modal" data-bs-target="#modal_email" class="btn btn-sm btn-outline-primary ver_email" title="Ver e-mail"><i class="fa fa-eye"></i></button></td>
                                            <td align="center"><?php echo $row->id; ?></td>
                                            <td align="center"><?php echo nice_date($row->created, 'd/m/Y H:i:s'); ?></td>
                                            <td><?php echo $title; ?></td>
                                            <td>
                                                <?php
                                                foreach ($mails as $mail) {
                                                    echo "$mail <br>";
                                                }  ?>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                </table>
                            </div>
                        <?php } else { ?>
                            <div class="alert alert-secondary alert-dismissible mt-3" role="alert">
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
    </div>
</div>

<div class="modal fade" id="modal_email" tabindex="-1" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mail_title">...</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body m-1" style="min-height:0;">
                <div class="row">
                    <div class="col-12">
                        <div id="mail_body">...</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <div class="row w-100">
                    <div class="col"></div>
                    <div class="col"></div>
                    <div class="col">
                        <button type="button" class="btn w-100 btn-outline-secondary" data-bs-dismiss="modal"><i class="fa fa-times"></i> FECHAR</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        function notifyCronStatus(type, message) {
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

        $(document).on('change', '.js-toggle-cron-active', function() {
            var $sw = $(this);
            var ativar = $sw.is(':checked') ? 1 : 0;

            $sw.prop('disabled', true);
            $.ajax({
                type: 'POST',
                url: '<?php echo base_url('painel/json_posttoggle_cron_active'); ?>',
                data: {
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
                        notifyCronStatus('error', (data && data.message) ? data.message : 'Erro ao alterar status.');
                        return;
                    }
                    notifyCronStatus('success', data.message || 'Status alterado com sucesso.');
                },
                error: function(xhr) {
                    $sw.prop('checked', !ativar);
                    console.log(xhr.responseText);
                    notifyCronStatus('error', 'Erro ao alterar status.');
                },
                complete: function() {
                    $sw.prop('disabled', false);
                }
            });
        });

        $(".ver_email").click(function() {
            // Checar o preenchimento do campo PLACA
            var id = $(this).attr("data-value");
            setTimeout(function() {
                // Ajax
                $.ajax({
                    type: "POST",
                    data: {
                        id: id
                    },
                    dataType: 'json',
                    url: "<?php echo base_url('painel/json_get_mail_body'); ?>",
                    success: function(data) {
                        if (data['redirect']) {
                            window.location.replace("<?php echo base_url('painel/sair_custom'); ?>");
                            return false;
                        }

                        if (data.return) {
                            $('#mail_title').text(data.title);
                            $('#mail_body').html(data.body);
                        } else {
                            $('#mail_title').text("E-mail já enviado.");
                            $('#mail_body').html("E-mail já enviado.");
                        }
                    },
                    error: function(data) {
                        console.log(data.responseText);
                    }
                }).done(function() {});
            }, 120);
        });
    });
</script>