<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Busca rápida de clientes — modal do "Acesso rápido" da navbar.
 *
 * Parcial global, incluída pelo menu.php (que o header carrega em toda tela do
 * painel), DENTRO do bloco da empresa master. Incluir com:
 *
 *   <?php $this->load->view('customers/quick_search_modal'); ?>
 *
 * O gatilho é qualquer elemento com id `btn_busca_cliente`. O modal consulta
 * `clientes/json_postbuscarapida` conforme se digita e leva para a visão geral
 * do cliente escolhido.
 *
 * Nada aqui grava filtro na sessão: a busca da tela de Clientes continua como
 * o usuário a deixou.
 */
?>
<style>
  /* Realce do item navegado por teclado.
     NÃO se usa a classe `active` do Bootstrap aqui: ela pinta o fundo de azul
     sólido e deixa por baixo o nome fantasia, o documento e os domínios, que
     são `text-muted` — o cinza sobre o azul fica ilegível justamente na linha
     que o usuário está escolhendo.
     `outline` com offset negativo desenha a marca PARA DENTRO e não ocupa
     espaço no layout: com `border` a linha ganharia 2px e as vizinhas
     saltariam a cada tecla. O `z-index` evita que o item seguinte cubra a
     marca, já que os itens da list-group se encostam. */
  .js-busca-cliente-item.js-busca-cliente-ativo {
    outline: 2px solid var(--bs-primary, #3f80ea);
    outline-offset: -2px;
    position: relative;
    z-index: 2;
  }
</style>

<div class="modal fade" id="modal_busca_cliente" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">BUSCAR CLIENTE</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body">
        <div class="input-group mb-3">
          <span class="input-group-text"><i class="mdi mdi-magnify"></i></span>
          <input type="text" class="form-control form-control-lg" id="busca_cliente_termo"
            placeholder="Nome, nome fantasia, CNPJ/CPF ou domínio..." autocomplete="off" />
          <span class="input-group-text d-none" id="busca_cliente_spinner">
            <span class="spinner-border spinner-border-sm"></span>
          </span>
        </div>
        <div id="busca_cliente_resultado"></div>
      </div>
      <div class="modal-footer justify-content-between">
        <small class="text-muted">Use <kbd>&uarr;</kbd> <kbd>&darr;</kbd> para navegar e <kbd>Enter</kbd> para abrir.</small>
        <div>
          <a class="btn btn-outline-secondary" href="<?php echo base_url('clientes'); ?>">VER TODOS</a>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">FECHAR</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  // DOMContentLoaded, e não $(function(){}): o app.js que traz o jQuery é
  // carregado no footer, ou seja, DEPOIS desta view. Referenciar $ aqui na hora
  // do parse estoura e nenhum handler chega a ser registrado.
  document.addEventListener("DOMContentLoaded", function() {
    var URL_BUSCA = '<?php echo base_url('clientes/json_postbuscarapida'); ?>';
    // Espelha Clientes::BUSCA_RAPIDA_MINIMO. É um literal de propósito: esta
    // parcial é carregada pelo menu em TODA tela do painel, e o controller
    // Clientes não está carregado na maioria delas — referenciar a constante
    // aqui derrubaria o site inteiro com "Class 'Clientes' not found". Quem
    // manda é o servidor: ele responde `curto` quando o termo é pequeno.
    var MINIMO = 2;
    var ATRASO = 300;

    // Cada requisição leva um número. Sem isso, uma resposta lenta chega depois
    // de uma rápida e a tela mostra o resultado de um termo já apagado.
    var sequencia = 0;
    var temporizador = null;
    var selecionado = -1;

    var $campo = $('#busca_cliente_termo');
    var $area = $('#busca_cliente_resultado');
    var $spinner = $('#busca_cliente_spinner');

    function aviso(classe, texto) {
      $area.html($('<div class="alert mb-0"></div>').addClass(classe)
        .append($('<div class="alert-message"></div>').text(texto)));
    }

    function dica() {
      aviso('alert-secondary', 'Digite ao menos ' + MINIMO + ' caracteres para buscar.');
    }

    // Sessão expirada: o MY_Controller responde 200 com {redirect:true}, então
    // a checagem precisa ser feita dentro do success, não pelo status HTTP.
    function sessaoExpirou(data) {
      if (data && data.redirect) {
        window.location.replace('<?php echo base_url('painel/sair_custom'); ?>');
        return true;
      }
      return false;
    }

    function badgeTipo(tipo) {
      var classe = tipo === 'J' ? 'bg-primary' : (tipo === 'F' ? 'bg-info' : 'bg-dark');
      return $('<span class="badge ms-2"></span>').addClass(classe).text(tipo);
    }

    /**
     * Monta uma linha do resultado.
     *
     * Âncora de verdade, e não div com onclick: assim Ctrl+clique e botão do
     * meio abrem em nova aba, e o navegador mostra o destino na barra de status.
     *
     * Todo texto entra por .text() — razão social e domínio são campos livres
     * do cadastro, e .html() aqui seria XSS armazenado.
     */
    function montarLinha(cliente) {
      var $linha = $('<a class="list-group-item list-group-item-action js-busca-cliente-item"></a>')
        .attr('href', cliente.url);

      $linha.append($('<div class="d-flex align-items-center"></div>')
        .append($('<strong></strong>').text(cliente.nome))
        .append(badgeTipo(cliente.tipo)));

      var identificacao = [];
      if (cliente.fantasia) identificacao.push(cliente.fantasia);
      if (cliente.documento) identificacao.push(cliente.documento);
      if (identificacao.length) {
        $linha.append($('<div class="text-muted small"></div>').text(identificacao.join(' · ')));
      }

      var $rodape = $('<div class="mt-1 small"></div>');

      // Selos por situação, já resolvidos no servidor — mesmo vocabulário da
      // coluna "Contratos" da listagem de clientes.
      if (cliente.contratos.length) {
        $.each(cliente.contratos, function(i, selo) {
          $rodape.append($('<span class="badge me-1"></span>').addClass(selo.classe).text(selo.texto));
        });
      } else {
        $rodape.append($('<span class="badge bg-light text-dark border"></span>').text('Sem contrato'));
      }

      if (cliente.dominios.length) {
        var texto = cliente.dominios.join(', ');
        var restante = cliente.dominios_total - cliente.dominios.length;
        if (restante > 0) texto += ' +' + restante;
        $rodape.append($('<span class="text-muted ms-2 text-break"></span>')
          .append($('<i class="mdi mdi-web"></i>'))
          .append(document.createTextNode(' ' + texto)));
      }

      $linha.append($rodape);
      return $linha;
    }

    function renderizar(dados) {
      selecionado = -1;

      if (dados.curto) {
        dica();
        return;
      }

      if (!dados.clientes.length) {
        aviso('alert-warning', 'Nenhum cliente encontrado para "' + dados.termo + '".');
        return;
      }

      var $lista = $('<div class="list-group"></div>');
      $.each(dados.clientes, function(i, cliente) {
        $lista.append(montarLinha(cliente));
      });

      $area.empty().append($lista);

      if (dados.tem_mais) {
        $area.append($('<small class="text-muted d-block mt-2"></small>')
          .text('Mostrando os ' + dados.total_exibido + ' primeiros. Refine a busca ou use VER TODOS.'));
      }
    }

    function buscar(termo) {
      var seq = ++sequencia;
      $spinner.removeClass('d-none');

      $.ajax({
        type: 'POST',
        url: URL_BUSCA,
        data: {
          termo: termo
        },
        dataType: 'json',
        success: function(data) {
          // Resposta de uma busca que não é mais a atual: descarta em silêncio.
          if (seq !== sequencia) return;
          if (sessaoExpirou(data)) return;

          if (!data || !data.return) {
            aviso('alert-danger', (data && data.message) ? data.message : 'Não foi possível buscar.');
            return;
          }
          renderizar(data.data);
        },
        error: function() {
          if (seq !== sequencia) return;
          aviso('alert-danger', 'Falha de comunicação ao buscar clientes.');
        },
        complete: function() {
          if (seq === sequencia) $spinner.addClass('d-none');
        }
      });
    }

    function marcar(indice) {
      var $itens = $('.js-busca-cliente-item');
      if (!$itens.length) return;

      if (indice < 0) indice = $itens.length - 1;
      if (indice >= $itens.length) indice = 0;

      selecionado = indice;
      $itens.removeClass('js-busca-cliente-ativo');
      $itens.eq(selecionado).addClass('js-busca-cliente-ativo');
      // Mantém o item escolhido visível quando a lista passa da área do modal.
      $itens.eq(selecionado)[0].scrollIntoView({
        block: 'nearest'
      });
    }

    $campo.on('input', function() {
      clearTimeout(temporizador);
      var termo = $(this).val().trim();

      if (termo.length < MINIMO) {
        // Incrementa a sequência para uma resposta em voo não repovoar a lista
        // que o usuário acabou de esvaziar.
        sequencia++;
        $spinner.addClass('d-none');
        dica();
        return;
      }

      temporizador = setTimeout(function() {
        buscar(termo);
      }, ATRASO);
    });

    $campo.on('keydown', function(e) {
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        marcar(selecionado + 1);
        return;
      }
      if (e.key === 'ArrowUp') {
        e.preventDefault();
        marcar(selecionado - 1);
        return;
      }
      if (e.key === 'Enter') {
        var $itens = $('.js-busca-cliente-item');
        if (!$itens.length) return;
        e.preventDefault();
        // Sem nada escolhido, Enter abre o primeiro — que é o mais relevante.
        var alvo = selecionado >= 0 ? selecionado : 0;
        window.location.href = $itens.eq(alvo).attr('href');
      }
    });

    // Abre por JS em vez de data-bs-toggle: o gatilho fica dentro de um
    // dropdown, e aninhar dois toggles do Bootstrap no mesmo ramo dá conflito
    // de foco. Assim também dá para zerar a tela ANTES de mostrar o modal.
    $('#btn_busca_cliente').on('click', function(e) {
      e.preventDefault();
      $campo.val('');
      dica();
      $('#modal_busca_cliente').modal('show');
    });

    // O foco só pega depois de o modal estar visível: durante a animação do
    // Bootstrap o elemento ainda não é focável.
    $('#modal_busca_cliente').on('shown.bs.modal', function() {
      $campo.trigger('focus');
    });

    // Zera ao fechar, senão a próxima abertura mostraria a busca anterior.
    $('#modal_busca_cliente').on('hidden.bs.modal', function() {
      clearTimeout(temporizador);
      sequencia++;
      $campo.val('');
      selecionado = -1;
      dica();
    });

    dica();
  });
</script>
