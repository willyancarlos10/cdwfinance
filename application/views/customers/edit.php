<?php
$attr = function ($secao, $chave, $default = '') use ($attrs) {
  return isset($attrs[$secao][$chave]) ? $attrs[$secao][$chave] : $default;
};
$attrAddr = function ($chave, $default = '') use ($attrs) {
  return isset($attrs['representative']['address'][$chave]) ? $attrs['representative']['address'][$chave] : $default;
};
$tipoAtual = $result->type;
?>
<div class="row mb-2 mb-xl-2">
  <div class="col-auto text-start">
    <h1 class="h3 mb-3"><?php echo htmlspecialchars($result->name, ENT_QUOTES, 'UTF-8'); ?></h1>
  </div>
  <div class="col-auto ms-auto text-end mt-n1">
    <a class="btn btn-outline-secondary" href="<?php echo base_url('clientes/info?id=' . (int) $result->id); ?>"><i class="fa fa-arrow-left"></i> VOLTAR</a>
  </div>
</div>

<div class="card flex-fill">
  <div class="card-body py-3">
    <?php echo empty(validation_errors()) ? '' : '<div class="alert alert-danger alert-dismissible" role="alert"><button type="button" class="btn-close d-none" data-bs-dismiss="alert" aria-label="Close"></button><div class="alert-message">' . validation_errors() . '</div></div>'; ?>

    <form method="POST" action="<?php echo current_url() . '?id=' . (int) $result->id; ?>" name="form" enctype="multipart/form-data">
      <input type="hidden" name="id" value="<?php echo (int) $result->id; ?>">

      <ul class="nav nav-pills mb-3" role="tablist">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab_principais" role="tab">Dados principais</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab_representante" role="tab">Representante</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab_financeiro" role="tab">Financeiro</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab_contrato" role="tab">Domínios e comentários</a></li>
      </ul>

      <div class="tab-content">
        <!-- DADOS PRINCIPAIS -->
        <div class="tab-pane fade show active" id="tab_principais" role="tabpanel">
          <div class="row">
            <div class="col-12 col-md-2">
              <div class="form-group mb-3">
                <label class="form-label">* Tipo</label>
                <select class="form-control" name="customer[type]" id="type">
                  <option value="J" <?php if ($tipoAtual === 'J') echo 'selected=""'; ?>>Pessoa jurídica</option>
                  <option value="F" <?php if ($tipoAtual === 'F') echo 'selected=""'; ?>>Pessoa física</option>
                </select>
              </div>
            </div>
            <div class="col-12 col-md-4">
              <div class="form-group mb-3">
                <label class="form-label" id="label_document">* <?php echo $tipoAtual === 'F' ? 'CPF' : 'CNPJ'; ?></label>
                <div class="input-group">
                  <input type="text" name="customer[document]" class="form-control" id="document" required value="<?php echo set_value('customer[document]', cnpj($result->document)); ?>">
                  <button type="button" class="btn btn-outline-primary" id="btn_buscar_cnpj" title="Buscar dados do CNPJ" <?php if ($tipoAtual === 'F') echo 'disabled'; ?>>
                    <i class="mdi mdi-magnify"></i>
                  </button>
                </div>
              </div>
            </div>
            <div class="col-12 col-md-6">
              <div class="form-group mb-3">
                <label class="form-label" id="label_name">* <?php echo $tipoAtual === 'F' ? 'Nome completo' : 'Razão social'; ?></label>
                <input type="text" class="form-control" name="customer[name]" id="name" required maxlength="150" value="<?php echo set_value('customer[name]', $result->name); ?>">
              </div>
            </div>
            <div class="col-12 col-md-4">
              <div class="form-group mb-3">
                <label class="form-label">Nome fantasia</label>
                <input type="text" class="form-control" name="customer[byname]" id="byname" maxlength="150" value="<?php echo set_value('customer[byname]', $result->byname); ?>">
              </div>
            </div>
            <!-- Só PJ tem inscrição estadual: em PF o bloco é escondido e o
                 controller grava ISENTO. -->
            <div class="col-12 col-md-2" id="bloco_ie" <?php if ($tipoAtual === 'F') echo 'style="display:none;"'; ?>>
              <div class="form-group mb-3">
                <label class="form-label">Inscrição estadual</label>
                <input type="text" class="form-control" name="customer[state_registration]" id="state_registration" maxlength="20" value="<?php echo set_value('customer[state_registration]', $result->state_registration); ?>">
                <small class="form-text text-muted">Em branco = ISENTO.</small>
              </div>
            </div>
            <div class="col-12 col-md-6">
              <div class="form-group mb-3">
                <label class="form-label">* E-mail para envio do contrato digital</label>
                <input type="email" class="form-control" name="customer[email]" id="email" required maxlength="150" value="<?php echo set_value('customer[email]', $result->email); ?>">
              </div>
            </div>
          </div>
          <hr>
          <h5>Endereço comercial</h5>
          <div class="row">
            <div class="col-12 col-md-3">
              <div class="form-group mb-3">
                <label class="form-label">* CEP</label>
                <input type="text" class="form-control" name="customer[address_zip]" id="address_zip" data-mask="00.000-000" data-mask-reverse="TRUE" required value="<?php echo set_value('customer[address_zip]', $result->address_zip); ?>">
              </div>
            </div>
            <div class="col-12 col-md-6">
              <div class="form-group mb-3">
                <label class="form-label">* Endereço</label>
                <input type="text" class="form-control" name="customer[address]" id="address" required maxlength="200" value="<?php echo set_value('customer[address]', $result->address); ?>">
              </div>
            </div>
            <div class="col-12 col-md-3">
              <div class="form-group mb-3">
                <label class="form-label">* Número</label>
                <input type="text" class="form-control" name="customer[address_number]" id="address_number" required maxlength="20" value="<?php echo set_value('customer[address_number]', $result->address_number); ?>">
              </div>
            </div>
            <div class="col-12 col-md-4">
              <div class="form-group mb-3">
                <label class="form-label">* Bairro</label>
                <input type="text" class="form-control" name="customer[address_district]" id="address_district" required maxlength="150" value="<?php echo set_value('customer[address_district]', $result->address_district); ?>">
              </div>
            </div>
            <div class="col-12 col-md-4">
              <div class="form-group mb-3">
                <label class="form-label">Complemento</label>
                <input type="text" class="form-control" name="customer[address_complement]" id="address_complement" maxlength="200" value="<?php echo set_value('customer[address_complement]', $result->address_complement); ?>">
              </div>
            </div>
            <div class="col-12 col-md-2">
              <div class="form-group mb-3">
                <label class="form-label">* Estado</label>
                <select class="form-control select2" name="customer[id_state]" id="id_state" required>
                  <option value="">-- UF --</option>
                  <?php foreach ($states as $row) { ?>
                    <option <?php if ((int) $row->id === (int) $result->id_state) echo 'selected=""'; ?> value="<?php echo (int) $row->id; ?>"><?php echo $row->uf; ?></option>
                  <?php } ?>
                </select>
              </div>
            </div>
            <div class="col-12 col-md-2">
              <div class="form-group mb-3">
                <label class="form-label">* Cidade</label>
                <select class="form-control select2" name="customer[id_city]" id="id_city" required>
                  <option value="">-- Selecione --</option>
                  <?php foreach ($cities as $row) { ?>
                    <option <?php if ((int) $row->id === (int) $result->id_city) echo 'selected=""'; ?> value="<?php echo (int) $row->id; ?>"><?php echo $row->name; ?></option>
                  <?php } ?>
                </select>
              </div>
            </div>
            <?php if (empty($result->id_city) && !empty($attrs['address_text'])) { ?>
              <div class="col-12">
                <div class="alert alert-warning mb-3">
                  <div class="alert-message">
                    A cidade informada no cadastro público não foi reconhecida:
                    <strong><?php echo htmlspecialchars((isset($attrs['address_text']['city']) ? $attrs['address_text']['city'] : '') . ' / ' . (isset($attrs['address_text']['uf']) ? $attrs['address_text']['uf'] : ''), ENT_QUOTES, 'UTF-8'); ?></strong>.
                    Selecione o estado e a cidade corretos acima.
                  </div>
                </div>
              </div>
            <?php } ?>
          </div>
        </div>

        <!-- REPRESENTANTE -->
        <div class="tab-pane fade" id="tab_representante" role="tabpanel">
          <h5>Proprietário e/ou sócio representante</h5>
          <p class="text-muted">Dados de quem assina o contrato.</p>
          <div class="row">
            <div class="col-12 col-md-6">
              <div class="form-group mb-3">
                <label class="form-label">Nome completo</label>
                <input type="text" class="form-control" name="attr[representative][name]" maxlength="150" value="<?php echo set_value('attr[representative][name]', $attr('representative', 'name')); ?>">
              </div>
            </div>
            <div class="col-12 col-md-6">
              <div class="form-group mb-3">
                <label class="form-label">Nacionalidade</label>
                <select class="form-control" name="attr[representative][nationality]" id="nationality">
                  <option value="brasileira" <?php if ($attr('representative', 'nationality', 'brasileira') === 'brasileira') echo 'selected=""'; ?>>Brasileira</option>
                  <option value="outra" <?php if ($attr('representative', 'nationality') === 'outra') echo 'selected=""'; ?>>Outra</option>
                </select>
              </div>
            </div>
            <div class="col-12 col-md-3">
              <div class="form-group mb-3">
                <label class="form-label">Estado civil</label>
                <select class="form-control" name="attr[representative][marital_status]">
                  <option value="">-- Selecione --</option>
                  <?php foreach ($marital_statuses as $alias => $rotulo) { ?>
                    <option <?php if ($attr('representative', 'marital_status') === $alias) echo 'selected=""'; ?> value="<?php echo $alias; ?>"><?php echo $rotulo; ?></option>
                  <?php } ?>
                </select>
              </div>
            </div>
            <div class="col-12 col-md-3">
              <div class="form-group mb-3">
                <label class="form-label">Profissão</label>
                <input type="text" class="form-control" name="attr[representative][profession]" maxlength="100" value="<?php echo set_value('attr[representative][profession]', $attr('representative', 'profession')); ?>">
              </div>
            </div>
            <div class="col-12 col-md-3">
              <div class="form-group mb-3">
                <label class="form-label">RG</label>
                <input type="text" class="form-control" name="attr[representative][rg]" maxlength="20" value="<?php echo set_value('attr[representative][rg]', $attr('representative', 'rg')); ?>">
              </div>
            </div>
            <div class="col-12 col-md-3">
              <div class="form-group mb-3">
                <label class="form-label">CPF</label>
                <input type="text" class="form-control" name="attr[representative][cpf]" data-mask="000.000.000-00" data-mask-reverse="TRUE" value="<?php echo set_value('attr[representative][cpf]', cnpj($attr('representative', 'cpf'))); ?>">
              </div>
            </div>
            <div class="col-12 col-md-3">
              <div class="form-group mb-3">
                <label class="form-label">WhatsApp</label>
                <input type="text" class="form-control phonemask" name="attr[representative][whatsapp]" value="<?php echo set_value('attr[representative][whatsapp]', $attr('representative', 'whatsapp')); ?>">
              </div>
            </div>
          </div>
          <hr>
          <h5>Endereço residencial do representante</h5>
          <div class="row">
            <div class="col-12 col-md-3">
              <div class="form-group mb-3">
                <label class="form-label">CEP</label>
                <input type="text" class="form-control" name="attr[representative][address][zip]" id="rep_zip" data-mask="00.000-000" data-mask-reverse="TRUE" value="<?php echo set_value('attr[representative][address][zip]', $attrAddr('zip')); ?>">
              </div>
            </div>
            <div class="col-12 col-md-6">
              <div class="form-group mb-3">
                <label class="form-label">Rua / Avenida</label>
                <input type="text" class="form-control" name="attr[representative][address][street]" id="rep_street" maxlength="200" value="<?php echo set_value('attr[representative][address][street]', $attrAddr('street')); ?>">
              </div>
            </div>
            <div class="col-12 col-md-3">
              <div class="form-group mb-3">
                <label class="form-label">Número</label>
                <input type="text" class="form-control" name="attr[representative][address][number]" maxlength="20" value="<?php echo set_value('attr[representative][address][number]', $attrAddr('number')); ?>">
              </div>
            </div>
            <div class="col-12 col-md-4">
              <div class="form-group mb-3">
                <label class="form-label">Bairro</label>
                <input type="text" class="form-control" name="attr[representative][address][district]" id="rep_district" maxlength="150" value="<?php echo set_value('attr[representative][address][district]', $attrAddr('district')); ?>">
              </div>
            </div>
            <div class="col-12 col-md-4">
              <div class="form-group mb-3">
                <label class="form-label">Complemento</label>
                <input type="text" class="form-control" name="attr[representative][address][complement]" maxlength="200" value="<?php echo set_value('attr[representative][address][complement]', $attrAddr('complement')); ?>">
              </div>
            </div>
            <div class="col-12 col-md-2">
              <div class="form-group mb-3">
                <label class="form-label">Estado</label>
                <select class="form-control select2" name="attr[representative][address][id_state]" id="rep_id_state">
                  <option value="">-- UF --</option>
                  <?php foreach ($states as $row) { ?>
                    <option <?php if ((string) $attrAddr('id_state') === (string) $row->id) echo 'selected=""'; ?> value="<?php echo (int) $row->id; ?>" data-uf="<?php echo $row->uf; ?>"><?php echo $row->uf; ?></option>
                  <?php } ?>
                </select>
              </div>
            </div>
            <div class="col-12 col-md-2">
              <div class="form-group mb-3">
                <label class="form-label">Cidade</label>
                <select class="form-control select2" name="attr[representative][address][id_city]" id="rep_id_city">
                  <option value="">-- Selecione --</option>
                  <?php foreach ($rep_cities as $row) { ?>
                    <option <?php if ((string) $attrAddr('id_city') === (string) $row->id) echo 'selected=""'; ?> value="<?php echo (int) $row->id; ?>"><?php echo $row->name; ?></option>
                  <?php } ?>
                </select>
              </div>
            </div>
          </div>
        </div>

        <!-- FINANCEIRO -->
        <div class="tab-pane fade" id="tab_financeiro" role="tabpanel">
          <h5>Responsável financeiro</h5>
          <div class="row">
            <div class="col-12 col-md-4">
              <div class="form-group mb-3">
                <label class="form-label">Nome</label>
                <input type="text" class="form-control" name="attr[billing][name]" maxlength="150" value="<?php echo set_value('attr[billing][name]', $attr('billing', 'name')); ?>">
              </div>
            </div>
            <div class="col-12 col-md-4">
              <div class="form-group mb-3">
                <label class="form-label">E-mail para envio das faturas</label>
                <input type="email" class="form-control" name="attr[billing][email]" maxlength="150" value="<?php echo set_value('attr[billing][email]', $attr('billing', 'email')); ?>">
              </div>
            </div>
            <div class="col-12 col-md-4">
              <div class="form-group mb-3">
                <label class="form-label">WhatsApp</label>
                <input type="text" class="form-control phonemask" name="attr[billing][whatsapp]" value="<?php echo set_value('attr[billing][whatsapp]', $attr('billing', 'whatsapp')); ?>">
              </div>
            </div>
          </div>
          <hr>
          <h5>Nota fiscal</h5>
          <div class="row">
            <div class="col-12 col-md-4">
              <div class="form-group mb-3">
                <label class="form-label">Necessita nota fiscal?</label>
                <select class="form-control" name="attr[billing][needs_invoice]">
                  <option value="">-- Selecione --</option>
                  <option value="S" <?php if ($attr('billing', 'needs_invoice') === 'S') echo 'selected=""'; ?>>Sim</option>
                  <option value="N" <?php if ($attr('billing', 'needs_invoice') === 'N') echo 'selected=""'; ?>>Não</option>
                </select>
              </div>
            </div>
          </div>
        </div>

        <!-- DOMÍNIOS E CONTRATO -->
        <div class="tab-pane fade" id="tab_contrato" role="tabpanel">
          <h5>Domínios</h5>
          <div class="row">
            <div class="col-12 col-md-6">
              <div class="form-group mb-3">
                <label class="form-label">Domínio principal do site</label>
                <input type="text" class="form-control" name="attr[domains][primary]" maxlength="255" placeholder="exemplo.com.br" value="<?php echo set_value('attr[domains][primary]', $attr('domains', 'primary')); ?>">
              </div>
            </div>
            <div class="col-12 col-md-6">
              <div class="form-group mb-3">
                <label class="form-label">Domínio secundário (se houver)</label>
                <input type="text" class="form-control" name="attr[domains][secondary]" maxlength="255" value="<?php echo set_value('attr[domains][secondary]', $attr('domains', 'secondary')); ?>">
              </div>
            </div>
          </div>
          <hr>
          <h5>Comentários</h5>
          <div class="row">
            <div class="col-12">
              <div class="form-group mb-3">
                <label class="form-label">Comentários do cadastro</label>
                <textarea class="form-control" name="attr[contract][comments]" rows="3" maxlength="1000"><?php echo set_value('attr[contract][comments]', $attr('contract', 'comments')); ?></textarea>
              </div>
            </div>
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
    var mascara89Dig = function(val) {
        return val.replace(/\D/g, '').length === 11 ? '(00) 00000-0000' : '(00) 0000-00009';
      },
      optionsTel = {
        onKeyPress: function(val, e, field, options) {
          field.mask(mascara89Dig.apply({}, arguments), options);
        }
      };
    $('.phonemask').mask(mascara89Dig, optionsTel);

    function aplicarMascaraDocumento() {
      var tipo = $('#type').val();
      $('#document').unmask();
      if (tipo === 'F') {
        $('#document').mask('000.000.000-00', {reverse: true});
        $('#label_document').text('* CPF');
        $('#label_name').text('* Nome completo');
        $('#btn_buscar_cnpj').prop('disabled', true);
        $('#bloco_ie').hide();
      } else {
        $('#document').mask('00.000.000/0000-00', {reverse: true});
        $('#label_document').text('* CNPJ');
        $('#label_name').text('* Razão social');
        $('#btn_buscar_cnpj').prop('disabled', false);
        $('#bloco_ie').show();
      }
    }
    aplicarMascaraDocumento();
    $('#type').on('change', aplicarMascaraDocumento);

    function sessaoExpirou(data) {
      if (data && data.redirect) {
        window.location.replace('<?php echo base_url('painel/sair_custom'); ?>');
        return true;
      }
      return false;
    }

    function carregarCidadesEm($city, idState, idCitySelecionar) {
      $.ajax({
        type: 'POST',
        url: '<?php echo base_url('clientes/json_get_cities_by_id/'); ?>' + idState,
        dataType: 'json',
        success: function(data) {
          if (sessaoExpirou(data)) return;
          $city.empty().append($('<option />').val('').text('-- Selecione --'));
          $.each(data, function(index, element) {
            var opt = $('<option />');
            opt.val(element.id);
            opt.text(element.name);
            $city.append(opt);
          });
          if (idCitySelecionar) $city.val(String(idCitySelecionar));
          $city.trigger('change.select2');
        }
      });
    }

    function carregarCidades(idState, idCitySelecionar) {
      carregarCidadesEm($('#id_city'), idState, idCitySelecionar);
    }

    $('#id_state').on('change', function() {
      var idState = $(this).val();
      if (idState) carregarCidades(idState, null);
    });

    $('#rep_id_state').on('change', function() {
      var idState = $(this).val();
      if (idState) carregarCidadesEm($('#rep_id_city'), idState, null);
    });

    // Busca de CNPJ: reusa o endpoint público já existente do cadastro de
    // empresas (Login::json_getsefaz). O objeto de retorno vem em data.message
    // (formato legado) — mesmo consumo do register.php.
    function preencherFormulario(message) {
      if (!message) return;

      $('#name').val(message.name || '');
      $('#byname').val(message.byname || message.name || '');
      if (message.email && !$('#email').val()) $('#email').val(message.email);

      $('#address_zip').unmask();
      $('#address_zip').val(message.address_zip || '');
      $('#address_zip').mask('00.000-000');

      $('#address').val(message.address || '');
      $('#address_number').val(message.address_number || '');
      $('#address_district').val(message.address_district || '');
      $('#address_complement').val(message.address_complement || '');

      if (message.id_state && parseInt(message.id_state, 10) > 0) {
        $('#id_state').val(String(message.id_state)).trigger('change.select2');
        carregarCidades(message.id_state, message.id_city);
      }

      var membership = [];
      if (message.membership) {
        try {
          membership = typeof message.membership === 'string' ? JSON.parse(message.membership) : message.membership;
        } catch (error) {
          membership = [];
        }
      }
      if (Array.isArray(membership) && membership.length) {
        var socio = membership[0].nome || membership[0].nome_socio || '';
        var $repName = $('input[name="attr[representative][name]"]');
        if (socio && !$repName.val()) $repName.val(socio);
      }
    }

    $('#btn_buscar_cnpj').on('click', function() {
      var documento = $('#document').val().replace(/\D/g, '');
      if ($('#type').val() !== 'J' || documento.length !== 14) {
        Swal.fire('Atenção', 'Informe um CNPJ completo com 14 dígitos.', 'warning');
        return;
      }

      var $btn = $(this);
      $btn.prop('disabled', true);
      $('#modal_loading').modal('show');

      $.ajax({
        type: 'POST',
        data: '',
        dataType: 'json',
        url: '<?php echo base_url('login/json_getsefaz/'); ?>' + documento,
        success: function(data) {
          if (sessaoExpirou(data)) return;
          if (data && data.return) {
            preencherFormulario(data.message);
            return;
          }
          Swal.fire('Ops', (data && data.message) ? data.message : 'Não foi possível consultar o CNPJ informado.', 'warning');
        },
        error: function() {
          Swal.fire('Erro', 'Não foi possível consultar o CNPJ. Tente novamente em instantes.', 'error');
        }
      }).always(function() {
        $('#modal_loading').modal('hide');
        $btn.prop('disabled', false);
      });
    });

    // CEP do endereço comercial: versão do painel (MY_Controller::json_getcep),
    // que também resolve id_state/id_city para os selects encadeados.
    $('#address_zip').blur(function() {
      var zipcode = $('#address_zip').val();
      if (!zipcode) return;
      $.ajax({
        type: 'POST',
        data: '',
        url: '<?php echo base_url('clientes/json_getcep/'); ?>' + encodeURIComponent(zipcode),
        dataType: 'json',
        success: function(data) {
          if (sessaoExpirou(data)) return;
          if (!data.return) return;
          if (data.logradouro) $('#address').val(data.logradouro);
          if (data.bairro) $('#address_district').val(data.bairro);
          if (data.id_state && parseInt(data.id_state, 10) > 0) {
            $('#id_state').val(String(data.id_state)).trigger('change.select2');
            carregarCidades(data.id_state, data.id_city);
          }
          $('#address_number').focus();
        }
      });
    });

    // CEP residencial do representante: preenche os selects encadeados
    // (a versão do painel do json_getcep resolve id_state/id_city).
    $('#rep_zip').blur(function() {
      var zipcode = $('#rep_zip').val();
      if (!zipcode) return;
      $.ajax({
        type: 'POST',
        data: '',
        url: '<?php echo base_url('clientes/json_getcep/'); ?>' + encodeURIComponent(zipcode),
        dataType: 'json',
        success: function(data) {
          if (sessaoExpirou(data)) return;
          if (!data.return) return;
          if (data.logradouro) $('#rep_street').val(data.logradouro);
          if (data.bairro) $('#rep_district').val(data.bairro);
          if (data.id_state && parseInt(data.id_state, 10) > 0) {
            $('#rep_id_state').val(String(data.id_state)).trigger('change.select2');
            carregarCidadesEm($('#rep_id_city'), data.id_state, data.id_city);
          }
        }
      });
    });
  });
</script>
