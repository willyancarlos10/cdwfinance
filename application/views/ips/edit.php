<?php
/**
 * Edição de um IP contratado.
 *
 * Posta no MESMO `post_salvar` da criação — a presença do `id` é o que distingue as
 * duas, como em Contratos::post_salvardominio. Uma segunda rotina de gravação seria
 * livre para divergir da primeira nas validações.
 */
?>
<div class="row mb-2 mb-xl-2">
  <div class="col text-start">
    <h3>Editando: <?php echo htmlspecialchars($result->ip, ENT_QUOTES, 'UTF-8'); ?></h3>
    <p class="text-muted mb-2">
      <small>
        <?php if (!empty($result->id_customer)) { ?>
          Alocado para
          <a href="<?php echo base_url('clientes/info?id=' . (int) $result->id_customer); ?>"><?php echo htmlspecialchars((string) $result->customer_name, ENT_QUOTES, 'UTF-8'); ?></a>.
          Para devolvê-lo ao estoque, escolha <strong>SEM VÍNCULO</strong>.
        <?php } else { ?>
          Este IP está <strong>disponível</strong>. Vincular um cliente é o que o marca como alocado.
        <?php } ?>
      </small>
    </p>
  </div>
  <div class="col-auto ms-auto text-end mt-n1">
    <a class="btn btn-outline-secondary" href="<?php echo base_url('ips-contratados'); ?>"><i class="fa fa-arrow-left"></i> VOLTAR</a>
  </div>
</div>

<div class="card flex-fill">
  <div class="card-body">
    <div class="row">
      <div class="col-12 col-sm-10">
        <form method="POST" action="<?php echo base_url('ips-contratados/post_salvar'); ?>" name="form">
          <input type="hidden" name="id" value="<?php echo (int) $result->id; ?>" />
          <div class="row">
            <div class="col-xs-12 col-md-4">
              <div class="form-group mb-3">
                <label class="form-label"><span class="required" aria-required="true"> * </span> IP</label>
                <input type="text" class="form-control" name="ip" maxlength="15" required="" autocomplete="off" value="<?php echo htmlspecialchars($result->ip, ENT_QUOTES, 'UTF-8'); ?>">
                <small class="form-text text-muted">Apenas IPv4. Não pode repetir dentro desta empresa.</small>
              </div>
            </div>
            <div class="col-xs-12 col-md-8">
              <div class="form-group mb-3">
                <label class="form-label">Cliente</label>
                <select name="id_customer" class="form-control select2" style="width: 100%">
                  <option value="0">-- SEM VÍNCULO (IP DISPONÍVEL) --</option>
                  <?php foreach ($customers as $cliente) { ?>
                    <option value="<?php echo (int) $cliente->id; ?>" <?php if ((int) $result->id_customer === (int) $cliente->id) echo 'selected=""'; ?>>
                      <?php echo htmlspecialchars($cliente->name, ENT_QUOTES, 'UTF-8'); ?><?php echo !empty($cliente->document) ? ' — ' . cnpj($cliente->document) : ''; ?>
                    </option>
                  <?php } ?>
                </select>
                <small class="form-text text-muted">Trocar ou remover o vínculo muda a situação do IP na hora — não há campo de situação a acertar depois.</small>
              </div>
            </div>
          </div>
          <div class="row">
            <div class="col-xs-12 col-md-12">
              <div class="form-group mb-3">
                <label class="form-label">Observações</label>
                <textarea class="form-control" name="comments" rows="2" maxlength="500"><?php echo htmlspecialchars((string) $result->comments, ENT_QUOTES, 'UTF-8'); ?></textarea>
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
