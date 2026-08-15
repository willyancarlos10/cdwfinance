<div class="row mb-2 mb-xl-2">
  <div class="col text-start">
    <h3>Editando: <?php echo $result->name; ?></h3>
  </div>
  <div class="col-auto ms-auto text-end mt-n1">
    <a href="javascript: window.history.go(-1);" class="btn btn-outline-secondary"><i class="fa fa-arrow-left"></i> VOLTAR</a>
  </div>
</div>
<div class="card flex-fill">
  <div class="card-body">

    <form method="POST" action="<?php echo current_url(); ?>" name="form" enctype="multipart/form-data">
      <input type="hidden" name="id" value="<?php echo $result->id; ?>">
      <div class="row">
        <div class="col-12 col-md-8">
          <div class="row">
            <div class="col-12 col-md-4">
              <div class="form-group mb-3">
                <label class="form-label"><span class="required" aria-required="true"> * </span> Nome</label>
                <input type="text" class="form-control js-origin-lock-readonly" name="name" required="" value="<?php echo $result->name; ?>">
              </div>
            </div>
            <div class="col-12">
              <div class="tab">
                <ul class="nav nav-pills mt-2" role="tab-list">
                  <li class="nav-item"><a class="nav-link active" href="#tab1" data-bs-toggle="tab" role="tab" aria-selected="true">Identificadores</a></li>
                  <li class="nav-item"><a class="nav-link" href="#tab2" data-bs-toggle="tab" role="tab">Histórico <?php if (!empty($history)) { ?><span class="badge bg-warning"><?php echo count($history); ?></span><?php } ?></a></li>
                </ul>
                <div class="tab-content" style="box-shadow: none; padding: 10px 0 0 0;">
                  <div class="tab-pane active" id="tab1" role="tabpanel">
                    <div class="table-responsive">
                      <table class="table table-striped table-bordered table-hover mt-3">
                        <thead>
                          <tr>
                            <th class="text-center" colspan="2">Permissão</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php $active = json_decode($result->permissions);
                          if (empty($active)) $active = array(); ?>

                          <?php foreach ($crm_permissions_items as $c) { ?>
                            <tr>
                              <td colspan="2">
                                <div class="form-check form-switch mb-2">
                                  <input class="form-check-input js-origin-lock-disable" id="<?php echo 'id' . $c->id; ?>" type="checkbox" <?php if (in_array($c->id, $active)) {
                                                                                                                                              echo 'checked=""';
                                                                                                                                            } ?> name="permissions[]" value="<?php echo $c->id; ?>">
                                  <label for="<?php echo 'id' . $c->id; ?>" class="form-check-label" for="flexSwitchCheckChecked"><?php echo $c->id . ' - ' . $c->name; ?></label>
                                </div>
                              </td>

                            </tr>
                          <?php } ?>




                        </tbody>
                      </table>
                    </div>
                  </div>
                  <div class="tab-pane" id="tab2" role="tabpanel">
                    <?php if (!empty($history)) { ?>
                      <ul class="timeline mt-2 mb-0">
                        <?php foreach ($history as $row) { ?>
                          <li class="timeline-item">
                            <strong><?php echo $row->name . ' - ' . $row->company_byname; ?> </strong><small>Em <?php echo nice_date($row->created, 'd/m/Y H:i'); ?></small><br />
                            <p class="mb-2"><?php echo $row->description; ?></p>
                          </li>
                        <?php } ?>
                      </ul>
                    <?php } else { ?>
                      <div class="alert alert-default">Nenhum registro encontrado.</div>
                    <?php } ?>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-12 col-sm-4">
          <h4 class="text-center">USUÁRIOS ATIVOS VINCULADOS</h4>
          <?php if (!empty($users)) { ?>
            <div class="table-responsive">
              <table class="table table-striped table-bordered table-hover">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Nome</th>
                    <th>Situação</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($users as $c) { ?>
                    <tr>
                      <td><?php echo $c->id; ?></td>
                      <td><a href="<?php echo base_url('usuarios/editar/' . $c->id); ?>"><?php echo $c->name; ?></a></td>
                      <td><span class="badge bg-<?php echo $c->status_color; ?>"><?php echo $c->status_name; ?></span></td>
                    </tr>
                  <?php } ?>
                </tbody>
              </table>
            </div>
          <?php } else { ?>
            <div class="alert alert-secondary alert-dismissible text-center" role="alert">
              <button type="button" class="btn-close d-none" data-bs-dismiss="alert" aria-label="Close"></button>
              <div class="alert-message">Nenhum usuário vinculado.</div>
            </div>
          <?php } ?>
        </div>
      </div>
      <div class="row">
        <div class="col-xs-12 col-md-12">
          <hr>
        </div>
      </div>
      <div class="row">
        <div class="col-12 col-sm-4">
          <button class="btn btn-primary w-100" type="submit"><i class="fa fa-save"></i> SALVAR</button>
        </div>
        <div class="col-12 col-sm-4"></div>
        <div class="col-12 col-sm-4">
        </div>
      </div>
    </form>

  </div>
</div>