<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Motor de geração das faturas recorrentes do CDW Finance.
 *
 * Só vê contratos com `billing_source = 'cdwfinance'`: os demais continuam
 * sendo cobrados pelo ERP Bom Controle, e gerar fatura aqui para eles cobraria
 * o cliente duas vezes pelo mesmo serviço.
 *
 * Duas ideias sustentam a rotina:
 *
 *  - `crm_contracts.next_competence` é a ÂNCORA. O motor não calcula "que mês
 *    deveria ser" a partir de hoje; ele lê onde o contrato parou e avança. É
 *    isso que torna a geração retomável — um contrato que ficou meses sem rodar
 *    gera as competências pendentes na ordem, cada uma com o seu vencimento, em
 *    vez de uma fatura só com valor acumulado.
 *
 *  - a UNIQUE (`id_contract`, `competence`) é a rede de segurança. A verificação
 *    em PHP evita a query desnecessária, mas quem garante que duas execuções
 *    concorrentes não gerem cobrança dupla é o banco.
 *
 * Roda pelo cron, que não tem sessão: nada aqui pode tocar `$this->session`. O
 * tenant vem do contrato e o autor das linhas é o `id_user_process_auto`.
 */
class Invoice_model extends CI_Model
{
    /** Antecedência padrão, quando o parâmetro não está gravado. */
    const DIAS_ANTECEDENCIA_PADRAO = 10;

    /**
     * Teto de competências geradas por contrato numa única rodada.
     *
     * Um contrato migrado com `next_competence` retroativa precisa gerar o
     * histórico pendente, mas um valor corrompido no campo não pode virar laço
     * infinito criando faturas.
     *
     * Conta COMPETÊNCIAS, não faturas: com parcelamento cada iteração cria até
     * `installments` linhas. O teto real vem da guarda `installments <= meses do
     * ciclo` — no pior caso (anual em 12×) são 144 faturas, o que exigiria 12
     * anos de atraso. Não precisa de teto próprio.
     */
    const MAX_COMPETENCIAS_POR_RODADA = 12;

    /**
     * Faturas por página nas abas do contrato e do cliente.
     *
     * Menor que o PER_PAGE da tela de Faturas (30) de propósito: ali a
     * listagem é a página inteira, e aqui ela divide o card com outras abas —
     * 30 linhas empurrariam o resto da tela para fora da dobra.
     */
    const PER_PAGE_ABA = 10;

    /** Meses que cada ciclo avança. Espelha Contratos::cycles(). */
    private $mesesPorCiclo = [
        'mensal' => 1,
        'bimestral' => 2,
        'trimestral' => 3,
        'quadrimestral' => 4,
        'semestral' => 6,
        'anual' => 12,
    ];

    public function __construct()
    {
        parent::__construct();
        $this->load->model('general_settings_model');
    }

    // ------------------------------------------------------------------
    // Rodada do cron
    // ------------------------------------------------------------------

    /**
     * Contratos elegíveis à geração, de todos os tenants.
     *
     * `billing_day > 0` é guarda contra contrato virado para cá sem o dia
     * definido: gerar com vencimento arbitrário seria pior que não gerar.
     * Suspenso e encerrado ficam de fora — "suspenso por inadimplência" existe
     * justamente para parar de cobrar.
     *
     * @return array
     */
    public function getBillableContracts()
    {
        $sql = "
            SELECT id, id_customer, id_company, cycle, value, billing_day,
                   next_competence, installments, invoice_policy
              FROM crm_contracts
             WHERE status = 'vigente'
               AND billing_source = 'cdwfinance'
               AND billing_day > 0
               AND next_competence IS NOT NULL
             ORDER BY id ASC
        ";

        $consulta = $this->db->query($sql);
        return ($consulta === FALSE) ? [] : $consulta->result();
    }

    /**
     * Gera as faturas pendentes de um contrato.
     *
     * @param  object   $contrato linha de crm_contracts
     * @param  int      $idUser   autor das linhas
     * @param  int|null $antecedencia dias; NULL = parâmetro global
     * @return array success, message, data => ['geradas', 'competencias']
     */
    public function generateForContract($contrato, $idUser, $antecedencia = NULL)
    {
        $meses = $this->mesesDoCiclo($contrato->cycle);
        if ($meses === 0) {
            return $this->resposta(FALSE, 'Ciclo desconhecido: ' . $contrato->cycle, 0, []);
        }

        $diaVencimento = (int) $contrato->billing_day;
        if ($diaVencimento < 1 || $diaVencimento > 31) {
            return $this->resposta(FALSE, 'Dia de vencimento inválido no contrato.', 0, []);
        }

        if ($antecedencia === NULL) {
            $antecedencia = $this->diasAntecedencia();
        }

        $competencia = $this->primeiroDiaDoMes((string) $contrato->next_competence);
        if ($competencia === NULL) {
            return $this->resposta(FALSE, 'Competência inicial inválida no contrato.', 0, []);
        }

        // O limite é o VENCIMENTO, não a competência: a fatura precisa existir
        // antes de vencer para dar tempo de emitir e enviar o boleto.
        $limite = date('Y-m-d', strtotime('+' . (int) $antecedencia . ' days'));

        // Parcelas em que o valor do ciclo é dividido. O teto é o número de
        // meses do ciclo (guarda no controller): parcela que passa do ciclo
        // invadiria a competência seguinte e criaria sobreposição sem fim.
        $parcelas = max(1, (int) (isset($contrato->installments) ? $contrato->installments : 1));
        $parcelas = min($parcelas, $meses);

        $valores = $this->valoresDasParcelas((float) $contrato->value, $parcelas);

        // Uma vez, fora dos dois laços: com 12 competências × N parcelas isso
        // seriam até 144 consultas para montar sempre o mesmo texto.
        $descricao = $this->descricaoDoContrato((int) $contrato->id);

        $geradas = 0;
        $competencias = [];

        for ($i = 0; $i < self::MAX_COMPETENCIAS_POR_RODADA; $i++) {
            // A competência abre quando a PRIMEIRA parcela entra na janela — é
            // a mesma conta de antes, porque a parcela 1 vence no mês da
            // competência. As demais nascem junto, com vencimento à frente.
            $vencimento = $this->vencimentoDaCompetencia($competencia, $diaVencimento);
            if ($vencimento > $limite) {
                break;
            }

            $criadasNaCompetencia = 0;

            for ($n = 1; $n <= $parcelas; $n++) {
                $resultado = $this->criarFatura(
                    $contrato,
                    $competencia,
                    $this->vencimentoDaParcela($competencia, $diaVencimento, $n),
                    $idUser,
                    [
                        'numero' => $n,
                        'total' => $parcelas,
                        'valor' => $valores[$n - 1],
                        'id_charge' => 0,
                        'description' => $descricao,
                        'invoice_policy' => (string) $contrato->invoice_policy,
                    ]
                );

                if (!$resultado['success']) {
                    // Não avança next_competence: a próxima rodada refaz a
                    // competência inteira. As parcelas já criadas são puladas
                    // pela checagem de existência e as que faltam nascem — o
                    // caminho se cura sozinho, e é por isso que não há
                    // transação em volta dos N inserts.
                    return $this->resposta(FALSE, $resultado['message'], $geradas, $competencias);
                }

                if ($resultado['criada']) {
                    $geradas++;
                    $criadasNaCompetencia++;
                }
            }

            if ($criadasNaCompetencia > 0) {
                $competencias[] = $competencia;
            }

            $competencia = $this->avancarCompetencia($competencia, $meses);
            $this->global_model->edit('crm_contracts', ['next_competence' => $competencia], 'id', (int) $contrato->id);
        }

        return $this->resposta(TRUE, '', $geradas, $competencias);
    }

    /**
     * Divide um valor em N parcelas cuja soma bate EXATAMENTE com ele.
     *
     * A conta é feita em centavos inteiros, e não em float: `1000 / 3` em
     * ponto flutuante não fecha, e um erro de arredondamento aqui é dinheiro
     * cobrado a mais ou a menos.
     *
     * O resto vai todo na ÚLTIMA parcela (R$ 1.000 em 3× = 333,33 + 333,33 +
     * 333,34). Sem isso o total cobrado divergiria do contratado em centavos, e
     * conferir a fatura à mão não bateria.
     *
     * @param  float $valor
     * @param  int   $parcelas
     * @return array valores em reais, na ordem das parcelas
     */
    public function valoresDasParcelas($valor, $parcelas)
    {
        $parcelas = max(1, (int) $parcelas);
        $totalCentavos = (int) round((float) $valor * 100);

        if ($parcelas === 1) {
            return [$totalCentavos / 100];
        }

        $base = intdiv($totalCentavos, $parcelas);
        $resto = $totalCentavos - ($base * $parcelas);

        $valores = array_fill(0, $parcelas - 1, $base / 100);
        $valores[] = ($base + $resto) / 100;

        return $valores;
    }

    /**
     * Vencimento da parcela N de uma competência.
     *
     * Reusa os dois helpers de sempre, e com isso herda de graça o limite ao
     * último dia do mês — a parcela 2 de uma competência de janeiro com
     * vencimento no dia 31 cai em 28/29 de fevereiro.
     *
     * @param  string $competencia Y-m-d (dia 1)
     * @param  int    $dia
     * @param  int    $numero      1-based
     * @return string Y-m-d
     */
    public function vencimentoDaParcela($competencia, $dia, $numero)
    {
        $mes = $this->avancarCompetencia($competencia, max(0, (int) $numero - 1));
        return $this->vencimentoDaCompetencia($mes, $dia);
    }

    /**
     * Gera uma competência específica — o botão GERAR FATURA da tela.
     *
     * Diferente da rodada do cron, aqui não há limite de antecedência: quem
     * clica está pedindo a fatura agora.
     *
     * @param  int $idContract
     * @param  int $idCompany
     * @param  int $idUser
     * @return array success, message, data
     */
    public function generateNow($idContract, $idCompany, $idUser)
    {
        $contrato = $this->global_model->getWhere_off('crm_contracts', [
            'id' => (int) $idContract,
            'id_company' => (int) $idCompany,
        ], TRUE);

        if (empty($contrato)) {
            return $this->resposta(FALSE, 'Contrato não encontrado.', 0, []);
        }

        if ((string) $contrato->billing_source !== 'cdwfinance') {
            return $this->resposta(FALSE, 'Este contrato é faturado pelo Bom Controle — ative o faturamento pelo CDW Finance antes de gerar faturas aqui.', 0, []);
        }

        if ((string) $contrato->status !== 'vigente') {
            return $this->resposta(FALSE, 'Só contrato vigente gera fatura.', 0, []);
        }

        // Antecedência folgada: gera a competência corrente mesmo que o
        // vencimento ainda esteja distante.
        return $this->generateForContract($contrato, $idUser, 366);
    }

    // ------------------------------------------------------------------
    // Gravação
    // ------------------------------------------------------------------

    /**
     * Insere UMA parcela de uma competência.
     *
     * A checagem prévia evita o INSERT inútil no caso comum; a UNIQUE
     * `uk_invoices_origem` é quem protege da corrida entre duas execuções. Por
     * isso a violação de chave duplicada é tratada como sucesso-sem-criar, e
     * não como erro: significa que aquela parcela já existe, que é exatamente o
     * estado desejado.
     *
     * Pública porque o `Charge_model` também cria faturas por aqui — este model
     * é o único que escreve em `crm_invoices`, e manter isso vale mais que
     * manter o método privado.
     *
     * @param  object $contrato
     * @param  string $competencia Y-m-d (dia 1)
     * @param  string $vencimento  Y-m-d
     * @param  int    $idUser
     * @param  array  $parcela     numero, total, valor, id_charge, description, invoice_policy
     * @return array success, message, criada
     */
    public function criarFatura($contrato, $competencia, $vencimento, $idUser, array $parcela)
    {
        $numero = max(1, (int) $parcela['numero']);
        $total = max(1, (int) $parcela['total']);
        $idCharge = isset($parcela['id_charge']) ? (int) $parcela['id_charge'] : 0;

        // As quatro colunas da UNIQUE: sem o id_charge e o número da parcela,
        // a avulsa de janeiro seria confundida com a recorrência de janeiro.
        $existente = $this->global_model->getFieldsWhereSingle_off(
            'crm_invoices',
            'id',
            [
                'id_contract' => (int) $contrato->id,
                'id_charge' => $idCharge,
                'competence' => $competencia,
                'installment_number' => $numero,
            ],
            TRUE
        );

        if (!empty($existente)) {
            return ['success' => TRUE, 'message' => '', 'criada' => FALSE];
        }

        $dados = [
            'id_company' => (int) $contrato->id_company,
            'id_customer' => (int) $contrato->id_customer,
            'id_contract' => (int) $contrato->id,
            'id_charge' => $idCharge,
            'competence' => $competencia,
            'due_date' => $vencimento,
            // Congelados: um reajuste em março não pode reescrever o que foi
            // cobrado em janeiro, e o total de parcelas descreve o acordo
            // vigente na geração — mudá-lo depois reescreveria o passado.
            'value' => (float) $parcela['valor'],
            'installment_number' => $numero,
            'installments_total' => $total,
            'status' => 'aberta',
            'description' => isset($parcela['description']) && $parcela['description'] !== NULL
                ? $parcela['description']
                : $this->descricaoDoContrato((int) $contrato->id),
            'invoice_policy' => isset($parcela['invoice_policy']) && $parcela['invoice_policy'] !== NULL
                ? (string) $parcela['invoice_policy']
                : (string) $contrato->invoice_policy,
            'created' => date('Y-m-d H:i:s'),
            'created_by' => (int) $idUser,
        ];

        // O CI3 sobe com db_debug ligado fora de produção, e ali o driver mata
        // o processo no erro de query — o ramo do 1062 abaixo nunca seria
        // alcançado. Com N inserts por competência a corrida ficou mais
        // provável, então o modo é silenciado e restaurado, como faz o
        // Import_gestor_model.
        $debugAnterior = $this->db->db_debug;
        $this->db->db_debug = FALSE;

        try {
            $id = $this->global_model->add('crm_invoices', $dados);

            if (empty($id)) {
                $erro = $this->db->error();
                // 1062 = Duplicate entry: outra execução gerou a mesma parcela
                // entre a checagem e o insert. O estado final é o correto.
                if (isset($erro['code']) && (int) $erro['code'] === 1062) {
                    return ['success' => TRUE, 'message' => '', 'criada' => FALSE];
                }

                $mensagem = isset($erro['message']) && $erro['message'] !== ''
                    ? $erro['message']
                    : 'falha ao gravar a fatura';

                return ['success' => FALSE, 'message' => $mensagem, 'criada' => FALSE];
            }
        } finally {
            $this->db->db_debug = $debugAnterior;
        }

        return ['success' => TRUE, 'message' => '', 'criada' => TRUE];
    }

    /**
     * Tipos de serviço do contrato, congelados no texto da fatura.
     *
     * @param  int $idContract
     * @return string
     */
    private function descricaoDoContrato($idContract)
    {
        $linhas = $this->global_model->getWhere_off(
            'crm_contracts_services_v',
            ['id_contract' => (int) $idContract],
            FALSE
        );

        $nomes = [];
        foreach ((array) $linhas as $linha) {
            $nome = trim((string) $linha->service_type_name);
            if ($nome !== '') $nomes[] = $nome;
        }

        if (empty($nomes)) {
            return 'Serviços contratados';
        }

        return mb_substr(implode(', ', $nomes), 0, 255);
    }

    // ------------------------------------------------------------------
    // Datas
    // ------------------------------------------------------------------

    /**
     * Vencimento da competência, limitado ao último dia do mês.
     *
     * Dia 31 em fevereiro vira 28 (ou 29). Sem esse limite, montar a data com
     * `mktime`/`strtotime` transborda para o mês seguinte e o vencimento salta
     * para 3 de março — o mesmo tipo de erro que a janela do Dashboard evita
     * ancorando no dia 1 antes de somar meses.
     *
     * @param  string $competencia Y-m-d (dia 1)
     * @param  int    $dia
     * @return string Y-m-d
     */
    public function vencimentoDaCompetencia($competencia, $dia)
    {
        $ano = (int) substr($competencia, 0, 4);
        $mes = (int) substr($competencia, 5, 2);

        $ultimoDia = (int) date('t', mktime(0, 0, 0, $mes, 1, $ano));
        $diaFinal = min((int) $dia, $ultimoDia);

        return sprintf('%04d-%02d-%02d', $ano, $mes, $diaFinal);
    }

    /**
     * Avança N meses SEMPRE a partir do dia 1 — nunca somando mês sobre uma
     * data já ajustada, que é como 31/01 vira 03/03.
     *
     * @param  string $competencia Y-m-d
     * @param  int    $meses
     * @return string Y-m-d (dia 1)
     */
    public function avancarCompetencia($competencia, $meses)
    {
        $ano = (int) substr($competencia, 0, 4);
        $mes = (int) substr($competencia, 5, 2);

        $total = ($ano * 12) + ($mes - 1) + (int) $meses;

        return sprintf('%04d-%02d-01', intdiv($total, 12), ($total % 12) + 1);
    }

    /**
     * @param  string $data Y-m-d
     * @return string|null dia 1 do mês, ou NULL se a data não for válida
     */
    public function primeiroDiaDoMes($data)
    {
        $data = substr(trim((string) $data), 0, 10);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $data, $partes)) {
            return NULL;
        }

        if (!checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1])) {
            return NULL;
        }

        return $partes[1] . '-' . $partes[2] . '-01';
    }

    /**
     * @param  string $cycle
     * @return int meses; 0 = ciclo desconhecido
     */
    public function mesesDoCiclo($cycle)
    {
        $cycle = (string) $cycle;
        return isset($this->mesesPorCiclo[$cycle]) ? $this->mesesPorCiclo[$cycle] : 0;
    }

    // ------------------------------------------------------------------
    // Parâmetros e apoio
    // ------------------------------------------------------------------

    /**
     * @return int
     */
    public function diasAntecedencia()
    {
        $valor = $this->general_settings_model->getGroupValue('faturamento', 'faturamento_dias_antecedencia', '');
        $valor = (int) $valor;

        return ($valor > 0) ? $valor : self::DIAS_ANTECEDENCIA_PADRAO;
    }

    /**
     * Dia de vencimento sugerido ao ligar o faturamento de um contrato.
     *
     * @return int
     */
    public function diaPadrao()
    {
        $valor = (int) $this->general_settings_model->getGroupValue('faturamento', 'faturamento_dia_padrao', '');
        return ($valor >= 1 && $valor <= 31) ? $valor : self::DIAS_ANTECEDENCIA_PADRAO;
    }

    /**
     * Faturas vencidas e ainda abertas do tenant — alimenta o contador do menu.
     *
     * @param  int $idCompany
     * @return int
     */
    public function countOverdue($idCompany)
    {
        $consulta = $this->db->query(
            "SELECT COUNT(*) AS total
               FROM crm_invoices
              WHERE id_company = ?
                AND status = 'aberta'
                AND due_date < CURDATE()",
            [(int) $idCompany]
        );

        if ($consulta === FALSE) return 0;

        $linha = $consulta->row();
        return empty($linha) ? 0 : (int) $linha->total;
    }

    // ------------------------------------------------------------------
    // Abas de faturas (contrato e cliente)
    // ------------------------------------------------------------------

    /**
     * Uma página de faturas do contrato ou do cliente.
     *
     * Paginado no BANCO, e não no navegador: um contrato mensal ativo há cinco
     * anos tem 60 faturas, e um cliente com três contratos, 180 — mandar tudo
     * de uma vez para a aba filtrar em JS cresce sem teto e sem aviso.
     *
     * O escopo NUNCA entra no SQL vindo de fora: `$escopo` é traduzido pela
     * allowlist abaixo, e escopo desconhecido é erro em vez de "traz tudo" —
     * mesma regra das faixas do card de domínios do Dashboard. O `id_company`
     * acompanha as duas consultas: a coluna de escopo sozinha atravessaria
     * tenants se um id fosse forjado no POST.
     *
     * @param  string $escopo    'contrato' | 'cliente'
     * @param  int    $id        id do contrato ou do cliente
     * @param  int    $idCompany tenant
     * @param  int    $pagina    1-based
     * @param  int    $porPagina
     * @return array|null NULL quando o escopo é desconhecido
     */
    public function listarPorEscopo($escopo, $id, $idCompany, $pagina = 1, $porPagina = self::PER_PAGE_ABA)
    {
        $colunas = ['contrato' => 'id_contract', 'cliente' => 'id_customer'];
        if (!isset($colunas[(string) $escopo])) return NULL;

        $coluna = $colunas[(string) $escopo];
        $binds = [(int) $idCompany, (int) $id];

        // Totais na mesma pergunta que a contagem: "quanto falta receber" é o
        // que se quer saber numa lista de faturas, e a paginação esconde a
        // resposta. Sai de uma query só — um COUNT à parte pagaria duas
        // varreduras pelo mesmo recorte.
        $consulta = $this->db->query(
            "SELECT COUNT(*) AS quantidade,
                    COALESCE(SUM(value), 0) AS total,
                    COALESCE(SUM(CASE WHEN situation IN ('a_vencer', 'vencida') THEN value ELSE 0 END), 0) AS aberto,
                    COALESCE(SUM(CASE WHEN situation = 'vencida' THEN value ELSE 0 END), 0) AS vencido
               FROM crm_invoices_v
              WHERE id_company = ? AND {$coluna} = ?",
            $binds
        );

        $resumo = ($consulta === FALSE) ? NULL : $consulta->row();

        $total = empty($resumo) ? 0 : (int) $resumo->quantidade;
        $porPagina = max(1, (int) $porPagina);
        $paginas = (int) ceil($total / $porPagina);

        // Página fora do intervalo é GRUDADA na última, e não devolvida vazia:
        // quem estava na página 4 quando a última fatura foi cancelada veria
        // uma tabela vazia e concluiria que não há faturas.
        $pagina = max(1, (int) $pagina);
        if ($paginas > 0 && $pagina > $paginas) $pagina = $paginas;

        $itens = [];
        if ($total > 0) {
            // LIMIT/OFFSET por interpolação de INTEIRO já convertido: o driver
            // manda bind como string ('10'), e `LIMIT '10'` é erro de sintaxe.
            $offset = ($pagina - 1) * $porPagina;

            $consulta = $this->db->query(
                "SELECT id, id_contract, id_customer, competence, due_date, value,
                        status, situation, description, invoice_policy,
                        id_charge, installment_number, installments_total,
                        charge_description, contract_cycle, contract_status, created
                   FROM crm_invoices_v
                  WHERE id_company = ? AND {$coluna} = ?
                  ORDER BY due_date DESC, id DESC
                  LIMIT " . (int) $porPagina . " OFFSET " . (int) $offset,
                $binds
            );

            if ($consulta !== FALSE) $itens = $consulta->result();
        }

        return [
            'itens' => $itens,
            'pagina' => $pagina,
            'paginas' => $paginas,
            'por_pagina' => $porPagina,
            'total' => $total,
            'valor_total' => empty($resumo) ? 0.0 : (float) $resumo->total,
            'valor_aberto' => empty($resumo) ? 0.0 : (float) $resumo->aberto,
            'valor_vencido' => empty($resumo) ? 0.0 : (float) $resumo->vencido,
        ];
    }

    /**
     * Rótulos das situações derivadas pela `crm_invoices_v`.
     *
     * Espelha Faturas::situations(); vive aqui também porque as abas de
     * contrato e cliente montam a tabela sem passar por aquele controller.
     *
     * @return array slug => rótulo
     */
    public function situations()
    {
        return [
            'a_vencer' => 'A vencer',
            'vencida' => 'Vencida',
            'paga' => 'Paga',
            'cancelada' => 'Cancelada',
        ];
    }

    /**
     * @param  bool   $success
     * @param  string $message
     * @param  int    $geradas
     * @param  array  $competencias
     * @return array
     */
    private function resposta($success, $message, $geradas, array $competencias)
    {
        return [
            'success' => (bool) $success,
            'message' => (string) $message,
            'data' => [
                'geradas' => (int) $geradas,
                'competencias' => $competencias,
            ],
        ];
    }
}
