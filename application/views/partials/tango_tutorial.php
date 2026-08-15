<?php
/**
 * Partial reutilizável: botão "Tutorial" + modal com vídeo Tango.
 *
 * Uso:
 *   $this->load->view('partials/tango_tutorial', [
 *     'tango_id'       => 'ID-DO-VIDEO-TANGO', // vazio = botão não aparece
 *     'tutorial_title' => 'Tutorial: Nome do módulo', // opcional
 *   ]);
 */
$tango_id = !empty($tango_id) ? trim($tango_id) : '';

if ($tango_id === '') {
  return; // Sem ID informado, não renderiza nada.
}

$tutorial_title = !empty($tutorial_title) ? $tutorial_title : 'Tutorial';
$tango_modal_id = 'modal-tango-' . substr(md5($tango_id), 0, 8);
$tango_embed_url = 'https://app.tango.us/app/embed/video/' . rawurlencode($tango_id) . '?narrationType=voice1';
?>
<button type="button" class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#<?php echo $tango_modal_id; ?>">
  <i class="mdi mdi-play-circle-outline"></i> Tutorial
</button>
<div class="modal" id="<?php echo $tango_modal_id; ?>" tabindex="-1" aria-labelledby="<?php echo $tango_modal_id; ?>-label" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="<?php echo $tango_modal_id; ?>-label">
          <i class="mdi mdi-play-circle-outline"></i> <?php echo htmlspecialchars($tutorial_title, ENT_QUOTES, 'UTF-8'); ?>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body p-0">
        <iframe data-src="<?php echo htmlspecialchars($tango_embed_url, ENT_QUOTES, 'UTF-8'); ?>"
                style="min-height:640px" sandbox="allow-scripts allow-same-origin allow-popups"
                title="<?php echo htmlspecialchars($tutorial_title, ENT_QUOTES, 'UTF-8'); ?>"
                width="100%" height="100%" frameborder="0" allow="autoplay" allowfullscreen></iframe>
      </div>
    </div>
  </div>
</div>
<script>
  document.addEventListener("DOMContentLoaded", function() {
    var tangoModal = document.getElementById("<?php echo $tango_modal_id; ?>");

    if (!tangoModal) {
      return;
    }

    var tangoIframe = tangoModal.querySelector("iframe[data-src]");

    // Carrega o vídeo apenas ao abrir o modal e descarrega ao fechar (para o áudio).
    tangoModal.addEventListener("show.bs.modal", function() {
      if (tangoIframe && !tangoIframe.getAttribute("src")) {
        tangoIframe.setAttribute("src", tangoIframe.getAttribute("data-src"));
      }
    });

    tangoModal.addEventListener("hidden.bs.modal", function() {
      if (tangoIframe) {
        tangoIframe.removeAttribute("src");
      }
    });
  });
</script>
