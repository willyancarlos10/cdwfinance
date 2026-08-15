<!-- Estado de "enviando" no botão de submit de todo formulário POST: desativa o
     botão e troca o ícone por um spinner até a navegação acontecer. Resolve duas
     coisas de uma vez — o usuário saber que o clique pegou, e o segundo clique
     não virar POST duplicado.

     Vale para o painel (footer.php) e para as telas públicas (login, cadastro,
     recuperação de senha e o wizard de clientes), como o partials/csrf_js.

     Opt-out: `data-sem-spinner` no <form>. -->
<script>
    (function() {
        var LIMITE_MS = 20000;

        function acharBotao(evento, form) {
            // submitter dá o botão realmente clicado (o formulário pode ter mais
            // de um submit). Onde não existir, cai no primeiro do formulário.
            var botao = evento.submitter || form.querySelector('button[type="submit"]');
            if (botao) return botao;

            // Formulário de ação confirmada (SUSPENDER, EXCLUIR, ATIVAR): o
            // botão visível é type="button", porque quem envia é o .submit() do
            // jQuery depois do Swal. Com um único botão no formulário, não há
            // ambiguidade sobre qual sinalizar.
            var botoes = form.querySelectorAll('button');
            return botoes.length === 1 ? botoes[0] : null;
        }

        function vestirSpinner(botao) {
            var spinner = document.createElement('span');
            spinner.className = 'spinner-border spinner-border-sm me-1';
            spinner.setAttribute('role', 'status');
            spinner.setAttribute('aria-hidden', 'true');

            // O rótulo do botão é preservado de propósito: o mesmo código serve
            // a SALVAR, FILTRAR, ENTRAR e EXCLUIR sem inventar texto para cada
            // um. Só o ícone dá lugar ao spinner; sem ícone, ele entra na frente.
            var icone = botao.querySelector('i');
            if (icone) {
                botao.replaceChild(spinner, icone);
            } else {
                botao.insertBefore(spinner, botao.firstChild);
            }
        }

        // Listener delegado no document, em BUBBLE (o terceiro argumento é
        // false): assim ele roda depois dos handlers presos ao próprio
        // formulário, independentemente da ordem em que os scripts carregaram.
        // É o que permite confiar no defaultPrevented abaixo.
        //
        // (O csrf_js escuta o mesmo evento em CAPTURE, porque precisa injetar o
        // token antes; os dois não se atrapalham.)
        document.addEventListener('submit', function(evento) {
            var form = evento.target;

            if (!form || form.tagName !== 'FORM') return;
            if (form.hasAttribute('data-sem-spinner')) return;
            if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;

            // Validação da página barrou o envio (campo obrigatório vazio,
            // confirmação recusada): a página continua onde está, então o botão
            // não pode entrar em "enviando". Handler que usa `return false` no
            // jQuery nem chega aqui — o stopPropagation embutido corta o evento
            // antes do document, o que dá o mesmo resultado.
            if (evento.defaultPrevented) return;

            // Sem botão de submit dentro do formulário (envio disparado por
            // JS, como o filtro de empresa do menu) não há o que sinalizar —
            // sai antes de marcar qualquer estado, para não travar o envio
            // seguinte de um formulário que nunca chega a navegar.
            var botao = acharBotao(evento, form);
            if (!botao) return;

            // Trava síncrona: segura o segundo submit já neste instante, sem
            // depender do disabled, que só entra no próximo tique.
            if (form.dataset.enviando === '1') {
                evento.preventDefault();
                return;
            }
            form.dataset.enviando = '1';

            var htmlOriginal = botao.innerHTML;

            function repousar() {
                delete form.dataset.enviando;
                botao.disabled = false;
                botao.innerHTML = htmlOriginal;
            }

            // O disabled entra num setTimeout, não aqui: desativar o botão no
            // meio do despacho do submit chega a cancelar o envio em alguns
            // navegadores. No próximo tique a submissão já partiu.
            setTimeout(function() {
                botao.disabled = true;
                vestirSpinner(botao);
            }, 0);

            // Rede de segurança: se nada navegou nesse tempo, algo prendeu o
            // envio fora do nosso alcance. Botão preso é pior que o risco de um
            // clique repetido tanto tempo depois.
            var relogio = setTimeout(repousar, LIMITE_MS);

            // Voltar pelo histórico devolve a página do cache do navegador com o
            // DOM como estava — botão desativado inclusive.
            window.addEventListener('pageshow', function(e) {
                if (e.persisted) {
                    clearTimeout(relogio);
                    repousar();
                }
            });
        }, false);
    })();
</script>
