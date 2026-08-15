<div class="row mb-2 mb-xl-2">
    <div class="col-auto text-start">
        <h1 class="h3 mb-3"><?php echo $result->name; ?></h1>
    </div>
    <div class="col-auto ms-auto text-end mt-n1">
        <a class="btn btn-outline-secondary" href="<?php echo base_url('empresas/info?id=' . $result->id); ?>"><i class="fa fa-arrow-left"></i> VOLTAR</a>
    </div>
</div>
<div class="card flex-fill">
    <div class="card-body py-3">
        <form method="POST" action="<?php echo current_url(); ?>" name="form" enctype="multipart/form-data">
            <input type="hidden" name="id" value="<?php echo $result->id; ?>">
            <div class="row">
                <div class="col-md-6">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label class="form-label">* CNPJ</label>
                                <div class="input-group">
                                    <input type="text" id="cnpj" class="form-control" data-mask="00.000.000/0000-00" name="company[cnpj]" value="<?php echo set_value('company[cnpj]', $result->cnpj); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label class="form-label">* Razão social</label>
                                <input type="text" id="name" class="form-control" name="company[name]" required="" value="<?php echo set_value('company[name]', $result->name); ?>" maxlength="100">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label class="form-label">* Nome fantasia</label>
                                <input type="text" id="byname" class="form-control" name="company[byname]" required="" value="<?php echo set_value('company[byname]', $result->byname); ?>" maxlength="50">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label class="form-label">Telefone fixo</label>
                                <input type="text" class="form-control phonemask" name="company[phone]" minlength="10" value="<?php echo set_value('company[phone]', $result->phone); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label class="form-label">* Contato nome</label>
                                <input type="text" class="form-control" required="" name="company[owner]" value="<?php echo set_value('company[owner]', $result->owner); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label class="form-label">* Contato celular</label>
                                <input type="text" class="form-control phonemask" required="" name="company[owner_cellphone]" value="<?php echo set_value('company[owner_cellphone]', $result->owner_cellphone); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label class="form-label">Contato e-mail</label>
                                <input type="text" class="form-control" name="company[email]" value="<?php echo set_value('company[email]', $result->email); ?>">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group mb-3">
                                <label class="form-label">* CEP</label>
                                <input type="text" required="" class="form-control" data-mask="00.000-000" name="company[address_zip]" value="<?php echo set_value('company[address_zip]', $result->address_zip); ?>">
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="form-group mb-3">
                                <label class="form-label">* Endereço:</label>
                                <input type="text" required="" class="form-control" name="company[address]" value="<?php echo set_value('company[address]', $result->address); ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label class="form-label">* Número:</label>
                                <input type="text" required="" class="form-control" name="company[address_number]" value="<?php echo set_value('company[address_number]', $result->address_number); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label class="form-label">* Bairro:</label>
                                <input type="text" required="" class="form-control" name="company[address_district]" value="<?php echo set_value('company[address_district]', $result->address_district); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label class="form-label">Complemento:</label>
                                <input type="text" class="form-control" name="company[address_complement]" value="<?php echo set_value('company[address_complement]', $result->address_complement); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label class="form-label">* Estado</label>
                                <select class="form-control select2" name="company[id_state]" id="id_state" required="">
                                    <?php foreach ($states as $row) { ?>
                                        <option <?php if ($row->id == $result->id_state) echo 'selected=""'; ?> value="<?php echo $row->id; ?>"><?php echo $row->name; ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label class="form-label">* Cidade</label>
                                <select class="form-control select2" name="company[id_city]" id="id_city" required="">
                                    <?php foreach ($cities as $row) { ?>
                                        <option <?php if ($row->id == $result->id_city) echo 'selected=""'; ?> value="<?php echo $row->id; ?>"><?php echo $row->name; ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <hr>
                </div>
                <div class="col-md-9">
                    <div class="form-group mb-3">
                        <label class="form-label">Grupo de empresas</label>
                        <select class="form-control select2 select2-multiple" multiple name="companies[]" style="width: 100%;" data-placeholder="Selecione as empresas do grupo">
                            <?php foreach ($companies as $company) { ?>
                                <option value="<?php echo (int) $company->id; ?>" <?php echo in_array((int) $company->id, $related_company_ids, TRUE) ? 'selected=""' : ''; ?>><?php echo htmlspecialchars($company->byname, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group mb-3">
                        <label class="form-label">* Status</label>
                        <select class="form-control select2" name="company[id_status]" required="">
                            <?php foreach ($status as $c) { ?>
                                <option <?php if ($c->id == $result->id_status) echo 'selected=""' ?> value="<?php echo $c->id; ?>"><?php echo $c->name; ?></option>
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
                <div class="col"><button type="submit" class="btn w-100 btn-primary"><i class="fa fa-save"></i> SALVAR</button></div>
                <div class="col"></div>
                <div class="col"></div>
            </div>
        </form>
    </div>
</div>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        $('#id_state').on("change", function(e) {
            var id_state = $('#id_state').val();
            $.ajax({
                type: "POST",
                url: "<?php echo base_url(); ?>empresas/json_get_cities_by_id/" + id_state,
                success: function(data) {
                    if (data['redirect']) {
                        window.location.replace("<?php echo base_url('painel/sair_custom'); ?>");
                        return false;
                    }

                    $('#id_city').empty();
                    $.each(data, function(index, element) {
                        var opt = $('<option />');
                        opt.val(element.id);
                        opt.text(element.name);
                        $('#id_city').append(opt);
                    });
                }
            });
        });
    });
</script>
