<?php
$edicao = !empty($result);
$tipoAtual = $edicao ? $result->type : set_value('server[type]', 'whm');
$tipoAtual = set_value('server[type]', $tipoAtual);
$authAtual = $edicao && !empty($result->auth_type) ? $result->auth_type : 'password';
$authAtual = set_value('server[auth_type]', $authAtual);
$verificaSsl = $edicao ? !empty($result->verify_ssl) : TRUE;
if ($this->input->method() === 'post') {
  $verificaSsl = (bool) $this->input->post('server[verify_ssl]');
}
?>
<div class="row mb-2 mb-xl-2">
  <div class="col-auto text-start">
    <h1 class="h3 mb-3"><?php echo $edicao ? 'Editar servidor' : 'Novo servidor'; ?></h1>
    <?php if ($edicao) { ?>
      <small class="text-muted"><?php echo htmlspecialchars($result->name, ENT_QUOTES, 'UTF-8'); ?></small>
    <?php } ?>
  </div>
  <div class="col-auto ms-auto text-end mt-n1">
    <a class="btn btn-outline-secondary" href="<?php echo base_url('servidores'); ?>"><i class="fa fa-arrow-left"></i> VOLTAR</a>
  </div>
</div>

<?php if (empty($crypto_ready)) { ?>
  <div class="alert alert-danger" role="alert">
    <div class="alert-message">
      <strong>Criptografia não configurada.</strong> Defina <code>$config['secret_crypto_key']</code> em
      <code>application/config/config.php</code> antes de cadastrar credenciais — sem ela a senha não é salva.
      Gere a chave com <code>php -r "echo bin2hex(random_bytes(32));"</code>.
    </div>
  </div>
<?php } ?>

<?php if (validation_errors()) { ?>
  <div class="alert alert-danger alert-dismissible" role="alert">
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
    <div class="alert-message"><?php echo validation_errors('<div class="small mt-1">', '</div>'); ?></div>
  </div>
<?php } ?>

<div class="card flex-fill">
  <div class="card-body">
    <form method="POST" action="<?php echo $edicao ? base_url('servidores/editar?id=' . (int) $result->id) : base_url('servidores/novo'); ?>" name="form" autocomplete="off">
      <?php if ($edicao) { ?>
        <input type="hidden" name="id" value="<?php echo (int) $result->id; ?>">
      <?php } ?>

      <div class="row">
        <div class="col-md-6">
          <div class="form-group mb-3">
            <label class="form-label">* Nome do servidor</label>
            <input type="text" class="form-control" name="server[name]" maxlength="150" required
              placeholder="Ex.: SERVIDOR PRINCIPAL"
              value="<?php echo set_value('server[name]', $edicao ? $result->name : ''); ?>">
            <div class="form-text">Identificação interna. Precisa ser único dentro da empresa.</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="form-group mb-3">
            <label class="form-label">* Tipo de painel</label>
            <select class="form-control" name="server[type]" id="server_type" required>
              <?php foreach ($server_types as $alias => $rotulo) { ?>
                <option value="<?php echo $alias; ?>" <?php echo ($tipoAtual === $alias) ? 'selected=""' : ''; ?>><?php echo $rotulo; ?></option>
              <?php } ?>
            </select>
          </div>
        </div>
        <div class="col-md-3">
          <div class="form-group mb-3">
            <label class="form-label">* Timeout (segundos)</label>
            <input type="number" class="form-control" name="server[timeout_seconds]" min="1" max="600" required
              value="<?php echo set_value('server[timeout_seconds]', $edicao ? (int) $result->timeout_seconds : 30); ?>">
          </div>
        </div>
      </div>

      <div class="row">
        <div class="col-md-6">
          <div class="form-group mb-3">
            <label class="form-label">* <span id="label_host">Endereço</span></label>
            <input type="text" class="form-control" name="server[host]" id="server_host" maxlength="255" required
              value="<?php echo set_value('server[host]', $edicao ? $result->host : ''); ?>">
            <div class="form-text" id="help_host"></div>
          </div>
        </div>
        <div class="col-md-3 js-only-cloudpanel">
          <div class="form-group mb-3">
            <label class="form-label">* Porta SSH</label>
            <input type="number" class="form-control" name="server[port]" min="1" max="65535"
              value="<?php echo set_value('server[port]', ($edicao && !empty($result->port)) ? (int) $result->port : 22); ?>">
          </div>
        </div>
        <div class="col-md-3">
          <div class="form-group mb-3">
            <label class="form-label">* <span id="label_username">Usuário</span></label>
            <input type="text" class="form-control" name="server[username]" id="server_username" maxlength="150" required
              value="<?php echo set_value('server[username]', $edicao ? $result->username : 'root'); ?>">
          </div>
        </div>
      </div>

      <div class="row">
        <div class="col-md-3 js-only-cloudpanel">
          <div class="form-group mb-3">
            <label class="form-label">* Autenticação</label>
            <select class="form-control" name="server[auth_type]" id="server_auth_type">
              <option value="password" <?php echo ($authAtual === 'password') ? 'selected=""' : ''; ?>>Senha</option>
              <option value="key" <?php echo ($authAtual === 'key') ? 'selected=""' : ''; ?>>Chave privada SSH</option>
            </select>
          </div>
        </div>
        <div class="col-md-9" id="wrap_secret">
          <div class="form-group mb-3">
            <div class="d-flex align-items-center justify-content-between">
              <label class="form-label mb-1"><?php echo $edicao ? '' : '* '; ?><span id="label_secret">Credencial</span></label>
              <?php if ($edicao) { ?>
                <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none" id="btn_revelar" data-id="<?php echo (int) $result->id; ?>">
                  <i class="fa fa-eye"></i> <span id="btn_revelar_texto">Ver credencial salva</span>
                </button>
              <?php } ?>
            </div>

            <div id="wrap_secret_input">
              <input type="password" class="form-control" name="secret" id="secret_input" autocomplete="new-password" data-lpignore="true"
                placeholder="<?php echo $edicao ? 'Deixe em branco para manter a credencial atual' : ''; ?>">
            </div>

            <div id="wrap_secret_textarea" style="display: none;">
              <textarea class="form-control font-monospace" name="secret_key" id="secret_textarea" rows="6"
                placeholder="<?php echo $edicao ? 'Deixe em branco para manter a chave atual' : '-----BEGIN OPENSSH PRIVATE KEY-----'; ?>"></textarea>
            </div>

            <div class="form-text" id="help_secret"></div>
            <?php if ($edicao) { ?>
              <div class="form-text text-warning d-none" id="aviso_revelado">
                <i class="fa fa-exclamation-triangle"></i> Credencial visível. Clique em <strong>Ocultar</strong> para limpar o campo e manter a credencial atual ao salvar.
              </div>
            <?php } ?>
          </div>
        </div>
      </div>

      <div class="row js-only-http">
        <div class="col-md-12">
          <div class="form-group mb-3">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" id="verify_ssl" name="server[verify_ssl]" value="1" <?php echo $verificaSsl ? 'checked' : ''; ?>>
              <label class="form-check-label" for="verify_ssl">Verificar certificado SSL</label>
            </div>
            <div class="form-text">Desmarque quando o painel usa certificado auto-assinado — é o caso mais comum em WHM e DirectAdmin.</div>
          </div>
        </div>
      </div>

      <?php if ($edicao) { ?>
        <hr>
        <div class="row">
          <div class="col-md-6">
            <div class="form-group mb-3">
              <label class="form-label">Último teste</label>
              <div>
                <?php if (empty($result->last_test)) { ?>
                  <span class="badge bg-secondary">Nunca testado</span>
                <?php } else { ?>
                  <span class="badge bg-<?php echo ($result->last_test_status === 'conectado') ? 'success' : 'danger'; ?>">
                    <?php echo ($result->last_test_status === 'conectado') ? 'Conectado' : 'Erro'; ?>
                  </span>
                  <small class="text-muted ms-1"><?php echo data($result->last_test); ?><?php if (!empty($result->last_test_ms)) echo ' · ' . numero((int) $result->last_test_ms) . 'ms'; ?></small>
                  <?php if (!empty($result->last_test_message)) { ?>
                    <div class="small text-muted mt-1"><?php echo htmlspecialchars($result->last_test_message, ENT_QUOTES, 'UTF-8'); ?></div>
                  <?php } ?>
                <?php } ?>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="form-group mb-3">
              <label class="form-label">Última sincronização</label>
              <div>
                <?php if (empty($result->last_sync)) { ?>
                  <span class="text-muted">Nunca sincronizado</span>
                <?php } else { ?>
                  <span class="badge bg-<?php echo ($result->last_sync_status === 'sucesso') ? 'success' : (($result->last_sync_status === 'parcial') ? 'warning' : 'danger'); ?>">
                    <?php echo ucfirst((string) $result->last_sync_status); ?>
                  </span>
                  <small class="text-muted ms-1"><?php echo data($result->last_sync); ?></small>
                  <?php if (!empty($result->last_sync_message)) { ?>
                    <div class="small text-muted mt-1"><?php echo htmlspecialchars($result->last_sync_message, ENT_QUOTES, 'UTF-8'); ?></div>
                  <?php } ?>
                <?php } ?>
              </div>
            </div>
          </div>
        </div>
      <?php } ?>

      <hr>
      <div class="row">
        <div class="col-md-3">
          <button type="submit" class="btn w-100 btn-primary"><i class="fa fa-save"></i> SALVAR</button>
        </div>
        <?php if ($edicao) { ?>
          <div class="col-md-3">
            <button type="button" class="btn w-100 btn-outline-primary js-testar" data-id="<?php echo (int) $result->id; ?>"><i class="fa fa-plug"></i> TESTAR CONEXÃO</button>
          </div>
          <div class="col-md-3">
            <button type="button" class="btn w-100 btn-outline-success js-sincronizar" data-id="<?php echo (int) $result->id; ?>"><i class="fa fa-sync"></i> SINCRONIZAR DOMÍNIOS</button>
          </div>
        <?php } ?>
      </div>
      <?php if ($edicao) { ?>
        <div class="form-text mt-2">O teste usa a credencial já salva. Se você acabou de trocá-la, salve antes de testar.</div>
      <?php } ?>
    </form>
  </div>
</div>

<script>
  document.addEventListener("DOMContentLoaded", function() {
    var TEXTOS = {
      whm: {
        host: 'Endereço do WHM',
        host_help: 'Hostname ou IP. A API do WHM responde sempre na porta 2087.',
        username: 'Usuário do WHM',
        secret: 'Token de API',
        secret_help: 'Gere em WHM › Development › Manage API Tokens.'
      },
      directadmin: {
        host: 'Endereço do DirectAdmin',
        host_help: 'Hostname ou IP. Se a porta não for a 2222, informe no endereço (ex.: painel.exemplo.com:2223).',
        username: 'Usuário admin',
        secret: 'Login Key',
        secret_help: 'Gere em DirectAdmin › Account Manager › Login Keys.'
      },
      cloudpanel: {
        host: 'Host / IP',
        host_help: 'O CloudPanel não tem API: a conexão é por SSH.',
        username: 'Usuário SSH',
        secret_password: 'Senha SSH',
        secret_key: 'Chave privada SSH',
        secret_help_password: 'O usuário precisa conseguir ler /home de todos os sites (na prática, root).',
        secret_help_key: 'Cole o conteúdo completo da chave privada. Chave com passphrase não é suportada.'
      }
    };

    var $tipo = $('#server_type');
    var $auth = $('#server_auth_type');

    function aplicarTipo() {
      var tipo = $tipo.val();
      var textos = TEXTOS[tipo] || TEXTOS.whm;
      var ehCloudPanel = (tipo === 'cloudpanel');
      var usaChave = ehCloudPanel && $auth.val() === 'key';

      $('.js-only-cloudpanel').toggle(ehCloudPanel);
      $('.js-only-http').toggle(!ehCloudPanel);

      $('#label_host').text(textos.host);
      $('#help_host').text(textos.host_help);
      $('#label_username').text(textos.username);

      if (ehCloudPanel) {
        $('#label_secret').text(usaChave ? textos.secret_key : textos.secret_password);
        $('#help_secret').text(usaChave ? textos.secret_help_key : textos.secret_help_password);
      } else {
        $('#label_secret').text(textos.secret);
        $('#help_secret').text(textos.secret_help);
      }

      // A chave privada é multilinha e não cabe num input. Os dois campos
      // existem no DOM, mas só o visível leva o name "secret" — assim o POST
      // nunca manda os dois valores.
      $('#wrap_secret_textarea').toggle(usaChave);
      $('#wrap_secret_input').toggle(!usaChave);

      if (usaChave) {
        $('#secret_input').attr('name', 'secret_input_off');
        $('#secret_textarea').attr('name', 'secret');
      } else {
        $('#secret_textarea').attr('name', 'secret_key_off');
        $('#secret_input').attr('name', 'secret');
      }
    }

    $tipo.on('change', aplicarTipo);
    $auth.on('change', aplicarTipo);
    aplicarTipo();

    <?php if ($edicao) { ?>
      // Ver / ocultar a credencial salva.
      //
      // O valor não vem no HTML da página: é buscado sob demanda em
      // json_postrevelar. Ao ocultar, o campo volta a ficar vazio — que é o
      // estado que faz o SALVAR manter a credencial atual.
      var revelado = false;
      var $btnRevelar = $('#btn_revelar');

      function preencherCampoCredencial(valor) {
        var usaTextarea = $('#wrap_secret_textarea').is(':visible');
        if (usaTextarea) {
          $('#secret_textarea').val(valor);
        } else {
          $('#secret_input').val(valor).attr('type', valor === '' ? 'password' : 'text');
        }
      }

      function definirEstadoRevelado(estado) {
        revelado = estado;
        $('#btn_revelar_texto').text(estado ? 'Ocultar' : 'Ver credencial salva');
        $btnRevelar.find('i').removeClass('fa-eye fa-eye-slash').addClass(estado ? 'fa-eye-slash' : 'fa-eye');
        $('#aviso_revelado').toggleClass('d-none', !estado);
      }

      $btnRevelar.on('click', function() {
        if (revelado) {
          preencherCampoCredencial('');
          definirEstadoRevelado(false);
          return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true);

        $.ajax({
          type: 'POST',
          url: '<?php echo base_url('servidores/json_postrevelar'); ?>',
          data: {
            id: $btn.data('id')
          },
          dataType: 'json',
          success: function(data) {
            if (data && data.redirect) {
              window.location.replace('<?php echo base_url('painel/sair_custom'); ?>');
              return;
            }
            if (!data || !data.return) {
              Swal.fire('Não foi possível exibir', (data && data.message) ? data.message : 'Falha ao ler a credencial.', 'error');
              return;
            }
            preencherCampoCredencial(data.data.secret);
            definirEstadoRevelado(true);
          },
          error: function(xhr) {
            console.log(xhr.responseText);
            Swal.fire('Erro', 'Falha ao ler a credencial.', 'error');
          },
          complete: function() {
            $btn.prop('disabled', false);
          }
        });
      });

      // Trocar de tipo/autenticação troca o campo visível; o valor revelado no
      // campo antigo ficaria escondido no DOM e seria enviado no submit.
      $tipo.add($auth).on('change', function() {
        if (revelado) {
          $('#secret_input').val('').attr('type', 'password');
          $('#secret_textarea').val('');
          definirEstadoRevelado(false);
        }
      });
    <?php } ?>

    <?php if ($edicao) { ?>
      function notificar(tipo, mensagem) {
        window.notyf.open({
          type: tipo,
          message: mensagem,
          duration: 7000,
          ripple: true,
          dismissible: true,
          position: {
            x: 'top',
            y: 'top'
          }
        });
      }

      function sessaoExpirou(data) {
        if (data && data.redirect) {
          window.location.replace('<?php echo base_url('painel/sair_custom'); ?>');
          return true;
        }
        return false;
      }

      $('.js-testar').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).find('i').removeClass('fa-plug').addClass('fa-spinner fa-spin');

        $.ajax({
          type: 'POST',
          url: '<?php echo base_url('servidores/json_posttestar'); ?>',
          data: {
            id: $btn.data('id')
          },
          dataType: 'json',
          success: function(data) {
            if (sessaoExpirou(data)) return;
            if (!data || !data.return) {
              Swal.fire('Falha na conexão', (data && data.message) ? data.message : 'Não foi possível conectar.', 'error');
              return;
            }
            notificar('success', data.message);
            setTimeout(function() {
              window.location.reload();
            }, 1200);
          },
          error: function(xhr) {
            console.log(xhr.responseText);
            Swal.fire('Erro', 'Falha ao testar a conexão.', 'error');
          },
          complete: function() {
            $btn.prop('disabled', false).find('i').removeClass('fa-spinner fa-spin').addClass('fa-plug');
          }
        });
      });

      $('.js-sincronizar').on('click', function() {
        var $btn = $(this);

        Swal.fire({
          title: 'Sincronizar domínios?',
          html: 'Domínios que não existirem mais no servidor serão removidos daqui.',
          icon: 'question',
          showCancelButton: true,
          confirmButtonColor: '#3085d6',
          cancelButtonColor: '#ccc',
          cancelButtonText: 'Cancelar',
          confirmButtonText: 'Sincronizar'
        }).then(function(result) {
          if (!result.value) return;

          $btn.prop('disabled', true).find('i').addClass('fa-spin');
          $('#modal_loading').modal('show');

          $.ajax({
            type: 'POST',
            url: '<?php echo base_url('servidores/json_postsincronizar'); ?>',
            data: {
              id: $btn.data('id')
            },
            dataType: 'json',
            success: function(data) {
              if (sessaoExpirou(data)) return;
              if (!data || !data.return) {
                $('#modal_loading').modal('hide');
                Swal.fire('Falha na sincronização', (data && data.message) ? data.message : 'Não foi possível sincronizar.', 'error');
                return;
              }
              notificar('success', data.message);
              setTimeout(function() {
                window.location.reload();
              }, 1200);
            },
            error: function(xhr) {
              $('#modal_loading').modal('hide');
              console.log(xhr.responseText);
              Swal.fire('Erro', 'Falha ao sincronizar os domínios.', 'error');
            },
            complete: function() {
              $btn.prop('disabled', false).find('i').removeClass('fa-spin');
            }
          });
        });
      });
    <?php } ?>
  });
</script>
