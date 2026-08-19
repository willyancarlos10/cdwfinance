<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Reajuste anual dos contratos por índice (IGPM, IPCA, ICTI) e o aviso prévio
 * ao cliente.
 *
 * O reajuste acontece no aniversário do contrato e aplica o índice acumulado
 * dos doze meses anteriores. Três decisões sustentam a rotina:
 *
 *  - O acumulado é COMPOSTO, nunca a soma das doze variações mensais. Somar
 *    superestima o reajuste (doze meses de 1% dão 12,6825%, não 12%) e a
 *    diferença é dinheiro cobrado a mais do cliente.
 *
 *  - Janela incompleta NÃO reajusta. Faltando qualquer uma das doze
 *    competências, o contrato é pulado e tenta de novo na próxima rodada. Um
 *    acumulado calculado sobre nove dos doze meses é um número plausível e
 *    errado — vira cobrança errada e só aparece quando o cliente reclama.
 *
 *  - Valor, histórico e próxima data mudam JUNTOS, em transação. Um `value`
 *    atualizado sem a linha de histórico é exatamente a pergunta sem resposta
 *    ("por que subiu de R$ 200 para R$ 214,60?") que a tabela existe para
 *    evitar.
 *
 * Só toca contratos com `billing_source = 'cdwfinance'`: reajustar o valor de
 * um contrato que o ERP cobra mudaria o número na tela sem mudar a cobrança
 * real, criando divergência silenciosa entre os dois sistemas.
 *
 * Roda pelo cron, que não tem sessão: nada aqui pode tocar `$this->session`.
 */
class Adjustment_model extends CI_Model
{
    /** Meses da janela do acumulado. */
    const MESES_JANELA = 12;

    /** Antecedência padrão do aviso, quando o parâmetro não está gravado. */
    const DIAS_AVISO_PADRAO = 30;

    /** Assunto padrão do e-mail, quando o parâmetro não está gravado. */
    const ASSUNTO_PADRAO = 'Reajuste anual do seu contrato';

    public function __construct()
    {
        parent::__construct();
        $this->load->model('general_settings_model');
    }

    /**
     * Catálogo dos índices. `nenhum` não entra: ele é a ausência de reajuste.
     *
     * @return array slug => rótulo
     */
    public function indexes()
    {
        return [
            'igpm' => 'IGP-M',
            'ipca' => 'IPCA',
            'icti' => 'ICTI',
        ];
    }

    // ------------------------------------------------------------------
    // Seleção
    // ------------------------------------------------------------------

    /**
     * Contratos com reajuste vencido, de todos os tenants.
     *
     * @return array
     */
    public function getDueContracts()
    {
        // `adjustment_notified_for` entra no SELECT porque applyForContract()
        // o consulta para carimbar `notified` no histórico. Sem ele, todo
        // reajuste era gravado como "sem aviso prévio", inclusive os avisados.
        $sql = "
            SELECT id, id_customer, id_company, cycle, value,
                   adjustment_index, next_adjustment, adjustment_notified_for
              FROM crm_contracts
             WHERE status = 'vigente'
               AND billing_source = 'cdwfinance'
               AND adjustment_index <> 'nenhum'
               AND next_adjustment IS NOT NULL
               AND next_adjustment <= CURDATE()
             ORDER BY id ASC
        ";

        $consulta = $this->db->query($sql);
        return ($consulta === FALSE) ? [] : $consulta->result();
    }

    /**
     * Contratos que entram na janela de aviso e ainda não foram avisados.
     *
     * A comparação é `adjustment_notified_for <> next_adjustment`, e não um
     * booleano: se a data do reajuste for remarcada, o cliente precisa ser
     * avisado de novo — um flag ligado impediria isso para sempre.
     *
     * @return array
     */
    public function getContractsToNotify()
    {
        $dias = $this->diasAviso();

        $sql = "
            SELECT id, id_customer, id_company, cycle, value,
                   adjustment_index, next_adjustment
              FROM crm_contracts
             WHERE status = 'vigente'
               AND billing_source = 'cdwfinance'
               AND adjustment_index <> 'nenhum'
               AND next_adjustment IS NOT NULL
               AND next_adjustment <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
               AND (adjustment_notified_for IS NULL
                    OR adjustment_notified_for <> next_adjustment)
             ORDER BY id ASC
        ";

        $consulta = $this->db->query($sql, [(int) $dias]);
        return ($consulta === FALSE) ? [] : $consulta->result();
    }

    // ------------------------------------------------------------------
    // Cálculo
    // ------------------------------------------------------------------

    /**
     * Acumulado composto dos doze meses encerrados ANTES do mês do reajuste.
     *
     * Reajuste em 15/03/2026 usa as competências de 03/2025 a 02/2026 — o mês
     * corrente ainda não foi publicado.
     *
     * @param  string $indice slug
     * @param  string $dataReajuste Y-m-d
     * @return array success, message, rate, inicio, fim, faltando
     */
    public function acumulado($indice, $dataReajuste)
    {
        $fim = $this->mesAnterior($dataReajuste);
        if ($fim === NULL) {
            return $this->resultadoAcumulado(FALSE, 'Data de reajuste inválida.', 0, '', '', []);
        }

        $inicio = $this->somarMeses($fim, -(self::MESES_JANELA - 1));

        $linhas = $this->db->query(
            "SELECT competence, rate
               FROM crm_adjustment_indexes
              WHERE index_slug = ?
                AND competence BETWEEN ? AND ?
              ORDER BY competence ASC",
            [(string) $indice, $inicio, $fim]
        );

        $porMes = [];
        if ($linhas !== FALSE) {
            foreach ($linhas->result() as $linha) {
                $porMes[substr((string) $linha->competence, 0, 7)] = (float) $linha->rate;
            }
        }

        // Confere mês a mês em vez de contar linhas: doze linhas com um mês
        // repetido e outro ausente passariam por uma contagem simples.
        $faltando = [];
        $fator = 1.0;
        $competencia = $inicio;

        for ($i = 0; $i < self::MESES_JANELA; $i++) {
            $chave = substr($competencia, 0, 7);
            if (!array_key_exists($chave, $porMes)) {
                $faltando[] = $chave;
            } else {
                $fator *= (1 + ($porMes[$chave] / 100));
            }
            $competencia = $this->somarMeses($competencia, 1);
        }

        if (!empty($faltando)) {
            return $this->resultadoAcumulado(
                FALSE,
                'Faltam competências do índice ' . mb_strtoupper($indice) . ': ' . implode(', ', $faltando) . '.',
                0,
                $inicio,
                $fim,
                $faltando
            );
        }

        $rate = round(($fator - 1) * 100, 4);

        return $this->resultadoAcumulado(TRUE, '', $rate, $inicio, $fim, []);
    }

    // ------------------------------------------------------------------
    // Aplicação
    // ------------------------------------------------------------------

    /**
     * Aplica o reajuste de um contrato.
     *
     * @param  object $contrato linha de crm_contracts
     * @param  int    $idUser
     * @return array success, message, data
     */
    public function applyForContract($contrato, $idUser)
    {
        $indice = (string) $contrato->adjustment_index;
        if (!array_key_exists($indice, $this->indexes())) {
            return ['success' => FALSE, 'message' => 'Índice desconhecido: ' . $indice, 'data' => NULL];
        }

        $dataReajuste = substr((string) $contrato->next_adjustment, 0, 10);
        $acumulado = $this->acumulado($indice, $dataReajuste);

        if (!$acumulado['success']) {
            // next_adjustment fica onde está: a próxima rodada tenta de novo
            // assim que o índice for lançado.
            return ['success' => FALSE, 'message' => $acumulado['message'], 'data' => NULL];
        }

        $valorAntes = (float) $contrato->value;

        // O novo valor sai do `rate` JÁ ARREDONDADO a 4 casas — o mesmo número
        // que vai para o histórico e para o e-mail do cliente. Usar o fator
        // bruto daria alguns centavos de diferença, e aí conferir
        // "1.000,00 − 11,3615%" à mão não bateria com o que foi cobrado.
        $valorDepois = round($valorAntes * (1 + ($acumulado['rate'] / 100)), 2);

        // Deflação é aceita — é o que o índice diz. Valor zerado ou negativo
        // não é: seria uma cobrança sem sentido gravada em silêncio.
        if ($valorDepois <= 0) {
            return [
                'success' => FALSE,
                'message' => 'O reajuste resultaria em valor menor ou igual a zero (' . $acumulado['rate'] . '%).',
                'data' => NULL,
            ];
        }

        $proxima = $this->somarMesesData($dataReajuste, self::MESES_JANELA);

        $this->db->trans_begin();

        $this->global_model->add('crm_contracts_adjustments', [
            'id_contract' => (int) $contrato->id,
            'id_company' => (int) $contrato->id_company,
            'applied_at' => $dataReajuste,
            'index_slug' => $indice,
            'rate' => $acumulado['rate'],
            'competence_start' => $acumulado['inicio'],
            'competence_end' => $acumulado['fim'],
            'value_before' => $valorAntes,
            'value_after' => $valorDepois,
            'notified' => $this->notificadoEm($contrato),
            'created' => date('Y-m-d H:i:s'),
            'created_by' => (int) $idUser,
        ]);

        $this->global_model->edit('crm_contracts', [
            'value' => $valorDepois,
            'next_adjustment' => $proxima,
            'modified' => date('Y-m-d H:i:s'),
            'modified_by' => (int) $idUser,
        ], 'id', (int) $contrato->id);

        if ($this->db->trans_status() === FALSE) {
            $this->db->trans_rollback();
            return ['success' => FALSE, 'message' => 'Falha ao gravar o reajuste.', 'data' => NULL];
        }

        $this->db->trans_commit();

        return [
            'success' => TRUE,
            'message' => '',
            'data' => [
                'rate' => $acumulado['rate'],
                'value_before' => $valorAntes,
                'value_after' => $valorDepois,
                'next_adjustment' => $proxima,
                'avisado' => ($this->notificadoEm($contrato) !== NULL),
            ],
        ];
    }

    /**
     * O aviso é anterior ao reajuste, então na hora de aplicar já deve existir.
     * Devolve NULL quando o cliente não foi avisado — o cron registra isso no
     * log, porque reajustar sem aviso prévio é exceção, não rotina.
     *
     * @param  object $contrato
     * @return string|null
     */
    private function notificadoEm($contrato)
    {
        // isset() e não acesso direto: quem chama pode ter montado o objeto com
        // um SELECT parcial, e um notice de propriedade ausente viraria "não
        // avisado" em silêncio — que é o oposto do que o campo deve informar.
        $avisadoPara = isset($contrato->adjustment_notified_for)
            ? substr((string) $contrato->adjustment_notified_for, 0, 10)
            : '';
        $reajuste = substr((string) $contrato->next_adjustment, 0, 10);

        return ($avisadoPara !== '' && $avisadoPara === $reajuste) ? date('Y-m-d H:i:s') : NULL;
    }

    // ------------------------------------------------------------------
    // Aviso ao cliente
    // ------------------------------------------------------------------

    /**
     * Enfileira o aviso de reajuste do contrato.
     *
     * @param  object $contrato linha de crm_contracts
     * @param  int    $idUser
     * @return array success, message, data
     */
    public function notifyContract($contrato, $idUser)
    {
        $indice = (string) $contrato->adjustment_index;
        $rotulos = $this->indexes();
        if (!array_key_exists($indice, $rotulos)) {
            return ['success' => FALSE, 'message' => 'Índice desconhecido: ' . $indice, 'data' => NULL];
        }

        $dataReajuste = substr((string) $contrato->next_adjustment, 0, 10);
        $acumulado = $this->acumulado($indice, $dataReajuste);

        if (!$acumulado['success']) {
            return ['success' => FALSE, 'message' => $acumulado['message'], 'data' => NULL];
        }

        $cliente = $this->global_model->getWhere_off('crm_customers', ['id' => (int) $contrato->id_customer], TRUE);
        if (empty($cliente)) {
            return ['success' => FALSE, 'message' => 'Cliente não encontrado.', 'data' => NULL];
        }

        $destinatario = $this->destinatario($cliente);
        if ($destinatario === '') {
            return [
                'success' => FALSE,
                'message' => 'Cliente sem e-mail cadastrado — o aviso de reajuste não pôde ser enviado.',
                'data' => NULL,
            ];
        }

        $valorAntes = (float) $contrato->value;
        $valorDepois = round($valorAntes * (1 + ($acumulado['rate'] / 100)), 2);

        $marcadores = [
            '{cliente}' => (string) $cliente->name,
            '{contrato}' => '#' . (int) $contrato->id,
            '{indice}' => $rotulos[$indice],
            '{percentual}' => $this->percentual($acumulado['rate']) . '%',
            '{valor_atual}' => reais($valorAntes),
            '{valor_novo}' => reais($valorDepois),
            '{data_reajuste}' => date('d/m/Y', strtotime($dataReajuste)),
            '{ciclo}' => $this->rotuloCiclo((string) $contrato->cycle),
        ];

        $assunto = $this->aplicarMarcadores($this->assuntoConfigurado(), $marcadores);
        $texto = $this->aplicarMarcadores($this->corpoConfigurado(), $marcadores);

        $corpo = $this->global_model->body_email('emails/billing/adjustment', [
            'title' => $assunto,
            'mensagem' => $texto,
            'cliente' => (string) $cliente->name,
            'indice' => $rotulos[$indice],
            'percentual' => $this->percentual($acumulado['rate']),
            'valor_atual' => reais($valorAntes),
            'valor_novo' => reais($valorDepois),
            'data_reajuste' => date('d/m/Y', strtotime($dataReajuste)),
            'ciclo' => $this->rotuloCiclo((string) $contrato->cycle),
        ]);

        $enfileirado = $this->global_model->send_email($assunto, $corpo, [$destinatario], [], [], NULL);
        if (!$enfileirado) {
            return ['success' => FALSE, 'message' => 'Falha ao enfileirar o e-mail.', 'data' => NULL];
        }

        $this->global_model->edit('crm_contracts', [
            'adjustment_notified_for' => $dataReajuste,
        ], 'id', (int) $contrato->id);

        return [
            'success' => TRUE,
            'message' => 'Aviso de reajuste enviado para ' . $destinatario . '.',
            'data' => [
                'destinatario' => $destinatario,
                'rate' => $acumulado['rate'],
                'value_after' => $valorDepois,
            ],
        ];
    }

    /**
     * Cascata do destinatário: contato financeiro → qualquer contato com
     * e-mail → e-mail do contrato.
     *
     * Existe porque só 161 dos 386 clientes têm contato financeiro com e-mail,
     * enquanto 374 têm e-mail no cadastro — sem a cascata, a maioria dos avisos
     * não sairia.
     *
     * @param  object $cliente
     * @return string
     */
    public function destinatario($cliente)
    {
        $contatos = $this->global_model->getWhere_off(
            'crm_customers_contacts',
            ['id_customer' => (int) $cliente->id],
            FALSE
        );

        $qualquer = '';
        foreach ((array) $contatos as $contato) {
            $email = trim((string) $contato->email);
            if ($email === '') continue;

            if ((string) $contato->type === 'financeiro') {
                return $email;
            }

            if ($qualquer === '') $qualquer = $email;
        }

        if ($qualquer !== '') return $qualquer;

        return trim((string) $cliente->email);
    }

    /**
     * Substitui os marcadores do texto configurado.
     *
     * Marcador desconhecido fica LITERAL no texto, e não é apagado: um
     * `{valor}` digitado errado aparecendo no e-mail é um erro visível, que se
     * corrige; um trecho sumindo em silêncio não.
     *
     * @param  string $texto
     * @param  array  $marcadores
     * @return string
     */
    public function aplicarMarcadores($texto, array $marcadores)
    {
        return str_replace(array_keys($marcadores), array_values($marcadores), (string) $texto);
    }

    // ------------------------------------------------------------------
    // Parâmetros
    // ------------------------------------------------------------------

    /**
     * @return int
     */
    public function diasAviso()
    {
        $valor = (int) $this->general_settings_model->getGroupValue('faturamento', 'reajuste_dias_aviso', '');
        return ($valor > 0) ? $valor : self::DIAS_AVISO_PADRAO;
    }

    /**
     * @return string
     */
    public function assuntoConfigurado()
    {
        $valor = trim((string) $this->general_settings_model->getGroupValue('faturamento', 'reajuste_email_assunto', ''));
        return ($valor !== '') ? $valor : self::ASSUNTO_PADRAO;
    }

    /**
     * @return string
     */
    public function corpoConfigurado()
    {
        $valor = trim((string) $this->general_settings_model->getGroupValue('faturamento', 'reajuste_email_corpo', ''));
        return ($valor !== '') ? $valor : $this->corpoPadrao();
    }

    /**
     * Texto de partida, para o e-mail não sair vazio antes de alguém escrever o
     * dele em Parâmetros gerais.
     *
     * @return string
     */
    public function corpoPadrao()
    {
        // HTML desde que o campo passou a ser editado por editor rico: o padrão
        // precisa sair no mesmo formato que a tela devolve, senão o primeiro
        // salvamento converteria o texto e o resultado mudaria sem ninguém pedir.
        return '<p>Prezado(a) {cliente},</p>'
            . '<p>Conforme previsto em contrato, o valor do seu plano será reajustado a partir de'
            . ' <strong>{data_reajuste}</strong>, com base na variação acumulada do índice'
            . ' {indice} nos últimos 12 meses ({percentual}).</p>'
            . '<p>Valor atual: R$ {valor_atual}<br />'
            . 'Novo valor: <strong>R$ {valor_novo}</strong><br />'
            . 'Periodicidade: {ciclo}</p>'
            . '<p>Permanecemos à disposição para qualquer esclarecimento.</p>';
    }

    /**
     * Marcadores aceitos, para a tela de parâmetros listar ao usuário.
     *
     * @return array marcador => descrição
     */
    public function marcadoresDisponiveis()
    {
        return [
            '{cliente}' => 'Razão social / nome do cliente',
            '{contrato}' => 'Número do contrato',
            '{indice}' => 'Índice aplicado (IGP-M, IPCA, ICTI)',
            '{percentual}' => 'Percentual acumulado do índice',
            '{valor_atual}' => 'Valor antes do reajuste',
            '{valor_novo}' => 'Valor depois do reajuste',
            '{data_reajuste}' => 'Data em que o reajuste passa a valer',
            '{ciclo}' => 'Periodicidade do contrato',
        ];
    }

    // ------------------------------------------------------------------
    // Datas e apoio
    // ------------------------------------------------------------------

    /**
     * Primeiro dia do mês ANTERIOR ao da data — o último mês publicado.
     *
     * @param  string $data Y-m-d
     * @return string|null Y-m-d (dia 1)
     */
    public function mesAnterior($data)
    {
        $data = substr(trim((string) $data), 0, 10);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $data, $p)) return NULL;
        if (!checkdate((int) $p[2], (int) $p[3], (int) $p[1])) return NULL;

        return $this->somarMeses($p[1] . '-' . $p[2] . '-01', -1);
    }

    /**
     * Soma meses a uma competência, sempre a partir do dia 1.
     *
     * @param  string $competencia Y-m-d
     * @param  int    $meses pode ser negativo
     * @return string Y-m-d (dia 1)
     */
    public function somarMeses($competencia, $meses)
    {
        $ano = (int) substr($competencia, 0, 4);
        $mes = (int) substr($competencia, 5, 2);

        $total = ($ano * 12) + ($mes - 1) + (int) $meses;

        return sprintf('%04d-%02d-01', intdiv($total, 12), ($total % 12) + 1);
    }

    /**
     * Soma meses preservando o dia, limitado ao último dia do mês de destino —
     * um reajuste em 31/03 cai em 31/03 do ano seguinte, e um em 29/02 de ano
     * bissexto cai em 28/02.
     *
     * @param  string $data Y-m-d
     * @param  int    $meses
     * @return string Y-m-d
     */
    public function somarMesesData($data, $meses)
    {
        $ano = (int) substr($data, 0, 4);
        $mes = (int) substr($data, 5, 2);
        $dia = (int) substr($data, 8, 2);

        $total = ($ano * 12) + ($mes - 1) + (int) $meses;
        $anoDestino = intdiv($total, 12);
        $mesDestino = ($total % 12) + 1;

        $ultimo = (int) date('t', mktime(0, 0, 0, $mesDestino, 1, $anoDestino));

        return sprintf('%04d-%02d-%02d', $anoDestino, $mesDestino, min($dia, $ultimo));
    }

    /**
     * Próximo aniversário do contrato que ainda NÃO passou.
     *
     * Os contratos importados têm `created` de anos atrás; calcular
     * `created + 12 meses` cairia no passado e a primeira rodada do cron
     * tentaria aplicar todos os reajustes desde então de uma vez.
     *
     * @param  string $created Y-m-d H:i:s
     * @return string Y-m-d
     */
    public function proximoAniversario($created)
    {
        $base = substr(trim((string) $created), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $base)) {
            $base = date('Y-m-d');
        }

        $hoje = date('Y-m-d');
        $candidato = $base;

        // Teto de segurança: 200 anos de margem cobrem qualquer data plausível
        // e evitam laço infinito se a data de origem for absurda.
        for ($i = 0; $i < 200; $i++) {
            $candidato = $this->somarMesesData($base, 12 * ($i + 1));
            if ($candidato > $hoje) return $candidato;
        }

        return $this->somarMesesData($hoje, 12);
    }

    /**
     * Percentual no formato brasileiro, com duas casas.
     *
     * O helper `numero()` do projeto não serve aqui: ele formata com ZERO
     * casas decimais, e um acumulado de 12,68% sairia como "13" no e-mail do
     * cliente.
     *
     * @param  float $valor
     * @return string
     */
    public function percentual($valor)
    {
        return number_format((float) $valor, 2, ',', '.');
    }

    /**
     * @param  string $cycle
     * @return string
     */
    private function rotuloCiclo($cycle)
    {
        $rotulos = [
            'mensal' => 'Mensal',
            'bimestral' => 'Bimestral',
            'trimestral' => 'Trimestral',
            'quadrimestral' => 'Quadrimestral',
            'semestral' => 'Semestral',
            'anual' => 'Anual',
        ];

        return isset($rotulos[$cycle]) ? $rotulos[$cycle] : $cycle;
    }

    /**
     * @param  bool   $success
     * @param  string $message
     * @param  float  $rate
     * @param  string $inicio
     * @param  string $fim
     * @param  array  $faltando
     * @return array
     */
    private function resultadoAcumulado($success, $message, $rate, $inicio, $fim, array $faltando)
    {
        return [
            'success' => (bool) $success,
            'message' => (string) $message,
            'rate' => (float) $rate,
            'inicio' => (string) $inicio,
            'fim' => (string) $fim,
            'faltando' => $faltando,
        ];
    }
}
