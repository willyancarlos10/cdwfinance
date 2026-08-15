<!DOCTYPE html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="csrf-token-name" content="<?php echo $this->security->get_csrf_token_name(); ?>">
  <meta name="csrf-hash" content="<?php echo $this->security->get_csrf_hash(); ?>">
  <meta name="description" content="<?php echo $this->config->item('og_description'); ?>">
  <meta name="author" content="<?php echo $this->config->item('company'); ?>">
  <title><?php echo $this->config->item('title'); ?></title>
  <link rel="canonical" href="<?php echo current_url(); ?>" />
  <link rel="icon" href="<?php echo base_url('theme/custom/images/CDW-FAVICON-150x150.png'); ?>" sizes="32x32" />
  <link rel="icon" href="<?php echo base_url('theme/custom/images/CDW-FAVICON-300x300.png'); ?>" sizes="192x192" />
  <link rel="apple-touch-icon" href="<?php echo base_url('theme/custom/images/CDW-FAVICON-300x300.png'); ?>" />
  <meta name="msapplication-TileImage" content="<?php echo base_url('theme/custom/images/CDW-FAVICON-300x300.png'); ?>" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?php echo base_url('theme/css/light.css?v=2'); ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@mdi/font@7.4.47/css/materialdesignicons.min.css" />
  <link rel="stylesheet" href="<?php echo base_url('theme/custom/sweetalert2/sweetalert2.min.css'); ?>">
  <link rel="stylesheet" href="<?php echo base_url('theme/custom/css/custom.css?v=5'); ?>">

  <!-- TinyMCE injeta seu próprio CSS/skin via JS (carregado no footer). -->
  <?php if (($editor_choice ?? 'tinymce') === 'froala') : ?>
    <!-- Froala Editor (self-hosted) — carregado apenas quando a empresa usa o Froala -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.4.0/css/font-awesome.min.css">
    <link rel="stylesheet" href="<?php echo base_url('theme/froala/css/froala_editor.css'); ?>">
    <link rel="stylesheet" href="<?php echo base_url('theme/froala/css/froala_style.css'); ?>">
    <link rel="stylesheet" href="<?php echo base_url('theme/froala/css/plugins/code_view.css'); ?>">
    <link rel="stylesheet" href="<?php echo base_url('theme/froala/css/plugins/image_manager.css'); ?>">
    <link rel="stylesheet" href="<?php echo base_url('theme/froala/css/plugins/image.css'); ?>">
    <link rel="stylesheet" href="<?php echo base_url('theme/froala/css/plugins/table.css'); ?>">
    <link rel="stylesheet" href="<?php echo base_url('theme/froala/css/plugins/colors.css'); ?>">
    <link rel="stylesheet" href="<?php echo base_url('theme/froala/css/plugins/video.css'); ?>">
    <!-- Fonte do conteúdo só na área editável do Froala (não afeta o restante do painel).
       Usa !important + descendentes para vencer o "font-family ... !important" global do painel
       (theme/custom/css/custom.css), mas escopado a .fr-element, então nada fora do editor muda. -->
    <style>
      .fr-box .fr-element,
      .fr-box .fr-element * {
        font-family: Arial, Helvetica, sans-serif !important;
      }

      .fr-box .fr-element a {
        color: #3949ab;
      }

      .fr-box .fr-element a strong {
        color: #3949ab;
      }

      .fr-box .fr-element .font-weight-bold,
      .fr-box .fr-element b,
      .fr-box .fr-element strong {
        color: #000;
      }

      /* Oculta o aviso "Unlicensed" do Froala v2 (conteúdo hospedado localmente).
       IMPORTANTE: o Froala tem anti-adulteração — se o display do banner deixar de ser
       "block" ele remove o elemento e, após várias tentativas, DESTRÓI o editor.
       Por isso mantemos display:block e escondemos só por visibility/altura, sem tocar
       no display do banner nem do link interno. */
      .fr-box .fr-wrapper>div:first-child:not(.fr-element) {
        visibility: hidden !important;
        height: 0 !important;
        min-height: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        overflow: hidden !important;
      }
    </style>
  <?php endif; ?>

  <link rel="stylesheet" href="https://unpkg.com/dropzone@5/dist/min/dropzone.min.css" type="text/css" />

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
  <!-- Mensagens de Alerta -->
  <?php if ($this->session->flashdata('success') != null) { ?>
    <script>
      document.addEventListener("DOMContentLoaded", function() {
        var message = "<?php echo $this->session->flashdata('success'); ?>";
        var type = "success";
        var duration = 25000;
        var ripple = true;
        var dismissible = true;
        var positionX = "top";
        var positionY = "top";
        window.notyf.open({
          type,
          message,
          duration,
          ripple,
          dismissible,
          position: {
            x: positionX,
            y: positionY
          }
        });
      });
    </script>
  <?php } ?>
  <?php if ($this->session->flashdata('error') != null) { ?>
    <script>
      document.addEventListener("DOMContentLoaded", function() {
        Swal.fire({
          title: "Erro",
          html: "<?php echo $this->session->flashdata('error'); ?>",
          icon: "error"
        });
      });
    </script>
  <?php } ?>
  <?php if ($this->session->flashdata('warning') != null) { ?>
    <script>
      document.addEventListener("DOMContentLoaded", function() {
        Swal.fire({
          title: "Atenção",
          html: "<?php echo $this->session->flashdata('warning'); ?>",
          icon: "warning"
        });
      });
    </script>
  <?php } ?>
  <!-- Mensagens de Alerta -->
  <?php $this->load->view('menu', $this->data); ?>