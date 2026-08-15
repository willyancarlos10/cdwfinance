<?php
defined('BASEPATH') or exit('No direct script access allowed');

$escape = function ($value) {
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>

<div class="row">
  <div class="col-md-4 col-xl-3">
    <div class="card mb-3">
      <div class="card-body text-center">
        <a class="btn btn-secondary btn-sm mb-2" href="<?php echo base_url('empresas/info?id=' . (int) $company->id); ?>">VOLTAR À EMPRESA</a>
        <div class="small text-muted">Empresa</div>
        <h4 class="mb-1"><?php echo $escape($company->byname); ?></h4>
        <div class="text-muted small"><?php echo $escape($company->name); ?></div>
      </div>
      <hr class="my-0">
      <ul class="list-group list-group-flush">
        <li class="list-group-item"><small>Chaves cadastradas</small><br><strong><?php echo count($api_keys); ?></strong></li>
        <li class="list-group-item"><small>Autenticação</small><br><code>Bearer</code></li>
      </ul>
    </div>
  </div>

  <div class="col-md-8 col-xl-9">
    <div class="card mb-3">
      <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
          <h5 class="card-title mb-1">Chaves de API</h5>
          <div class="text-muted small">Credenciais de integração vinculadas exclusivamente a esta empresa.</div>
        </div>
        <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#modal_create_api_key">
          <i class="mdi mdi-key-plus"></i> NOVA CHAVE
        </button>
      </div>
      <div class="card-body">
        <div class="alert alert-info p-3">
          As chaves não expiram, mas podem ser revogadas a qualquer momento. A chave completa é exibida somente após a criação.</a>.
        </div>

        <?php if (!empty($api_key_plaintext)) { ?>
          <div class="alert alert-warning p-2">
            <div class="form-group w-100">
              <label class="form-label mt-2" for="api_key_plaintext">Chave de API</label>
              <div class="input-group">
                <input id="api_key_plaintext" class="form-control font-monospace" type="text" readonly value="<?php echo $escape($api_key_plaintext); ?>">
                <button id="copy_api_key" class="btn btn-outline-secondary" type="button">COPIAR</button>
              </div>
            </div>
          </div>
        <?php } ?>

        <?php if (empty($api_keys)) { ?>
          <div class="alert alert-secondary mb-0 p-3">Nenhuma chave de API cadastrada para esta empresa.</div>
        <?php } else { ?>
          <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
              <thead>
                <tr>
                  <th class="text-center">Ações</th>
                  <th>Nome</th>
                  <th>Identificador</th>
                  <th>Escopos</th>
                  <th>Criada em</th>
                  <th>Último uso</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($api_keys as $api_key) { ?>
                  <?php $scopes = json_decode($api_key->scopes, TRUE); ?>
                  <tr>
                    <td class="text-center">
                      <?php if (empty($api_key->revoked_at)) { ?>
                        <form method="POST" action="<?php echo base_url('empresas/chaves-api/revogar'); ?>" class="d-inline js-revoke-api-key">
                          <input type="hidden" name="id_company" value="<?php echo (int) $company->id; ?>">
                          <input type="hidden" name="id_key" value="<?php echo (int) $api_key->id; ?>">
                          <button class="btn btn-sm btn-outline-danger" type="submit" title="Revogar chave"><i class="fa fa-ban"></i></button>
                        </form>
                      <?php } else { ?>
                        <span class="text-muted">—</span>
                      <?php } ?>
                    </td>
                    <td><?php echo $escape($api_key->name); ?></td>
                    <td><code><?php echo $escape($api_key->key_prefix); ?></code></td>
                    <td>
                      <?php foreach ((array) $scopes as $scope) { ?>
                        <span class="badge bg-secondary"><?php echo $escape($scope); ?></span>
                      <?php } ?>
                    </td>
                    <td><?php echo date('d/m/Y H:i', strtotime($api_key->created)); ?></td>
                    <td><?php echo !empty($api_key->last_used_at) ? date('d/m/Y H:i', strtotime($api_key->last_used_at)) : 'Nunca utilizada'; ?></td>
                    <td>
                      <?php if (empty($api_key->revoked_at)) { ?>
                        <span class="badge bg-success">ATIVA</span>
                      <?php } else { ?>
                        <span class="badge bg-danger">REVOGADA</span>
                        <div class="small text-muted mt-1"><?php echo date('d/m/Y H:i', strtotime($api_key->revoked_at)); ?></div>
                      <?php } ?>
                    </td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
        <?php } ?>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modal_create_api_key" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" action="<?php echo base_url('empresas/chaves-api/criar'); ?>" class="modal-content" autocomplete="off">
      <input type="hidden" name="id_company" value="<?php echo (int) $company->id; ?>">
      <div class="modal-header">
        <h5 class="modal-title">Nova chave de API</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label" for="api_key_name">Nome da integração</label>
          <input id="api_key_name" name="api_key[name]" class="form-control" maxlength="100" required placeholder="Ex.: Site institucional">
          <div class="form-text">Use um nome que permita identificar onde esta chave está sendo utilizada.</div>
        </div>
        <div>
          <div class="d-flex align-items-center justify-content-between mb-1">
            <label class="form-label mb-0">Permissões</label>
            <a href="#" id="toggle_all_scopes" class="small text-decoration-none" role="button">Selecionar todos</a>
          </div>
          <?php if (empty($api_key_scopes)) { ?>
            <div class="alert alert-warning mb-0" role="alert">
              <div class="alert-message">
                Nenhuma permissão disponível: a API v1 ainda não expõe endpoints.
                Cadastre os escopos no controller <code>Empresas</code> assim que o
                primeiro endpoint do financeiro for publicado.
              </div>
            </div>
          <?php } else { ?>
            <?php foreach ($api_key_scopes as $scope => $label) { ?>
              <div class="form-check">
                <input class="form-check-input js-scope-checkbox" type="checkbox" name="api_key[scopes][]" value="<?php echo $escape($scope); ?>" id="scope_<?php echo $escape(str_replace(':', '_', $scope)); ?>">
                <label class="form-check-label" for="scope_<?php echo $escape(str_replace(':', '_', $scope)); ?>"><?php echo $escape($label); ?> <code><?php echo $escape($scope); ?></code></label>
              </div>
            <?php } ?>
          <?php } ?>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">CANCELAR</button>
        <button class="btn btn-primary" type="submit"><i class="mdi mdi-key-plus"></i> GERAR CHAVE</button>
      </div>
    </form>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    var copyButton = document.getElementById('copy_api_key');
    var apiKeyInput = document.getElementById('api_key_plaintext');

    if (copyButton && apiKeyInput && navigator.clipboard) {
      copyButton.addEventListener('click', function() {
        navigator.clipboard.writeText(apiKeyInput.value).then(function() {
          copyButton.textContent = 'COPIADA';
        });
      });
    }

    document.querySelectorAll('.js-revoke-api-key').forEach(function(form) {
      form.addEventListener('submit', function(event) {
        if (!window.confirm('Revogar esta chave? A integração que a utiliza perderá o acesso imediatamente.')) {
          event.preventDefault();
        }
      });
    });

    var toggleAllScopes = document.getElementById('toggle_all_scopes');
    var scopeCheckboxes = document.querySelectorAll('.js-scope-checkbox');

    if (toggleAllScopes && scopeCheckboxes.length) {
      var refreshToggleLabel = function() {
        var allChecked = Array.prototype.every.call(scopeCheckboxes, function(checkbox) {
          return checkbox.checked;
        });
        toggleAllScopes.textContent = allChecked ? 'Desmarcar todos' : 'Selecionar todos';
      };

      toggleAllScopes.addEventListener('click', function(event) {
        event.preventDefault();
        var allChecked = Array.prototype.every.call(scopeCheckboxes, function(checkbox) {
          return checkbox.checked;
        });
        scopeCheckboxes.forEach(function(checkbox) {
          checkbox.checked = !allChecked;
        });
        refreshToggleLabel();
      });

      scopeCheckboxes.forEach(function(checkbox) {
        checkbox.addEventListener('change', refreshToggleLabel);
      });

      refreshToggleLabel();
    }
  });
</script>