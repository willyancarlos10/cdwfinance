<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Títulos do contrato na API pública — as duas origens.
 *
 * São coisas diferentes, e por isso vão em blocos separados na resposta:
 *
 *  - `invoices`: as faturas que o CDW Finance GEROU (`crm_invoices`). Consulta
 *    local, barata.
 *  - `bomcontrole`: o extrato do ERP, consultado AO VIVO. Nada de financeiro
 *    do ERP é copiado para o banco — snapshot local criaria uma segunda
 *    verdade sobre faturas que mudam lá.
 *
 * Um contrato costuma ter só uma das duas: `billing_source` diz quem fatura, e
 * é exclusivo. Ter as duas populadas é o retrato de uma transição.
 *
 * A REDE só acontece quando o contrato está vinculado — a guarda vive dentro
 * de `Bomcontrole_model::montarExtratoContrato()`, que devolve
 * `['vinculado' => FALSE]` sem chamar o ERP. Dos 403 contratos da base, 383
 * têm vínculo.
 */
class Api_invoice_model extends CI_Model
{
  /**
   * Teto de faturas embutidas no detalhe do contrato, que não é paginado.
   * Um contrato mensal acumula 12 por ano, então 200 cobre 16 anos — e ainda
   * assim é teto, não promessa.
   */
  const MAX_FATURAS_NO_DETALHE = 200;

  /**
   * Timeout por chamada ao ERP quando quem espera é uma requisição HTTP.
   * O padrão da library é 30s e são DUAS chamadas (abertas + pagas), o que
   * deixaria a resposta do contrato pendurada por mais de um minuto no pior
   * caso. Mesmo raciocínio do TIMEOUT_SINCRONIZACAO do Bomcontrole_model.
   */
  const TIMEOUT_ERP = 12;

  const CAMPOS = 'id, id_contract, id_customer, id_charge, installment_number, installments_total,
    charge_description, competence, due_date, value, status, situation, description,
    invoice_policy, psp, psp_status, registration, link_boleto, linha_digitavel,
    paid_at, paid_amount, paid_method, sent_at,
    nf_status, nf_issued_at, nf_sent_at, link_nota_fiscal, link_nota_fiscal_xml,
    comments, created, modified';

  public function __construct()
  {
    parent::__construct();
    $this->load->model('bomcontrole_model');
  }

  // ------------------------------------------------- FATURAS DO CDW FINANCE

  public function countInvoices($idCompany, array $filters = [])
  {
    $this->applyFilters($idCompany, $filters);
    return (int) $this->db->count_all_results();
  }

  public function getInvoices($idCompany, $limit, $offset, array $filters = [])
  {
    $this->db->select(self::CAMPOS);
    $this->applyFilters($idCompany, $filters);

    // Mesma ordem das telas: o que interessa numa lista de faturas está
    // sempre no começo.
    return $this->db->order_by('due_date', 'desc')
      ->order_by('id', 'desc')
      ->limit((int) $limit, (int) $offset)
      ->get()
      ->result();
  }

  public function formatInvoice($invoice)
  {
    return [
      'id' => (int) $invoice->id,
      'contract_id' => (int) $invoice->id_contract,
      'customer_id' => (int) $invoice->id_customer,
      // O período da OBRIGAÇÃO. Todas as parcelas de uma competência a
      // compartilham; o que as distingue é `installment`.
      'competence' => $invoice->competence,
      'due_date' => $invoice->due_date,
      // Decimal em string, como no contrato: float perderia o centavo.
      'value' => $invoice->value,
      'status' => $invoice->status,
      // Derivada na view: 'vencida' é due_date < hoje numa fatura aberta —
      // muda sozinha, ninguém grava.
      'situation' => $invoice->situation,
      'description' => $invoice->description,
      'installment' => [
        'number' => (int) $invoice->installment_number,
        'total' => (int) $invoice->installments_total,
      ],
      // `id_charge` é sentinela 0 para a recorrência; > 0 é cobrança avulsa.
      'charge' => empty($invoice->id_charge) ? NULL : [
        'id' => (int) $invoice->id_charge,
        'description' => $invoice->charge_description,
      ],
      'billing' => [
        'psp' => $invoice->psp,
        'psp_status' => $invoice->psp_status,
        // Responde "existe boleto para o cliente pagar?", que é pergunta
        // diferente de `situation` ("ele já pagou?").
        'registration' => $invoice->registration,
        'has_boleto' => !empty($invoice->linha_digitavel) || !empty($invoice->link_boleto),
        'invoice_policy' => $invoice->invoice_policy,
        'sent_at' => $invoice->sent_at,
      ],
      'payment' => empty($invoice->paid_at) ? NULL : [
        'paid_at' => $invoice->paid_at,
        // Valor e data são os DO PROVEDOR: o cliente pode ter pago com juros,
        // desconto ou em data diferente, e o extrato do banco é o que vale.
        'amount' => $invoice->paid_amount,
        'method' => $invoice->paid_method,
      ],
      'invoice_document' => [
        'status' => $invoice->nf_status,
        'issued_at' => $invoice->nf_issued_at,
        'sent_at' => $invoice->nf_sent_at,
        'pdf_url' => $invoice->link_nota_fiscal,
        'xml_url' => $invoice->link_nota_fiscal_xml,
      ],
      'comments' => $invoice->comments,
      'created_at' => $invoice->created,
      'updated_at' => $invoice->modified,
    ];
  }

  // --------------------------------------------------- EXTRATO DO ERP

  /**
   * Extrato do Bom Controle do contrato, pronto para a API.
   *
   * Reusa `Bomcontrole_model::montarExtratoContrato()` — a mesma regra que a
   * aba do painel usa, inclusive a guarda que evita a rede quando o contrato
   * não está vinculado.
   *
   * **Falha do ERP nunca derruba a resposta do contrato**: o contrato já foi
   * consultado com sucesso e é dado local. O bloco sai com `available: false`
   * e o motivo — devolver 500 aqui faria uma indisponibilidade do ERP apagar
   * também os dados que temos.
   *
   * @param  int $idContract
   * @param  int $idCompany
   * @return array
   */
  public function extratoBomControle($idContract, $idCompany)
  {
    $resultado = $this->bomcontrole_model->montarExtratoContrato($idContract, $idCompany, self::TIMEOUT_ERP);

    if (empty($resultado['success'])) {
      // Integração desativada, sem chave ou ERP fora do ar.
      log_message('error', '[BOMCONTROLE] API: extrato do contrato ' . (int) $idContract
        . ' (empresa ' . (int) $idCompany . ') falhou: ' . ($resultado['message'] ?? ''));

      return [
        'linked' => TRUE,
        'available' => FALSE,
        'message' => $resultado['message'] ?: 'Não foi possível consultar o Bom Controle.',
        'items' => [],
      ];
    }

    $dados = $resultado['data'] ?? [];

    // Sem vínculo: nenhuma chamada foi feita ao ERP, e isso é estado normal,
    // não falha. `available` é TRUE porque a resposta é completa e verdadeira
    // — simplesmente não há o que buscar.
    if (empty($dados['vinculado'])) {
      return [
        'linked' => FALSE,
        'available' => TRUE,
        'message' => 'Contrato não vinculado a um contrato de venda no Bom Controle.',
        'items' => [],
      ];
    }

    $bloco = [
      'linked' => TRUE,
      'available' => TRUE,
      'message' => NULL,
      'items' => array_map([$this, 'formatBomControleItem'], $dados['itens'] ?? []),
    ];

    if (!empty($dados['contrato_bc'])) {
      $bloco['contract'] = $this->formatBomControleContrato($dados['contrato_bc']);
    }
    // O fallback pelo Financeiro/Pesquisar mistura parcelas de outros
    // contratos do mesmo cliente; quando ativo, o model avisa e o aviso
    // precisa chegar a quem consome.
    if (!empty($dados['aviso_pagas'])) {
      $bloco['warning'] = $dados['aviso_pagas'];
    }

    return $bloco;
  }

  /**
   * Item do extrato → JSON da API. As chaves vêm em PT-BR do
   * `Bomcontrole_model` (que normaliza o PascalCase do ERP); aqui viram o
   * mesmo idioma das outras entidades.
   */
  public function formatBomControleItem(array $item)
  {
    return [
      'invoice_id' => (int) ($item['id_fatura'] ?? 0),
      'issue_date' => $item['emissao'] ?? NULL,
      'due_date' => $item['vencimento'] ?? NULL,
      'value' => isset($item['valor']) ? (float) $item['valor'] : NULL,
      // Derivado no model: não há enum de status na API do ERP — sai de
      // Quitado + vencimento vs. hoje.
      'status' => $item['status'] ?? NULL,
      'days_overdue' => isset($item['dias_vencido']) ? (int) $item['dias_vencido'] : NULL,
      'paid' => !empty($item['quitado']),
      'paid_at' => $item['data_pagamento'] ?? NULL,
      'installment' => $item['parcela'] ?? NULL,
      'payment_method' => $item['forma_pagamento'] ?? NULL,
      'boleto_url' => $item['link_boleto'] ?? NULL,
      'invoice_document_url' => $item['link_nota_fiscal'] ?? NULL,
      'comments' => $item['observacao'] ?? NULL,
    ];
  }

  /** Contrato de venda no ERP. As chaves vêm de `consultarExtratoDoContrato()`. */
  private function formatBomControleContrato(array $contrato)
  {
    return [
      'id' => isset($contrato['id']) ? (int) $contrato['id'] : NULL,
      'sale_id' => isset($contrato['id_venda']) ? (int) $contrato['id_venda'] : NULL,
      'closed' => !empty($contrato['encerrado']),
      'value' => isset($contrato['valor']) ? (float) $contrato['valor'] : NULL,
      'customer_name' => $contrato['cliente_nome'] ?? NULL,
      'linked_at' => $contrato['vinculado_em'] ?? NULL,
    ];
  }

  // ------------------------------------------------------------- FILTROS

  private function applyFilters($idCompany, array $filters)
  {
    $this->db->from('crm_invoices_v');
    $this->db->where('id_company', (int) $idCompany);

    if (!empty($filters['id'])) {
      $this->db->where('id', (int) $filters['id']);
    }
    if (!empty($filters['contract_id'])) {
      $this->db->where('id_contract', (int) $filters['contract_id']);
    }
    if (!empty($filters['customer_id'])) {
      $this->db->where('id_customer', (int) $filters['customer_id']);
    }
    if (!empty($filters['status'])) {
      $this->db->where('status', (string) $filters['status']);
    }
    if (!empty($filters['situation'])) {
      $this->db->where('situation', (string) $filters['situation']);
    }
  }
}
