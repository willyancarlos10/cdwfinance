<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Modal de consulta WHOIS/RDAP de um domínio de CONTRATO.
 *
 * Parcial compartilhada pela visão geral do contrato e pela do cliente — as
 * duas listam `crm_contracts_domains` e disparam o mesmo endpoint. Incluir com:
 *
 *   <?php $this->load->view('domains/whois_modal'); ?>
 *
 * Os botões que a abrem precisam ter a classe `js-whois-dominio` e os atributos
 * `data-id` (id do domínio do contrato) e `data-dominio` (nome, só para o
 * título).
 */
?>
<div class="modal fade" id="modal_whois_dominio" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">WHOIS — <span id="whois_dominio_nome"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body" id="whois_dominio_corpo">
        <p class="text-muted mb-0">Consultando...</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">FECHAR</button>
        <button type="button" class="btn btn-primary" id="btn_whois_dominio_atualizar"><i class="fa fa-rotate-right"></i> ATUALIZAR WHOIS</button>
      </div>
    </div>
  </div>
</div>

<script>
  // DOMContentLoaded, e não $(function(){}): o app.js que traz o jQuery é
  // carregado no footer, ou seja, DEPOIS desta view. Referenciar $ aqui na hora
  // do parse estoura e nenhum handler chega a ser registrado.
  document.addEventListener("DOMContentLoaded", function() {
    // Domínio aberto no modal, para o botão ATUALIZAR WHOIS repetir a consulta
    // sem depender de qual botão da tabela a abriu.
    var whoisAtual = null;

    // Cada chamada bate na origem (RDAP para .br, API Ninjas para os demais)
    // ignorando o intervalo de reconsulta do cron — é sempre dado fresco.
    function consultarWhois() {
      if (!whoisAtual) return;

      var $corpo = $('#whois_dominio_corpo');
      var $atualizar = $('#btn_whois_dominio_atualizar');

      $corpo.html('<p class="text-muted mb-0"><i class="fa fa-spinner fa-spin"></i> Consultando o registro do domínio...</p>');
      $atualizar.prop('disabled', true);

      $.post('<?php echo base_url('contratos/json_postwhoisdominio'); ?>', {
        id: whoisAtual
      }, null, 'json').done(function(retorno) {
        if (!retorno || !retorno.success) {
          $corpo.html($('<div class="alert alert-danger mb-0"><div class="alert-message"></div></div>')
            .find('.alert-message').text(retorno && retorno.message ? retorno.message : 'Não foi possível consultar o domínio.').end());
          return;
        }

        $corpo.empty();

        if (retorno.data.ns_changed) {
          $corpo.append('<div class="alert alert-warning"><div class="alert-message">Os nameservers deste domínio mudaram desde a última consulta. A alteração foi registrada no histórico.</div></div>');
        }

        var $tabela = $('<table class="table table-sm mb-0"></table>');
        var $tbody = $('<tbody></tbody>');
        var vencimento = null;

        $.each(retorno.data.fields, function(i, campo) {
          if (campo.label === 'Vencimento') vencimento = campo.value;
          $tbody.append($('<tr></tr>')
            .append($('<th class="text-nowrap" style="width: 40%;"></th>').text(campo.label))
            .append($('<td></td>').text(campo.value)));
        });

        if (retorno.data.nameservers.length) {
          var $lista = $('<ul class="list-unstyled mb-0"></ul>');
          $.each(retorno.data.nameservers, function(i, ns) {
            $lista.append($('<li></li>').text(ns));
          });
          $tbody.append($('<tr></tr>')
            .append('<th class="text-nowrap">Nameservers</th>')
            .append($('<td></td>').append($lista)));
        }

        $tabela.append($tbody);
        $corpo.append($('<div class="table-responsive"></div>').append($tabela));

        $corpo.append(retorno.data.sem_vinculo
          ? '<small class="text-muted d-block mt-2">Consulta feita agora na origem. Este domínio não tem vínculo com um servidor, então só o vencimento do contrato foi atualizado.</small>'
          : '<small class="text-muted d-block mt-2">Consulta feita agora na origem. Os dados foram gravados e a listagem já reflete o resultado.</small>');

        // Atualiza a célula de vencimento da linha: sem isto, a tabela atrás do
        // modal continuaria com o valor antigo até um refresh manual.
        if (vencimento) {
          $('.js-cel-venc-dominio[data-id="' + whoisAtual + '"]').text(vencimento);
        }
      }).fail(function() {
        $corpo.html('<div class="alert alert-danger mb-0"><div class="alert-message">Falha de comunicação ao consultar o domínio.</div></div>');
      }).always(function() {
        $atualizar.prop('disabled', false);
      });
    }

    $('.js-whois-dominio').on('click', function() {
      var $botao = $(this);

      whoisAtual = $botao.data('id');
      $('#whois_dominio_nome').text($botao.data('dominio'));
      $('#modal_whois_dominio').modal('show');

      consultarWhois();
    });

    $('#btn_whois_dominio_atualizar').on('click', consultarWhois);
  });
</script>
