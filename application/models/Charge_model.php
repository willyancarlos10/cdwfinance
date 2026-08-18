<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Cobrança avulsa: uma venda pontual, parcelada, dentro de um contrato.
 *
 * É a outra origem de fatura do sistema. A recorrência (Invoice_model) responde
 * "quanto este contrato cobra por ciclo, para sempre"; a cobrança avulsa
 * responde "este serviço específico custou R$ X, em N vezes, uma vez só".
 *
 * Duas propriedades a separam da recorrência:
 *
 *  - **Não tem ponteiro.** As N faturas nascem no ato do lançamento, em
 *    transação, porque a obrigação inteira já existe e não há "próxima
 *    competência" a acompanhar. Por isso aqui a transação faz sentido e no
 *    motor recorrente não: lá, uma falha no meio é curada pela rodada seguinte,
 *    que refaz a competência a partir do ponteiro que não avançou.
 *  - **Nunca é reajustada.** O `Adjustment_model` só toca `crm_contracts.value`.
 *
 * A cobrança é SEMPRE de um contrato (daí o nome da tabela seguir a convenção
 * das demais filhas), e é dele que herda a política de NF. Isso preserva a FK e
 * o INNER JOIN da `crm_invoices_v` — nenhuma fatura órfã de contrato.
 *
 * Quem escreve em `crm_invoices` continua sendo só o `Invoice_model`: este
 * model chama o `criarFatura()` de lá.
 *
 * @see Invoice_model
 */
class Charge_model extends CI_Model
{
    /**
     * Teto de parcelas de uma cobrança avulsa.
     *
     * Diferente da recorrência, aqui não há ciclo que limite — o teto existe
     * só para um dedo escorregado no formulário não virar um carnê de 200
     * meses. Dois anos cobre o parcelamento comercial que se pratica.
     */
    const MAX_PARCELAS = 24;

    public function __construct()
    {
        parent::__construct();
        $this->load->model('invoice_model');
    }

    /**
     * Lança a cobrança e gera as parcelas.
     *
     * @param  int   $idContract
     * @param  int   $idCompany
     * @param  int   $idUser
     * @param  array $dados description, value, installments, due_date (Y-m-d), invoice_policy, comments
     * @return array success, message, data => ['id_charge', 'geradas']
     */
    public function lancar($idContract, $idCompany, $idUser, array $dados)
    {
        $contrato = $this->global_model->getWhere_off('crm_contracts', [
            'id' => (int) $idContract,
            'id_company' => (int) $idCompany,
        ], TRUE);

        if (empty($contrato)) {
            return $this->resposta(FALSE, 'Contrato não encontrado.');
        }

        // Mesma regra do generateNow: cobrar aqui um contrato que o ERP também
        // cobra é cobrar o cliente duas vezes pelo mesmo serviço.
        if ((string) $contrato->billing_source !== 'cdwfinance') {
            return $this->resposta(FALSE, 'Este contrato é faturado pelo Bom Controle — ative o faturamento pelo CDW Finance antes de lançar cobranças aqui.');
        }

        if ((string) $contrato->status !== 'vigente') {
            return $this->resposta(FALSE, 'Só contrato vigente recebe cobrança.');
        }

        $descricao = trim((string) (isset($dados['description']) ? $dados['description'] : ''));
        if ($descricao === '') {
            return $this->resposta(FALSE, 'Informe a descrição da cobrança.');
        }

        $valor = round((float) (isset($dados['value']) ? $dados['value'] : 0), 2);
        if ($valor <= 0) {
            return $this->resposta(FALSE, 'O valor da cobrança precisa ser maior que zero.');
        }

        $parcelas = (int) (isset($dados['installments']) ? $dados['installments'] : 1);
        if ($parcelas < 1 || $parcelas > self::MAX_PARCELAS) {
            return $this->resposta(FALSE, 'Número de parcelas inválido (1 a ' . self::MAX_PARCELAS . ').');
        }

        // Parcela que arredonda para zero é cobrança que não cobra: R$ 0,03 em
        // 5× geraria quatro faturas de R$ 0,00.
        if (((int) round($valor * 100)) < $parcelas) {
            return $this->resposta(FALSE, 'O valor é pequeno demais para ' . $parcelas . ' parcelas — cada parcela ficaria em R$ 0,00.');
        }

        $vencimento = $this->invoice_model->primeiroDiaDoMes((string) (isset($dados['due_date']) ? $dados['due_date'] : ''));
        if ($vencimento === NULL) {
            return $this->resposta(FALSE, 'Data do primeiro vencimento inválida.');
        }

        // A competência é o mês do primeiro vencimento e o dia sai da mesma
        // data: as parcelas seguintes caem no mesmo dia dos meses à frente.
        $competencia = $vencimento;
        $diaVencimento = (int) substr((string) $dados['due_date'], 8, 2);
        if ($diaVencimento < 1 || $diaVencimento > 31) {
            return $this->resposta(FALSE, 'Data do primeiro vencimento inválida.');
        }

        $politica = (string) (isset($dados['invoice_policy']) ? $dados['invoice_policy'] : '');
        if ($politica === '') {
            $politica = (string) $contrato->invoice_policy;
        }

        $valores = $this->invoice_model->valoresDasParcelas($valor, $parcelas);

        $this->db->trans_begin();

        $idCharge = $this->global_model->add('crm_contracts_charges', [
            'id_company' => (int) $contrato->id_company,
            'id_customer' => (int) $contrato->id_customer,
            'id_contract' => (int) $contrato->id,
            'description' => mb_substr($descricao, 0, 255),
            'value' => $valor,
            'installments' => $parcelas,
            'competence' => $competencia,
            'billing_day' => $diaVencimento,
            'invoice_policy' => $politica,
            'status' => 'lancada',
            'comments' => trim((string) (isset($dados['comments']) ? $dados['comments'] : '')),
            'created' => date('Y-m-d H:i:s'),
            'created_by' => (int) $idUser,
        ]);

        if (empty($idCharge)) {
            $this->db->trans_rollback();
            return $this->resposta(FALSE, 'Falha ao gravar a cobrança.');
        }

        $geradas = 0;

        for ($n = 1; $n <= $parcelas; $n++) {
            $resultado = $this->invoice_model->criarFatura(
                $contrato,
                $competencia,
                $this->invoice_model->vencimentoDaParcela($competencia, $diaVencimento, $n),
                $idUser,
                [
                    'numero' => $n,
                    'total' => $parcelas,
                    'valor' => $valores[$n - 1],
                    'id_charge' => (int) $idCharge,
                    // A descrição da cobrança substitui a dos serviços do
                    // contrato: o que está sendo cobrado aqui é outra coisa.
                    'description' => mb_substr($descricao, 0, 255),
                    'invoice_policy' => $politica,
                ]
            );

            if (!$resultado['success']) {
                $this->db->trans_rollback();
                return $this->resposta(FALSE, 'Falha ao gerar a parcela ' . $n . ': ' . $resultado['message']);
            }

            if ($resultado['criada']) $geradas++;
        }

        if ($this->db->trans_status() === FALSE) {
            $this->db->trans_rollback();
            return $this->resposta(FALSE, 'Falha ao lançar a cobrança.');
        }

        $this->db->trans_commit();

        return $this->resposta(TRUE, $geradas . ' parcela(s) gerada(s).', [
            'id_charge' => (int) $idCharge,
            'geradas' => $geradas,
        ]);
    }

    /**
     * Cancela a cobrança e as parcelas ainda ABERTAS.
     *
     * As pagas ficam como estão: o dinheiro entrou, e reescrever a situação
     * delas apagaria o registro de um pagamento que existiu. As canceladas já
     * estão no estado final.
     *
     * A cobrança nunca é apagada, só cancelada — é registro financeiro, mesma
     * regra das FKs RESTRICT de `crm_invoices`. É também o que sustenta a
     * sentinela `id_charge = 0` viver sem FK.
     *
     * @param  int $idCharge
     * @param  int $idCompany
     * @param  int $idUser
     * @return array success, message, data => ['canceladas']
     */
    public function cancelar($idCharge, $idCompany, $idUser)
    {
        $cobranca = $this->global_model->getWhere_off('crm_contracts_charges', [
            'id' => (int) $idCharge,
            'id_company' => (int) $idCompany,
        ], TRUE);

        if (empty($cobranca)) {
            return $this->resposta(FALSE, 'Cobrança não encontrada.');
        }

        if ((string) $cobranca->status === 'cancelada') {
            return $this->resposta(FALSE, 'Esta cobrança já está cancelada.');
        }

        $agora = date('Y-m-d H:i:s');

        $this->db->trans_begin();

        $this->global_model->edit('crm_contracts_charges', [
            'status' => 'cancelada',
            'modified' => $agora,
            'modified_by' => (int) $idUser,
        ], 'id', (int) $cobranca->id);

        $this->db->query(
            "UPDATE crm_invoices
                SET status = 'cancelada', modified = ?, modified_by = ?
              WHERE id_charge = ? AND id_company = ? AND status = 'aberta'",
            [$agora, (int) $idUser, (int) $cobranca->id, (int) $idCompany]
        );

        $canceladas = (int) $this->db->affected_rows();

        if ($this->db->trans_status() === FALSE) {
            $this->db->trans_rollback();
            return $this->resposta(FALSE, 'Falha ao cancelar a cobrança.');
        }

        $this->db->trans_commit();

        return $this->resposta(TRUE, 'Cobrança cancelada; ' . $canceladas . ' parcela(s) em aberto cancelada(s).', [
            'canceladas' => $canceladas,
        ]);
    }

    /**
     * Cobranças de um contrato, da mais recente para a mais antiga.
     *
     * @param  int $idContract
     * @param  int $idCompany
     * @return array
     */
    public function listarPorContrato($idContract, $idCompany)
    {
        $consulta = $this->db->query(
            "SELECT * FROM crm_contracts_charges_v
              WHERE id_contract = ? AND id_company = ?
              ORDER BY created DESC, id DESC",
            [(int) $idContract, (int) $idCompany]
        );

        return ($consulta === FALSE) ? [] : $consulta->result();
    }

    /**
     * @param  bool   $success
     * @param  string $message
     * @param  array  $data
     * @return array
     */
    private function resposta($success, $message, array $data = [])
    {
        return [
            'success' => (bool) $success,
            'message' => (string) $message,
            'data' => $data,
        ];
    }
}
