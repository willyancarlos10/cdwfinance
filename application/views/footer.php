</div>
</main>
<footer class="footer py-2">
    <div class="container-fluid">
        <div class="row text-muted">
            <div class="col-12 col-sm-6 text-center text-sm-start mb-0 mb-sm-2"></div>
            <div class="col-12 col-sm-6 text-center text-sm-end">
                <p class="mb-0">&copy; <?php echo date('Y'); ?> - <a href="javascript:;" class="text-muted"><?php echo $this->config->item('title'); ?></a></p>
            </div>
        </div>
    </div>
</footer>
</div>
</div>

<!-- loading -->
<div class="modal" id="modal_loading" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="staticBackdropLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-md" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">CARREGANDO</h5>
                <button type="button" class="btn-close d-none" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body m-1" style="min-height: 0 !important;">
                <div class="row">
                    <div class="col-12 text-center">
                        <img class="" src="<?php echo base_url('theme/custom/images/transfer.gif'); ?>" style="width: 80px"><br />
                        <strong class="font-red mt-2">Aguarde até o final do processamento, não feche essa janela.</strong>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <div class="row w-100">
                    <div class="col"></div>
                    <div class="col"></div>
                    <div class="col"><button type="button" disabled class="btn w-100 btn-outline-secondary" data-dismiss="modal"><i class="fa fa-times"></i> FECHAR</button></div>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- loading -->

<script src="<?php echo base_url('theme/js/app.js'); ?>"></script>
<script src="<?php echo base_url('theme/custom/maskmoney/jquery.mask.min.js'); ?>"></script>
<script src="<?php echo base_url('theme/custom/maskmoney/jquery.maskMoney.js'); ?>"></script>
<script src="<?php echo base_url('theme/custom/repeater/jquery.repeater.js'); ?>"></script>
<script src="<?php echo base_url('theme/custom/repeater/form-repeater.min.js'); ?>"></script>
<script src="<?php echo base_url('theme/custom/sweetalert2/sweetalert2.all.min.js'); ?>"></script>

<script src="https://unpkg.com/dropzone@5/dist/min/dropzone.min.js"></script>

<!-- TinyMCE (self-hosted / Community GPL) — só quando a empresa NÃO usa o Froala.
     Sem este asset, o bloco "if (typeof tinymce !== 'undefined')" abaixo se auto-desativa. -->
<?php if (($editor_choice ?? 'tinymce') !== 'froala') : ?>
    <script type="text/javascript" src="<?php echo base_url('theme/tinymce/tinymce.min.js'); ?>"></script>
<?php endif; ?>

<script>
    // Diagnóstico de upload: devolve ao servidor o corpo bruto que o cliente não
    // conseguiu interpretar. Sem isso o log só mostra o que o PHP ACHA que enviou —
    // e a falha "não foi possível interpretar a resposta" acontece justamente
    // quando o corpo que chega ao navegador é diferente do que o PHP gerou.
    var uploadDiagUrl = '<?php echo base_url('editor/upload-diag'); ?>';

    // Froala e Dropzone não entregam o XHR no callback de erro: sem o status HTTP
    // não dá para distinguir "200 com corpo sujo" de "500 devolvendo HTML".
    // Este gancho guarda os metadados da última resposta de upload — apenas para
    // as URLs de upload do painel — e o log_id gerado pelo servidor.
    window.ultimoUploadLogId = '';
    window.ultimaRespostaUpload = null;
    (function() {
        var abrirOriginal = XMLHttpRequest.prototype.open;
        var enviarOriginal = XMLHttpRequest.prototype.send;

        function ehUrlDeUpload(url) {
            url = String(url || '');
            return url.indexOf('editor/upload') !== -1 || url.indexOf('/upload_') !== -1;
        }

        XMLHttpRequest.prototype.open = function(metodo, url) {
            this.__urlUpload = ehUrlDeUpload(url) && url.indexOf('upload-diag') === -1 ? url : null;
            return abrirOriginal.apply(this, arguments);
        };

        XMLHttpRequest.prototype.send = function() {
            var xhr = this;
            if (xhr.__urlUpload) {
                // Zera antes de enviar: sem isso, uma falha de rede (que não gera
                // resposta) herdaria os dados do upload anterior no log.
                window.ultimoUploadLogId = '';
                window.ultimaRespostaUpload = {
                    url: xhr.__urlUpload,
                    status: 0,
                    contentType: '',
                    corpo: '',
                    evento: 'sem resposta (requisição não completou)'
                };

                var registrar = function(evento) {
                    return function() {
                        try {
                            window.ultimoUploadLogId = xhr.getResponseHeader('X-Upload-Log-Id') || '';
                            window.ultimaRespostaUpload = {
                                url: xhr.__urlUpload,
                                status: xhr.status,
                                contentType: xhr.getResponseHeader('Content-Type') || '',
                                corpo: typeof xhr.responseText === 'string' ? xhr.responseText : '',
                                evento: evento
                            };
                        } catch (e) {}
                    };
                };

                // O Froala usa o mesmo código de erro (4) para "JSON inválido" e
                // para "a requisição nem completou" (onerror). Registrar os dois
                // eventos é o que separa as duas causas no log.
                xhr.addEventListener('load', registrar('load'));
                xhr.addEventListener('error', registrar('erro de rede'));
                xhr.addEventListener('timeout', registrar('timeout'));
                xhr.addEventListener('abort', registrar('cancelado'));
            }
            return enviarOriginal.apply(this, arguments);
        };
    })();

    function reportarFalhaUpload(info) {
        try {
            info = info || {};
            var ultima = window.ultimaRespostaUpload || {};
            // O callback do editor entrega só o corpo; status e content-type vêm do gancho de XHR.
            var corpo = typeof info.corpo === 'string' ? info.corpo : (ultima.corpo || '');
            if (typeof info.corpo === 'object' && info.corpo !== null) {
                corpo = JSON.stringify(info.corpo);
            }

            var dados = new FormData();
            dados.append('origem', info.origem || '');
            dados.append('log_id', info.logId || window.ultimoUploadLogId || '');
            dados.append('codigo_erro', info.codigoErro === undefined || info.codigoErro === null ? '' : String(info.codigoErro));
            dados.append('http_status', String(info.status !== undefined && info.status !== null ? info.status : (ultima.status !== undefined ? ultima.status : '')));
            dados.append('content_type', info.contentType || ultima.contentType || '');
            dados.append('evento_xhr', ultima.evento || '');
            dados.append('arquivo', info.arquivo || '');
            dados.append('url', window.location.href);
            dados.append('corpo', String(corpo).substring(0, 8000));

            // Token de CSRF pelas metas do header: este XHR não passa pelo
            // ajaxPrefilter do jQuery, e config.php (csrf_exclude_uris) não é
            // versionado — assim o diagnóstico funciona sem depender do ambiente.
            var nomeCsrf = document.querySelector('meta[name="csrf-token-name"]');
            var hashCsrf = document.querySelector('meta[name="csrf-hash"]');
            if (nomeCsrf && hashCsrf) {
                dados.append(nomeCsrf.getAttribute('content'), hashCsrf.getAttribute('content'));
            }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', uploadDiagUrl, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.send(dados);
        } catch (e) {
            // O diagnóstico nunca pode atrapalhar a mensagem de erro na tela.
        }
    }

    // Sessão expirada durante um upload do editor: o servidor responde
    // {"redirect":true,...} com status 200 (mesmo contrato das demais telas do painel).
    function redirecionarSessaoExpirada(data) {
        var msg = (data && data.message) || 'Sua sessão expirou. Faça login novamente.';
        var destino = (data && data.login_url) || '<?php echo base_url('login?warning=Sua sessão expirou.'); ?>';

        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'warning',
                title: 'Sessão expirada',
                text: msg
            }).then(function() {
                window.location.replace(destino);
            });
        } else {
            alert(msg);
            window.location.replace(destino);
        }
    }

    document.addEventListener("DOMContentLoaded", function() {
        if (typeof tinymce !== "undefined") {
            var editorUploadUrl = '<?php echo base_url('editor/upload'); ?>';
            var visibleHeightResizeTimers = {};
            var getEditorIntegerOption = function(element, optionName, defaultValue) {
                var value = parseInt(element.getAttribute(optionName), 10);
                return isNaN(value) ? defaultValue : value;
            };
            var resizeEditorToVisibleHeight = function(editor) {
                var element = editor.getElement();
                if (!element || element.getAttribute('data-visible-height') !== 'true') {
                    return;
                }

                var container = editor.getContainer();
                if (!container) {
                    return;
                }

                var minHeight = getEditorIntegerOption(element, 'data-visible-height-min', 360);
                var bottomSpacing = getEditorIntegerOption(element, 'data-visible-height-bottom', 24);
                var viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
                var containerTop = container.getBoundingClientRect().top;
                var availableHeight = Math.floor(viewportHeight - containerTop - bottomSpacing);
                var nextHeight = Math.max(minHeight, availableHeight);

                container.style.height = nextHeight + 'px';
                container.style.minHeight = minHeight + 'px';
                editor.dispatch('ResizeEditor');
            };
            var scheduleVisibleHeightResize = function(editor) {
                clearTimeout(visibleHeightResizeTimers[editor.id]);
                visibleHeightResizeTimers[editor.id] = setTimeout(function() {
                    resizeEditorToVisibleHeight(editor);
                }, 80);
            };
            tinymce.init({
                selector: '.wysiwyg',
                license_key: 'gpl',
                language: 'pt_BR',
                language_url: '<?php echo base_url('theme/tinymce/langs/pt_BR.js'); ?>',
                base_url: '<?php echo base_url('theme/tinymce'); ?>',
                suffix: '.min',
                height: 400,
                resize: true,
                menubar: false,
                branding: false,
                promotion: false,
                relative_urls: false,
                remove_script_host: false,
                plugins: 'advlist autolink lists link image table code fullscreen media ' +
                    'searchreplace wordcount charmap preview pagebreak quickbars',
                toolbar: 'undo redo | blocks fontsize | ' +
                    'bold italic underline strikethrough forecolor backcolor removeformat | ' +
                    'alignleft aligncenter alignright alignjustify | lineheight | ' +
                    'bullist numlist outdent indent | blockquote link image media table charmap | ' +
                    'searchreplace preview code fullscreen',
                toolbar_mode: 'wrap',
                toolbar_sticky: true,
                contextmenu: 'recortar colar colarsemformatacao | link image table',
                setup: function(editor) {
                    editor.ui.registry.addMenuItem('recortar', {
                        text: 'Recortar',
                        icon: 'cut',
                        onAction: function() {
                            var html = editor.selection.getContent({
                                format: 'html'
                            });
                            var text = editor.selection.getContent({
                                format: 'text'
                            });
                            if (!html) {
                                editor.notificationManager.open({
                                    text: 'Selecione um texto para recortar.',
                                    type: 'info',
                                    timeout: 3000
                                });
                                return;
                            }
                            var remover = function() {
                                editor.selection.setContent('');
                            };
                            if (navigator.clipboard && navigator.clipboard.write && window.ClipboardItem) {
                                var item = new ClipboardItem({
                                    'text/html': new Blob([html], {
                                        type: 'text/html'
                                    }),
                                    'text/plain': new Blob([text], {
                                        type: 'text/plain'
                                    })
                                });
                                navigator.clipboard.write([item]).then(remover).catch(function() {
                                    // Fallback para o comando nativo do navegador
                                    if (!editor.execCommand('Cut')) {
                                        editor.notificationManager.open({
                                            text: 'Permissão negada. Use Ctrl+X para recortar.',
                                            type: 'warning',
                                            timeout: 4000
                                        });
                                    }
                                });
                            } else if (!editor.execCommand('Cut')) {
                                editor.notificationManager.open({
                                    text: 'Use Ctrl+X para recortar.',
                                    type: 'warning',
                                    timeout: 4000
                                });
                            }
                        }
                    });
                    // Lê a área de transferência e devolve o texto puro
                    var lerTextoPuro = function() {
                        if (navigator.clipboard && navigator.clipboard.readText) {
                            return navigator.clipboard.readText();
                        }
                        return Promise.reject();
                    };
                    editor.ui.registry.addMenuItem('colarsemformatacao', {
                        text: 'Colar sem formatação',
                        icon: 'paste-text',
                        onAction: function() {
                            lerTextoPuro().then(function(text) {
                                if (text) {
                                    editor.insertContent(editor.dom.encode(text));
                                } else {
                                    editor.notificationManager.open({
                                        text: 'Nada de texto para colar.',
                                        type: 'info',
                                        timeout: 3000
                                    });
                                }
                            }).catch(function() {
                                editor.notificationManager.open({
                                    text: 'Permissão negada. Use Ctrl+Shift+V para colar sem formatação.',
                                    type: 'warning',
                                    timeout: 4000
                                });
                            });
                        }
                    });
                    editor.ui.registry.addMenuItem('colar', {
                        text: 'Colar',
                        icon: 'paste',
                        onAction: function() {
                            // Clipboard API funciona em contexto seguro (https ou localhost)
                            if (!navigator.clipboard || !navigator.clipboard.read) {
                                editor.notificationManager.open({
                                    text: 'Use Ctrl+V para colar (navegador não permite colar pelo menu).',
                                    type: 'warning',
                                    timeout: 4000
                                });
                                return;
                            }
                            navigator.clipboard.read().then(function(items) {
                                var handled = false;
                                var pending = items.map(function(item) {
                                    if (item.types.indexOf('text/html') !== -1) {
                                        handled = true;
                                        return item.getType('text/html').then(function(blob) {
                                            return blob.text();
                                        }).then(function(html) {
                                            editor.insertContent(html);
                                        });
                                    }
                                    if (item.types.indexOf('text/plain') !== -1) {
                                        handled = true;
                                        return item.getType('text/plain').then(function(blob) {
                                            return blob.text();
                                        }).then(function(text) {
                                            editor.insertContent(editor.dom.encode(text));
                                        });
                                    }
                                    return Promise.resolve();
                                });
                                return Promise.all(pending).then(function() {
                                    if (!handled) {
                                        editor.notificationManager.open({
                                            text: 'Nada de texto para colar.',
                                            type: 'info',
                                            timeout: 3000
                                        });
                                    }
                                });
                            }).catch(function() {
                                editor.notificationManager.open({
                                    text: 'Permissão negada. Use Ctrl+V para colar.',
                                    type: 'warning',
                                    timeout: 4000
                                });
                            });
                        }
                    });
                },
                // Upload direto para a hospedagem (FTP da empresa ativa)
                automatic_uploads: true,
                images_upload_url: editorUploadUrl,
                images_upload_handler: function(blobInfo, progress) {
                    return new Promise(function(resolve, reject) {
                        var xhr = new XMLHttpRequest();
                        xhr.open('POST', editorUploadUrl);
                        // Sem esse header o CI não trata a requisição como AJAX e, com a
                        // sessão expirada, devolve o HTML da tela de login com status 200.
                        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                        xhr.upload.onprogress = function(e) {
                            if (e.lengthComputable) progress((e.loaded / e.total) * 100);
                        };
                        xhr.onload = function() {
                            var logId = xhr.getResponseHeader('X-Upload-Log-Id') || '';
                            var contentType = xhr.getResponseHeader('Content-Type') || '';

                            if (xhr.status < 200 || xhr.status >= 300) {
                                reportarFalhaUpload({
                                    origem: 'tinymce',
                                    codigoErro: 'http',
                                    status: xhr.status,
                                    contentType: contentType,
                                    corpo: xhr.responseText,
                                    arquivo: blobInfo.filename(),
                                    logId: logId
                                });
                                reject({
                                    message: 'Falha no upload: ' + xhr.status,
                                    remove: true
                                });
                                return;
                            }
                            var json;
                            try {
                                json = JSON.parse(xhr.responseText);
                            } catch (err) {
                                reportarFalhaUpload({
                                    origem: 'tinymce',
                                    codigoErro: 'json_parse',
                                    status: xhr.status,
                                    contentType: contentType,
                                    corpo: xhr.responseText,
                                    arquivo: blobInfo.filename(),
                                    logId: logId
                                });
                                reject({
                                    message: 'Resposta inválida do servidor.'
                                });
                                return;
                            }
                            if (json && json.redirect) {
                                redirecionarSessaoExpirada(json);
                                reject({
                                    message: json.message || 'Sua sessão expirou. Faça login novamente.'
                                });
                                return;
                            }
                            var url = json.location || json.link || (json.data && json.data.url);
                            if (!url) {
                                reportarFalhaUpload({
                                    origem: 'tinymce',
                                    codigoErro: 'sem_url',
                                    status: xhr.status,
                                    contentType: contentType,
                                    corpo: xhr.responseText,
                                    arquivo: blobInfo.filename(),
                                    logId: logId
                                });
                                reject({
                                    message: (json && json.message) || 'Resposta inválida do servidor.'
                                });
                                return;
                            }
                            resolve(url);
                        };
                        xhr.onerror = function() {
                            reject({
                                message: 'Erro de rede no upload.'
                            });
                        };
                        var fd = new FormData();
                        fd.append('file', blobInfo.blob(), blobInfo.filename());
                        xhr.send(fd);
                    });
                },
                init_instance_callback: function(editor) {
                    var element = editor.getElement();
                    if (!element || element.getAttribute('data-visible-height') !== 'true') {
                        return;
                    }

                    var onWindowResize = function() {
                        scheduleVisibleHeightResize(editor);
                    };

                    scheduleVisibleHeightResize(editor);
                    window.addEventListener('resize', onWindowResize);
                    editor.on('remove', function() {
                        window.removeEventListener('resize', onWindowResize);
                        clearTimeout(visibleHeightResizeTimers[editor.id]);
                    });
                },
            });
        }

        $(".moneymask_negative").maskMoney({
            allowNegative: true,
            thousands: '.',
            decimal: ',',
            affixesStay: false
        });
        $(".moneymask").maskMoney({
            allowNegative: true,
            thousands: '.',
            decimal: ',',
            affixesStay: false
        });
        $(".select2").select2();
        $('.select2_companies').select2({
            ajax: {
                url: '<?php echo base_url('painel/json_getcompanies'); ?>',
                dataType: 'json',
                delay: 150,
                data: function(params) {
                    var query = {
                        search: params.term,
                        budget_type: $("#budget_type").val()
                    }
                    return query;
                },
                processResults: function(data) {
                    return {
                        results: data
                    };
                }
            },
            minimumInputLength: 2,
            cache: true,
            allowClear: true,
            placeholder: {
                id: '0', // The value of the option //
                text: '-- SELECIONE -- '
            }
        });
        $(".datepicker").daterangepicker({
            opens: "left",
            autoUpdateInput: false,
            autoclose: true,
            locale: {
                format: 'DD/MM/YYYY',
                cancelLabel: 'Limpar'
            },
            singleDatePicker: true,
        });
        $('.datepicker').on('apply.daterangepicker', function(ev, picker) {
            $(this).val(picker.startDate.format(picker.locale.format));
        });
        $('.datepicker').on('cancel.daterangepicker', function(ev, picker) {
            //$(this).val('');
        });
        $(".daterange").daterangepicker({
            opens: "center",
            autoUpdateInput: false,
            autoclose: true,
            locale: {
                format: 'DD/MM/YYYY'
            },
            ranges: {
                'Hoje': [moment(), moment()],
                'Ontem': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                'Últimos 7 dias': [moment().subtract(6, 'days'), moment()],
                'Últimos 30 dias': [moment().subtract(29, 'days'), moment()],
                'Este mês': [moment().startOf('month'), moment().endOf('month')],
                'Último mês': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
            }
        });
        $('.daterange').on('apply.daterangepicker', function(ev, picker) {
            $(this).val(picker.startDate.format('DD/MM/YYYY') + ' - ' + picker.endDate.format('DD/MM/YYYY'));
        });
        $('.daterange').on('cancel.daterangepicker', function(ev, picker) {
            //$(this).val('');
        });
        $(".daterange").blur(function() {
            var result = $(this).val().split(' ').length;
            if (result.lenght !== 3) {
                $(this).val("");
            }
        });
        var mascara89Dig = function(val) {
                return val.replace(/\D/g, '').length === 11 ? '(00) 00000-0000' : '(00) 0000-00009';
            },
            optionsTel = {
                onKeyPress: function(val, e, field, options) {
                    field.mask(mascara89Dig.apply({}, arguments), options);
                }
            };
        $('.phonemask').mask(mascara89Dig, optionsTel);

        // Custom
        $("#id_companies").change(function() {
            $("#form_companies").submit();
        });
    });
</script>

<?php if (($editor_choice ?? 'tinymce') === 'froala') : ?>
    <!-- Froala Editor v2 (self-hosted) — carregado apenas quando a empresa usa o Froala -->
    <script type="text/javascript" src="<?php echo base_url('theme/froala/js/froala_editor.min.js'); ?>"></script>
    <script type="text/javascript" src="<?php echo base_url('theme/froala/js/plugins/align.min.js'); ?>"></script>
    <script type="text/javascript" src="<?php echo base_url('theme/froala/js/plugins/code_beautifier.min.js'); ?>"></script>
    <script type="text/javascript" src="<?php echo base_url('theme/froala/js/plugins/code_view.min.js'); ?>"></script>
    <script type="text/javascript" src="<?php echo base_url('theme/froala/js/plugins/draggable.min.js'); ?>"></script>
    <script type="text/javascript" src="<?php echo base_url('theme/froala/js/plugins/image.min.js'); ?>"></script>
    <script type="text/javascript" src="<?php echo base_url('theme/froala/js/plugins/link.min.js'); ?>"></script>
    <script type="text/javascript" src="<?php echo base_url('theme/froala/js/plugins/lists.min.js'); ?>"></script>
    <script type="text/javascript" src="<?php echo base_url('theme/froala/js/plugins/paragraph_format.min.js'); ?>"></script>
    <script type="text/javascript" src="<?php echo base_url('theme/froala/js/plugins/paragraph_style.min.js'); ?>"></script>
    <script type="text/javascript" src="<?php echo base_url('theme/froala/js/plugins/table.min.js'); ?>"></script>
    <script type="text/javascript" src="<?php echo base_url('theme/froala/js/plugins/colors.min.js'); ?>"></script>
    <script type="text/javascript" src="<?php echo base_url('theme/froala/js/plugins/video.min.js'); ?>"></script>
    <script type="text/javascript" src="<?php echo base_url('theme/froala/js/plugins/url.min.js'); ?>"></script>
    <script type="text/javascript" src="<?php echo base_url('theme/froala/js/plugins/entities.min.js'); ?>"></script>
    <script type="text/javascript" src="<?php echo base_url('theme/froala/js/languages/pt_br.js'); ?>"></script>
    <!-- Chave de licença Froala (remove o aviso "Unlicensed") -->
    <script id="fr-fek">
        try {
            (function(k) {
                localStorage.FEK = k;
                t = document.getElementById('fr-fek');
                t.parentNode.removeChild(t);
            })('zB-9qaJ-7dD-17tiA2C-9rsE2bs==')
        } catch (e) {}
    </script>
    <script>
        // Exibe uma mensagem clara quando o upload de imagem/arquivo do editor falha (ex.: erro 500)
        function mostrarErroUploadEditor(error, response) {
            var msg = '';

            // Envia ao log o corpo exato que o Froala recebeu, com o código do erro
            // (4 = falhou ao interpretar a resposta). O Froala não expõe o XHR aqui,
            // então o corpo bruto é a única evidência do que chegou ao navegador.
            reportarFalhaUpload({
                origem: 'froala',
                codigoErro: error && error.code ? error.code : '',
                corpo: response,
                logId: (typeof window.ultimoUploadLogId === 'string' ? window.ultimoUploadLogId : '')
            });

            // 1) Tenta usar a mensagem detalhada que o servidor enviou em JSON: {"errors":{"file":"..."}} ou {"message":"..."}
            if (response) {
                try {
                    var data = JSON.parse(response);
                    if (data) {
                        // Sessão expirada: avisa e manda para o login em vez de exibir erro de upload.
                        if (data.redirect) {
                            redirecionarSessaoExpirada(data);
                            return;
                        }
                        if (data.errors && data.errors.file) {
                            msg = data.errors.file;
                        } else if (data.message) {
                            msg = data.message;
                        } else if (data.error) {
                            msg = data.error;
                        }
                    }
                } catch (ex) {}
            }
            // 2) Sem mensagem do servidor: usa o código de erro do Froala para dar um texto claro
            if (!msg && error && error.code) {
                switch (error.code) {
                    case 1:
                        msg = 'Nenhum arquivo foi selecionado para envio.';
                        break;
                    case 2:
                        msg = 'O servidor recebeu a imagem mas não devolveu o endereço dela. Verifique a configuração de FTP e de domínio da empresa.';
                        break;
                    case 3:
                        msg = 'Erro no servidor ao enviar a imagem (erro 500). Verifique o arquivo e a configuração de FTP da empresa e tente novamente.';
                        break;
                    case 4:
                        msg = 'Não foi possível interpretar a resposta do servidor. Tente novamente; se o erro persistir, informe o suporte.';
                        break;
                    case 5:
                        msg = 'A imagem é muito grande. Envie um arquivo de até 10 MB.';
                        break;
                    case 6:
                        msg = 'Tipo de arquivo inválido. Use imagens jpg, jpeg, png, gif ou webp.';
                        break;
                    case 8:
                        msg = 'O endereço da imagem não é válido.';
                        break;
                }
            }
            if (!msg) {
                msg = 'Não foi possível enviar a imagem. Tente novamente.';
            }
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: 'Ops!',
                    text: msg
                });
            } else {
                alert(msg);
            }
        }

        $(function() {
            if (typeof $.FroalaEditor === 'undefined') {
                return;
            }
            $('.wysiwyg')
                .on('froalaEditor.image.error', function(e, editor, error, response) {
                    mostrarErroUploadEditor(error, response);
                })
                .on('froalaEditor.file.error', function(e, editor, error, response) {
                    mostrarErroUploadEditor(error, response);
                })
                .froalaEditor({
                    enter: $.FroalaEditor.ENTER_BR,
                    language: 'pt_br',
                    heightMin: 360,
                    theme: 'gray',
                    toolbarSticky: true,
                    // Sem Image Manager (o gestorcms-v3 não expõe endpoints de listagem/exclusão): só upload e por URL
                    imageInsertButtons: ['imageBack', '|', 'imageUpload', 'imageByURL'],
                    imageUploadURL: '<?php echo base_url('editor/upload'); ?>',
                    fileUploadURL: '<?php echo base_url('editor/upload'); ?>',
                    // Sem esse header o CI não trata a requisição como AJAX e, com a sessão
                    // expirada, o XHR recebe o HTML da tela de login com status 200.
                    requestHeaders: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    // Explícito para não depender do default ao trocar de versão do Froala.
                    imageUploadParam: 'file',
                    fileUploadParam: 'file',
                    // O default do Froala não inclui webp, que o servidor aceita.
                    imageAllowedTypes: ['jpeg', 'jpg', 'png', 'gif', 'webp'],
                    // 10 MB: mesmo teto de MY_Controller::EDITOR_UPLOAD_MAX_SIZE.
                    imageMaxSize: 10 * 1024 * 1024,
                    fileMaxSize: 10 * 1024 * 1024
                });
        });
    </script>
<?php endif; ?>

<?php $this->load->view('partials/csrf_js'); ?>
<?php $this->load->view('partials/form_saving_js'); ?>
</body>

</html>