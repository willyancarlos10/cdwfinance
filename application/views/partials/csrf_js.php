<!-- CSRF: injeta o token do CodeIgniter em todo POST (formulários + AJAX jQuery) do painel
     e das telas de autenticação. Depende das metas csrf-token-name/csrf-hash no <head>
     (header.php no painel, partials/auth_head.php nas telas de login/cadastro).
     Endpoints de API/MCP e uploads estão isentos via csrf_exclude_uris. -->
<script>
    (function() {
        var nameMeta = document.querySelector('meta[name="csrf-token-name"]');
        var hashMeta = document.querySelector('meta[name="csrf-hash"]');
        if (!nameMeta || !hashMeta) return;

        var csrfName = nameMeta.getAttribute('content');
        var csrfHash = hashMeta.getAttribute('content');

        function ensureField(form) {
            if (!form || (form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
            var input = form.querySelector('input[name="' + csrfName + '"]');
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = csrfName;
                form.appendChild(input);
            }
            input.value = csrfHash;
        }

        // 1) Formulários POST já presentes na página.
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('form').forEach(ensureField);
        });

        // 2) Garante o token no submit (cobre formulários criados dinamicamente).
        document.addEventListener('submit', function(e) {
            ensureField(e.target);
        }, true);

        // 3) Requisições AJAX do jQuery (toggles, exclusões, filtros, etc.).
        if (window.jQuery) {
            jQuery.ajaxPrefilter(function(options) {
                var method = (options.type || options.method || 'GET').toUpperCase();
                if (method === 'GET' || method === 'HEAD') return;

                if (options.data instanceof FormData) {
                    if (!options.data.has(csrfName)) options.data.append(csrfName, csrfHash);
                    return;
                }

                var csrfPair = encodeURIComponent(csrfName) + '=' + encodeURIComponent(csrfHash);
                if (typeof options.data === 'string') {
                    options.data += (options.data ? '&' : '') + csrfPair;
                } else if (options.data && typeof options.data === 'object') {
                    // Objeto ainda não serializado (ex.: processData:false): serializa junto com o token.
                    options.data = jQuery.param(options.data) + '&' + csrfPair;
                } else {
                    // POST sem corpo (data ausente): envia o token como string. Definir um objeto aqui
                    // quebrava o jQuery ("data.replace is not a function"), pois o prefilter roda após
                    // a fase de serialização — era o motivo do modal de lead não abrir.
                    options.data = csrfPair;
                }
            });
        }
    })();
</script>
