<div class="row mb-2 mb-xl-2">
  <div class="col text-start">
    <h3>Editando: <?php echo $result->name ?></h3>
  </div>
</div>
<div class="card flex-fill">
  <div class="card-body">
    <div class="row">
      <div class="col-12 col-sm-8">
        <form method="POST" action="<?php echo current_url(); ?>" name="form" enctype="multipart/form-data">
          <input type="hidden" name="id" value="<?php echo $result->id; ?>" />
          <div class="row">
            <div class="col-xs-12 col-md-12">
              <div class="form-group mb-3">
                <label class="form-label"><span class="required" aria-required="true"> * </span> Nome</label>
                <input type="text" class="form-control" name="name" required="" value="<?php echo $result->name; ?>">
              </div>
            </div>
            <div class="col-xs-12 col-md-12">
              <div class="form-group mb-3">
                <label class="form-label">Selecionar empresas</label>
                <select class="form-control select2 select2-multiple" multiple name="companies[]">
                  <?php $active = json_decode($result->companies);
                  if (empty($active)) $active = array(); ?>
                  <?php foreach ($crm_companies_v as $c) { ?>
                    <option <?php if (in_array($c->id, $active)) echo 'selected=""'; ?> value="<?php echo $c->id; ?>"><?php echo $c->byname . ' - ' . $c->cnpj; ?></option>
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
            <div class="col">
              <button class="btn w-100 btn-primary" type="submit"><i class="fa fa-save"></i> SALVAR</button>
            </div>
            <div class="col"></div>
            <div class="col">
              <a href="javascript: window.history.go(-1);" class="btn w-100 btn-outline-secondary"><i class="fa fa-arrow-left"></i> VOLTAR</a>
            </div>
          </div>
        </form>
      </div>
      <div class="col-12 col-sm-4">
        <h4>USUÁRIOS VINCULADOS</h4>
        <?php if (!empty($crm_users_v)) { ?>
          <div class="table-responsive">
            <table class="table table-striped table-hover">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Nome</th>
                  <th>Situação</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($crm_users_v as $c) { ?>
                  <tr>
                    <td><?php echo $c->id; ?></td>
                    <td><a href="<?php echo base_url('usuarios/editar/' . $c->id); ?>"><?php echo $c->name; ?></a><br><small class="text-muted"><?php echo $c->company_name; ?></small></td>
                    <td><span class="badge bg-<?php echo $c->status_color; ?>"><?php echo $c->status_name; ?></span></td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
        <?php } else { ?>
          <div class="alert alert-default">Nenhum registro encontrado.</div>
        <?php } ?>
      </div>
    </div>
  </div>
</div>