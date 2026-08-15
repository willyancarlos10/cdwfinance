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
  <div class="h-100 w-100 d-flex flex-column">
    <div class="row h-100">
      <div class="h-100 col-xxl-6 col-xl-6 col-lg-6 mx-auto d-flex bg-white position-relative">
        <!-- my-auto no item flex (e não align-items-center na coluna): centraliza na vertical
             enquanto sobra espaço e, em tela baixa, joga o excedente só para baixo, mantendo
             o topo do formulário alcançável pela rolagem. -->
        <div class="w-100 my-auto py-4">
          <div class="text-center">
            <a href="<?php echo base_url() ?>">
              <img src="<?php echo base_url() . 'theme/custom/images/logomarca.png'; ?>" alt="" style="max-width:280px;" class="img-fluid w-100 mb-3 mt-4">
            </a>
          </div>
          <div class="container p-4">
            <div class="row d-flex justify-content-center">
              <div class="col-12 col-sm-8">
                <?php if ($this->input->get('error')) : ?>
                  <div class="alert alert-danger alert-dismissible" role="alert">
                    <button type="button" class="btn-close d-none" data-bs-dismiss="alert" aria-label="Close"></button>
                    <div class="alert-icon">
                      <i class="far fa-fw fa-bell"></i>
                    </div>
                    <div class="alert-message">
                      <?php echo html_escape($this->input->get('error')) ?>
                    </div>
                  </div>
                <?php endif; ?>

                <?php if ($this->input->get('warning')) : ?>
                  <div class="alert alert-warning alert-dismissible" role="alert">
                    <button type="button" class="btn-close d-none" data-bs-dismiss="alert" aria-label="Close"></button>
                    <div class="alert-icon">
                      <i class="far fa-fw fa-bell"></i>
                    </div>
                    <div class="alert-message text-center">
                      <?php echo html_escape($this->input->get('warning')); ?>
                    </div>
                  </div>
                <?php endif; ?>

                <?php if ($this->input->get('success')) : ?>
                  <div class="alert alert-primary alert-dismissible" role="alert">
                    <button type="button" class="btn-close d-none" data-bs-dismiss="alert" aria-label="Close"></button>
                    <div class="alert-icon">
                      <i class="far fa-fw fa-bell"></i>
                    </div>
                    <div class="alert-message">
                      <?php echo html_escape($this->input->get('success')); ?>
                    </div>
                  </div>
                <?php endif; ?>

                <h3 class="text-center mb-2 mt-2">Olá, seja bem vindo!</h3>

                <?php if ($this->config->item('homologation')) { ?>
                  <div class="text-center"><span class="fs-4 badge bg-danger">AMBIENTE DE HOMOLOGAÇÃO</span></div>
                <?php } ?>
                <?php echo form_open_multipart(base_url() . 'login/entrar', array('method' => 'POST')); ?>
                <div class="input-group mb-3">
                  <span class="input-group-text"><i class="mdi mdi-email fs-4"></i></span>
                  <input type="email" name="myemail" class="form-control form-control-lg" placeholder="Digite seu endereço de e-mail" value="<?php echo set_value('myemail') ?>" required>
                </div>
                <div class="input-group mb-3">
                  <span class="input-group-text"><i class="mdi mdi-key-variant fs-4"></i></span>
                  <input type="password" name="mypass" id="mypass" class="form-control form-control-lg" placeholder="Digite sua senha" value="<?php echo set_value('mypass') ?>" required>
                  <button type="button" class="btn btn-outline-secondary" id="btn_toggle_mypass" title="Ver senha" aria-label="Ver senha">
                    <i class="mdi mdi-eye"></i>
                  </button>
                </div>
                <div class="form-group my-0">
                  <div class="w-100">
                    <a class="float-end" href="<?php echo base_url() . 'login/esqueceu' ?>">Esqueci minha senha</a>
                  </div>
                </div>
                <div class="form-group pt-3 mb-2">
                  <br />
                  <button type="submit" name="button" class="btn btn-primary w-100 btn-lg"><i class="mdi mdi-login"></i> ENTRAR</button>
                </div>



                <?php echo form_close(); ?>
                <hr>

                <a class="float-end mt-1" href="<?php echo base_url() . 'cadastro-cliente' ?>">Cliente? Faça seu cadastro</a>

              </div>
            </div>
          </div>
        </div>
      </div>
      <!-- A visibilidade acompanha o breakpoint das colunas (lg): abaixo dele as duas
           ocupam a linha inteira e a imagem cairia embaixo do formulário. -->
      <div class="h-100 col-xxl-6 col-xl-6 col-lg-6 d-none d-lg-flex p-0 overflow-hidden">
        <!-- A imagem é retrato (640x960) e a coluna é panorâmica: object-fit-cover preenche
             o espaço inteiro sem distorcer, e object-position-top ancora o corte no topo
             (o padrão do cover é o centro, que comia a logomarca da imagem). -->
        <img src="<?php echo base_url('theme/custom/images/login.jpg'); ?>" alt="" class="d-block w-100 h-100 object-fit-cover object-position-top">
      </div>
    </div>
  </div>

  <script src="<?php echo base_url('theme/js/app.js'); ?>"></script>
  <script>
    $('#btn_toggle_mypass').on('click', function() {
      var $input = $('#mypass');
      var $icon = $(this).find('i');
      var isHidden = $input.attr('type') === 'password';
      $input.attr('type', isHidden ? 'text' : 'password');
      $icon.toggleClass('mdi-eye', !isHidden).toggleClass('mdi-eye-off', isHidden);
      $(this).attr('title', isHidden ? 'Ocultar senha' : 'Ver senha')
        .attr('aria-label', isHidden ? 'Ocultar senha' : 'Ver senha');
    });
  </script>
  <?php $this->load->view('partials/form_saving_js'); ?>
</body>

</html>