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
     * Cancela a cobrança avulsa e as parcelas ainda em aberto.
     *
     * ⚠️ **CANCELAR A PARCELA É CANCELAR O BOLETO PRIMEIRO.** Até 30/08/2026
     * este método marcava as parcelas como canceladas com um `UPDATE` direto e
     * nunca tocava no PSP — deixando boletos vivos e perfeitamente pagáveis no
     * banco enquanto a fatura constava cancelada aqui. É exatamente o buraco
     * que o cancelamento de fatura existe para fechar: o cliente paga, o
     * dinheiro entra, e do lado de cá não há nada a conciliar.
     *
     * A regra é a MESMA do `Faturas::derrubarCobranca()`, e por isso passa pelo
     * mesmo `Psp_model::cancelarCobranca()` — que decide sozinho o que fazer
     * quando não há cobrança registrada (sucesso, sem nada a cancelar) e é
     * quem apaga boleto, PIX e `psp_charge_id` da linha.
     *
     * **A ORDEM É POR PARCELA, e é o que torna a repetição segura**: cada uma
     * só é marcada como cancelada DEPOIS de o banco confirmar a derrubada do
     * boleto. Falhando uma, as anteriores continuam corretamente canceladas
     * (boleto fora, fatura fechada), a que falhou continua **em aberto com o
     * boleto de pé** — que é a verdade — e a COBRANÇA não é marcada como
     * cancelada. Repetir a operação retoma exatamente de onde parou, porque o
     * laço só olha para as parcelas `aberta`.
     *
     * **Não há mais transação em volta do laço**, de propósito: ela agora
     * envolveria chamadas de rede, segurando lock de banco por dezenas de
     * segundos. A atomicidade que importa é a de cada parcela, e essa é
     * garantida pela ordem.
     *
     * O espaçamento entre chamadas existe porque o rate limit do Inter estoura
     * em ~6 seguidas, e uma avulsa pode ter até `MAX_PARCELAS` (24).
     *
     * @param  int $idCharge
     * @param  int $idCompany
     * @param  int $idUser
     * @return array
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

        $abertas = $this->db->query(
            "SELECT id FROM crm_invoices
              WHERE id_charge = ? AND id_company = ? AND status = 'aberta'
              ORDER BY installment_number ASC, id ASC",
            [(int) $cobranca->id, (int) $idCompany]
        );

        $abertas = ($abertas === FALSE) ? [] : $abertas->result();

        $this->load->model('psp_model');

        $agora = date('Y-m-d H:i:s');
        $canceladas = 0;
        $boletos = 0;
        $falhas = [];

        foreach ($abertas as $i => $fatura) {
            if ($i > 0) usleep(Psp_model::ESPACAMENTO_COBRANCAS_MICROSSEGUNDOS);

            $r = $this->psp_model->cancelarCobranca(
                (int) $fatura->id,
                (int) $idCompany,
                'Cobrança avulsa cancelada no CDW Finance',
                $idUser
            );

            if (empty($r['success'])) {
                // Fica em aberto, com o boleto vivo — que é a verdade. Marcar
                // como cancelada aqui é justamente o defeito que este método
                // deixou de ter.
                $falhas[] = '#' . (int) $fatura->id . ': ' . $r['message'];
                continue;
            }

            if (!empty($r['data']['cancelou'])) $boletos++;

            $this->global_model->edit('crm_invoices', [
                'status' => 'cancelada',
                'modified' => $agora,
                'modified_by' => (int) $idUser,
            ], 'id', (int) $fatura->id);

            $canceladas++;
        }

        if (!empty($falhas)) {
            return $this->resposta(FALSE, sprintf(
                'A cobrança NÃO foi cancelada: %d de %d parcela(s) foram canceladas e %d falhou(ram) no banco — %s'
                . ' As que falharam continuam EM ABERTO, com o boleto de pé: cancelar só aqui deixaria o cliente conseguindo pagá-lo.'
                . ' Repita a operação em instantes; ela retoma de onde parou.',
                $canceladas,
                count($abertas),
                count($falhas),
                implode(' | ', $falhas)
            ), ['canceladas' => $canceladas, 'falhas' => count($falhas)]);
        }

        $this->global_model->edit('crm_contracts_charges', [
            'status' => 'cancelada',
            'modified' => $agora,
            'modified_by' => (int) $idUser,
        ], 'id', (int) $cobranca->id);

        // "Cancelei N boletos" é diferente de "não havia boleto nenhum", e a
        // segunda não deixa dúvida sobre o que o cliente tem em mãos.
        return $this->resposta(TRUE, sprintf(
            'Cobrança cancelada; %d parcela(s) em aberto cancelada(s)%s.',
            $canceladas,
            $boletos > 0 ? ', ' . $boletos . ' com boleto derrubado no banco' : ' (nenhuma tinha boleto registrado)'
        ), ['canceladas' => $canceladas, 'boletos' => $boletos]);
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
