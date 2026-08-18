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
              <div class="form-group mb-3">
                <label class="form-label">Monitoramento de sites</label>
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" role="switch" id="monitor_site" name="monitor_site" value="1" <?php if (!empty($result->monitor_site)) echo 'checked=""'; ?>>
                  <label class="form-check-label" for="monitor_site">Contratos deste tipo têm site</label>
                </div>
                <small class="form-text text-muted">
                  Só os domínios de contrato vigente com pelo menos um tipo marcado aqui entram na rotina diária
                  que checa DNS e página inicial. Deixe desmarcado em contratos que não têm site — e-mail e
                  gerenciamento de domínio —, senão eles apareceriam como "site fora do ar" todos os dias.
                </small>
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
