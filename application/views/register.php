<!DOCTYPE html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="description" content="<?php echo $this->config->item('og_description'); ?>">
  <meta name="author" content="<?php echo $this->config->item('company'); ?>">
  <title><?php echo $this->config->item('title'); ?></title>
  <link rel="canonical" href="<?php echo current_url(); ?>" />
  <link rel="icon" href="<?php echo base_url('theme/custom/images/CDW-FAVICON-150x150.png'); ?>" sizes="32x32" />
  <link rel="icon" href="<?php echo base_url('theme/custom/images/CDW-FAVICON-300x300.png'); ?>" sizes="192x192" />
  <link rel="apple-touch-icon" href="<?php echo base_url('theme/custom/images/CDW-FAVICON-300x300.png'); ?>" />
  <meta name="msapplication-TileImage" content="<?php echo base_url('theme/custom/images/CDW-FAVICON-300x300.png'); ?>" />
  <?php $this->load->view('partials/auth_head'); ?>
  <!-- OG -->
  <meta property="og:url" content="<?php echo base_url(); ?>" />
  <meta property="og:type" content="website" />
  <meta property="og:title" content="<?php echo $this->config->item('title'); ?>" />
  <meta property="og:description" content="<?php echo $this->config->item('og_description'); ?>" />
  <meta property="og:image" content="<?php echo base_url() . 'theme/custom/images/logomarca.png'; ?>" />
  <meta property="og:image:alt" content="<?php echo $this->config->item('title'); ?>" />
  <meta property="og:image:type" content="image/png" />
  <meta property="og:image:width" content="500" />
  <meta property="og:image:height" content="500" />
  <!-- OG -->
</head>

<body data-theme="default" data-layout="fluid" data-sidebar-position="left" data-sidebar-behavior="sticky">
  <div class="min-vh-100 w-100 d-flex flex-column">
    <div class="row flex-grow-1 g-0 justify-content-center">
      <div class="col-12 col-lg-6 bg-white overflow-auto align-items-center">
        <div class="container p-4 pb-5">
          <div class="text-center">
            <a href="<?php echo base_url(); ?>">
              <img src="<?php echo base_url() . 'theme/custom/images/logomarca.png'; ?>" alt="<?php echo $this->config->item('company'); ?>" style="max-width:280px;" class="img-fluid w-100 mb-3 mt-2">
            </a>
          </div>
          <div class="row d-flex justify-content-center">
            <div class="col-12">
              <?php
              echo empty(validation_errors()) ? '' : '<div class="alert alert-danger alert-dismissible" role="alert"><button type="button" class="btn-close d-none" data-bs-dismiss="alert" aria-label="Close"></button><div class="alert-message">' . validation_errors() . '</div></div>';
              ?>
              <?php if ($this->session->flashdata('error') != null) { ?>
                <div class="alert alert-danger alert-dismissible" role="alert">
                  <button type="button" class="btn-close d-none" data-bs-dismiss="alert" aria-label="Close"></button>
                  <div class="alert-icon">
                    <i class="far fa-fw fa-bell"></i>
                  </div>
                  <div class="alert-message"><?php echo $this->session->flashdata('error'); ?></div>
                </div>
              <?php } ?>

              <?php if (!empty($_GET['error'])) { ?>
                <div class="alert alert-danger alert-dismissible" role="alert">
                  <button type="button" class="btn-close d-none" data-bs-dismiss="alert" aria-label="Close"></button>
                  <div class="alert-icon">
                    <i class="far fa-fw fa-bell"></i>
                  </div>
                  <div class="alert-message"><?php echo $_GET['error']; ?></div>
                </div>
              <?php } ?>

              <div class="text-center mb-4">
                <h3 class="mb-2">Cadastro de empresas</h3>
                <p class="text-muted mb-0">Preencha os dados abaixo para solicitar seu acesso.</p>
              </div>
              <form enctype="multipart/form-data" class="register-form" action="<?php echo base_url('login/cadastro'); ?>" method="POST">
                <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                <hr>
                <div class="row">
                  <div class="col-md-12">
                    <h4>Dados da empresa</h4>
                  </div>
                  <div class="col-md-12">
                    <div class="form-group text-center mb-3">
                      <div id="loading" style="display: none;">
                        <label></label><br />
                        <img src="<?php echo base_url('theme/custom/images/transfer.gif'); ?>" style="max-height: 30px;" /> Carregando, aguarde...
                      </div>
                    </div>
                  </div>
                  <div class="col-12 col-sm-6">
                    <div class="form-group mb-3">
                      <label for="cnpj">* CNPJ</label>
                      <div class="input-group">
                        <input type="text" name="company[cnpj]" class="form-control" id="cnpj" placeholder="00.000.000/0000-00" data-mask="00.000.000/0000-00" data-mask-reverse="TRUE" required value="<?php echo set_value('company[cnpj]', $cnpj); ?>">
                        <button type="button" class="btn btn-outline-primary" id="btn_buscar_cnpj" title="Buscar dados do CNPJ">
                          <i class="mdi mdi-magnify"></i>
                        </button>
                      </div>
                      <small class="text-muted">Ao sair do campo, os dados da empresa serão preenchidos automaticamente.</small>
                    </div>
                  </div>
                  <div class="col-12 col-sm-6">
                    <div class="form-group mb-3">
                      <label for="name">* Razão Social</label>
                      <input class="form-control" type="text" name="company[name]" id="name" required="" value="<?php echo set_value('company[name]'); ?>" placeholder="* Razão Social" maxlength="150">
                    </div>
                  </div>
                  <div class="col-12 col-sm-6">
                    <div class="form-group mb-3">
                      <label for="byname">* Nome Fantasia</label>
                      <input class="form-control" type="text" name="company[byname]" id="byname" required="" value="<?php echo set_value('company[byname]'); ?>" placeholder="* Nome Fantasia" maxlength="100">
                    </div>
                  </div>
                  <div class="col-md-12">
                    <hr>
                  </div>
                  <div class="col-md-12">
                    <h4>Dados do contato </h4>
                  </div>
                  <div class="col-12 col-sm-6">
                    <div class="form-group mb-3">
                      <label for="owner">* Nome</label>
                      <input class="form-control" type="text" name="company[owner]" id="owner" data-mask-reverse="TRUE" required="" value="<?php echo set_value('company[owner]'); ?>" placeholder="* Nome" maxlength="100">
                    </div>
                  </div>
                  <div class="col-12 col-sm-6">
                    <div class="form-group mb-3">
                      <label for="owner_cellphone">* Telefone celular para contato</label>
                      <input class="form-control phonemask" type="text" required="" name="company[owner_cellphone]" id="owner_cellphone" value="<?php echo set_value('company[owner_cellphone]'); ?>" placeholder="* Telefone celular">
                    </div>
                  </div>
                  <div class="col-12 col-sm-6">
                    <div class="form-group mb-3">
                      <label for="phone">Telefone fixo</label>
                      <input class="form-control" type="text" name="company[phone]" id="phone" data-mask="(00) 0000-0000" minlength="10" value="<?php echo set_value('company[phone]'); ?>" placeholder="Telefone fixo">
                    </div>
                  </div>
                  <div class="col-md-12">
                    <hr>
                  </div>
                  <div class="col-md-12">
                    <h4>Localização da empresa</h4>
                  </div>
                  <div class="col-12 col-sm-6">
                    <div class="form-group mb-3">
                      <label for="address_zip">* CEP</label>
                      <input class="form-control" type="text" name="company[address_zip]" id="address_zip" data-mask="00.000-000" data-mask-reverse="TRUE" required="" value="<?php echo set_value('company[address_zip]'); ?>" placeholder="* CEP">
                    </div>
                  </div>
                  <div class="col-12 col-sm-6">
                    <div class="form-group mb-3">
                      <label for="address">* Endereço</label>
                      <input class="form-control" type="text" name="company[address]" id="address" required value="<?php echo set_value('company[address]'); ?>" placeholder="* Endereço">
                    </div>
                  </div>
                  <div class="col-12 col-sm-6">
                    <div class="form-group mb-3">
                      <label for="address_number">* Número</label>
                      <input class="form-control" type="text" name="company[address_number]" id="address_number" required value="<?php echo set_value('company[address_number]'); ?>" placeholder="* Número">
                    </div>
                  </div>
                  <div class="col-12 col-sm-6">
                    <div class="form-group mb-3">
                      <label for="address_district">* Bairro</label>
                      <input required class="form-control" type="text" name="company[address_district]" id="address_district" value="<?php echo set_value('company[address_district]'); ?>" placeholder="* Bairro">
                    </div>
                  </div>
                  <div class="col-12 col-sm-6">
                    <div class="form-group mb-3">
                      <label for="address_complement">Complemento</label>
                      <input class="form-control" type="text" name="company[address_complement]" id="address_complement" value="<?php echo set_value('company[address_complement]'); ?>" placeholder="Complemento">
                    </div>
                  </div>
                  <div class="col-12 col-sm-6">
                    <div class="form-group mb-3">
                      <label for="address_uf">Estado</label>
                      <input class="form-control" required type="text" maxlength="2" name="company[address_uf]" id="address_uf" value="<?php echo set_value('company[address_uf]'); ?>" placeholder="Estado">
                    </div>
                  </div>
                  <div class="col-12 col-sm-6">
                    <div class="form-group mb-3">
                      <label for="address_city">Cidade</label>
                      <input class="form-control" required type="text" name="company[address_city]" id="address_city" value="<?php echo set_value('company[address_city]'); ?>" placeholder="Cidade">
                    </div>
                  </div>
                  <div class="col-md-12">
                    <hr>
                    <h4>Login de acesso</h4>
                  </div>
                  <div class="col-md-12"></div>
                  <div class="col-12 col-sm-6">
                    <div class="form-group mb-3">
                      <label for="email">* Seu e-mail</label>
                      <input type="email" name="email" id="email" class="form-control" required value="<?php echo set_value('email'); ?>" placeholder="* Seu e-mail">
                    </div>
                  </div>
                  <div class="col-12 col-sm-6">
                    <div class="form-group mb-3">
                      <label for="email2">* Confirmar e-mail</label>
                      <input type="email" name="email2" id="email2" class="form-control" required value="<?php echo set_value('email2'); ?>" placeholder="* Confirmar e-mail">
                    </div>
                  </div>
                  <div class="col-12 col-sm-6">
                    <div class="form-group mb-3">
                      <label for="passw1">* Senha</label>
                      <input type="password" name="passw1" id="passw1" required class="form-control" placeholder="* Senha">
                      <span class="font-13 text-danger"><small>A senha deverá possuir 8 dígitos com letras e números</small></span>
                    </div>
                  </div>
                  <div class="col-12 col-sm-6">
                    <div class="form-group mb-3">
                      <label for="passw2">* Confirmar senha</label>
                      <input type="password" name="passw2" id="passw2" required class="form-control" placeholder="* Confirmar senha">
                      <span class="font-13 text-danger"><small>A senha deverá possuir 8 dígitos com letras e números</small></span>
                    </div>
                  </div>
                  <div class="col-md-12">
                    <div class="form-group text-center mt-3">
                      <button id="salvar" type="submit" class="btn btn-primary w-100 btn-lg"><i class="mdi mdi-content-save"></i> ENVIAR CADASTRO</button>
                    </div>
                  </div>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="<?php echo base_url('theme/js/app.js'); ?>"></script>
  <script src="<?php echo base_url('theme/custom/sweetalert2/sweetalert2.all.min.js'); ?>"></script>
  <?php $this->load->view('partials/csrf_js'); ?>
  <?php $this->load->view('partials/form_saving_js'); ?>
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
      $('.select2').select2();

      $(window).keydown(function(event) {
        if (event.keyCode == 13) {
          event.preventDefault();
          return false;
        }
      });

      <?php if (!empty($cnpj)) { ?>
        buscarCnpj();
      <?php } ?>

      function preencherFormularioEmpresa(message) {
        if (!message) {
          return;
        }

        $('#name').val(message.name || '');
        $('#byname').val(message.byname || message.name || '');
        $('#phone').val(message.phone || '');

        $('#address_zip').unmask();
        $('#address_zip').val(message.address_zip || '');
        $('#address_zip').mask('00.000-000');

        $('#address').val(message.address || '');
        $('#address_number').val(message.address_number || '');
        $('#address_district').val(message.address_district || '');
        $('#address_complement').val(message.address_complement || '');
        $('#address_uf').val(message.address_uf || '');
        $('#address_city').val(message.address_city || '');

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
          if (socio) {
            $('#owner').val(socio);
          }
        }

        if (message.email) {
          $('#email').val(message.email);
        }

        $('#name').trigger('focus');
      }

      function buscarCnpj() {
        var cnpj = $('#cnpj').val().replace(/\D/g, '');
        if (cnpj.length !== 14) {
          Swal.fire('Atenção', 'Informe um CNPJ completo com 14 dígitos.', 'warning');
          return;
        }

        $("#loading").show();
        $("#btn_buscar_cnpj").prop('disabled', true);

        $.ajax({
          type: "POST",
          data: "",
          dataType: 'json',
          url: "<?php echo base_url('login/json_getsefaz/'); ?>" + cnpj,
          success: function(data) {
            if (data && data.return) {
              preencherFormularioEmpresa(data.message);
              return;
            }

            Swal.fire('Ops', (data && data.message) ? data.message : 'Não foi possível consultar o CNPJ informado.', 'warning');
          },
          error: function() {
            Swal.fire('Erro', 'Não foi possível consultar o CNPJ. Tente novamente em instantes.', 'error');
          }
        }).always(function() {
          $("#loading").hide();
          $("#btn_buscar_cnpj").prop('disabled', false);
        });
      }

      $('#btn_buscar_cnpj').on('click', function() {
        buscarCnpj();
      });

      $('#cnpj').on('blur', function() {
        var cnpj = $(this).val().replace(/\D/g, '');
        if (cnpj.length === 14) {
          buscarCnpj();
        }
      });

      $('#address_zip').blur(function() {
        var zipcode = $('#address_zip').val();
        if (zipcode) {
          $.ajax({
            type: "POST",
            data: "",
            url: "<?php echo base_url('login/json_getcep/'); ?>" + encodeURIComponent(zipcode),
            dataType: 'json',
            success: function(data) {
              if (data.return) {
                $("#address").val(data.logradouro);
                $("#address_district").val(data.bairro);
                $("#address_city").val(data.localidade);
                $("#address_uf").val(data.uf);
                $("#address_number").focus();

                if (!data.logradouro) {
                  $("#address").removeAttr("readonly");
                  $("#address_number").removeAttr("readonly");
                  $("#address_district").removeAttr("readonly");
                }
              } else {
                $("#address").val("");
                $("#address_district").val("");
                $("#address_complement").val("");
                $("#address_city").val("");
                $("#address_uf").val("");
              }
            },
            error: function() {
              Swal.fire('Erro', 'Não foi possível consultar o CEP informado.', 'error');
            }
          });
        }
      });
    });
  </script>
</body>

</html>