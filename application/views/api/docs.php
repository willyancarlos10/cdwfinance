<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>API Docs | <?php echo $this->config->item('title'); ?></title>
  <link rel="icon" href="<?php echo base_url('theme/custom/images/CDW-FAVICON-150x150.png'); ?>">
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui.css">
  <style>
    body {
      margin: 0;
      background: #fafafa;
    }

    /* A barra de topo do Swagger só serve para trocar a URL da spec. */
    .swagger-ui .topbar {
      display: none;
    }

    .cdw-header {
      background: #23272f;
      color: #fff;
      padding: 14px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px;
    }

    .cdw-header a {
      color: #fff;
      text-decoration: none;
      font-size: 14px;
      border: 1px solid rgba(255, 255, 255, .35);
      border-radius: 4px;
      padding: 6px 12px;
    }

    .cdw-header a:hover {
      background: rgba(255, 255, 255, .12);
    }

    .cdw-header strong {
      font-size: 16px;
    }

    .cdw-falha {
      display: none;
      margin: 24px auto;
      max-width: 780px;
      padding: 16px 20px;
      border: 1px solid #f0c36d;
      background: #fdf6e3;
      border-radius: 6px;
      font-family: sans-serif;
      font-size: 14px;
      color: #6b5900;
      line-height: 1.5;
    }
  </style>
</head>

<body>
  <div class="cdw-header">
    <strong>CDW Finance — API pública</strong>
    <a href="<?php echo base_url(); ?>">&larr; Voltar ao painel</a>
  </div>

  <div class="cdw-falha" id="cdw_falha">
    <strong>Não foi possível carregar o Swagger UI.</strong><br>
    Os arquivos da interface vêm de <code>unpkg.com</code>. Verifique a conexão
    do servidor com a internet ou baixe o
    <code>swagger-ui-dist</code> para <code>theme/</code> e aponte os dois
    <code>&lt;link&gt;</code>/<code>&lt;script&gt;</code> desta view para o caminho local.
    A especificação continua acessível em
    <a href="<?php echo base_url('api/docs/openapi.yaml'); ?>"><?php echo base_url('api/docs/openapi.yaml'); ?></a>.
  </div>

  <div id="swagger-ui"></div>

  <script src="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui-bundle.js" crossorigin></script>
  <script src="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui-standalone-preset.js" crossorigin></script>
  <script>
    window.addEventListener('load', function() {
      // Sem o bundle na página, o SwaggerUIBundle não existe e o #swagger-ui
      // ficaria em branco sem explicação nenhuma.
      if (typeof SwaggerUIBundle === 'undefined') {
        document.getElementById('cdw_falha').style.display = 'block';
        return;
      }

      SwaggerUIBundle({
        url: '<?php echo base_url('api/docs/openapi.yaml'); ?>',
        dom_id: '#swagger-ui',
        deepLinking: true,
        presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
        layout: 'BaseLayout',
        docExpansion: 'list',
        defaultModelsExpandDepth: 1,
        persistAuthorization: true
      });
    });
  </script>
</body>

</html>
