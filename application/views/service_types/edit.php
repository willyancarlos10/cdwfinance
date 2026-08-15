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
                <input type="text" class="form-control" name="name" maxlength="150" required="" value="<?php echo $result->name; ?>">
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
              <a href="<?php echo base_url('tipos-servicos'); ?>" class="btn w-100 btn-outline-secondary"><i class="fa fa-arrow-left"></i> VOLTAR</a>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
