<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Painel extends MY_Controller
{
    /** Janela do indicador "Domínios em alerta", em dias. */
    const DIAS_ALERTA_DOMINIO = 15;

    /**
     * Meses exibidos no gráfico "Movimento de contratos", contando o corrente.
     *
     * 12 fecha o ciclo anual (o mesmo mês do ano passado fica na ponta, então
     * sazonalidade aparece). Mexer aqui muda gráfico e query juntos — os meses
     * são gerados a partir desta constante, não fixados na view.
     */
    const MESES_MOVIMENTO = 12;

    /**
     * Faixas do card de espaço, em % do espaço contratado. São os únicos
     * lugares onde os limiares aparecem: a contagem do card e a lista do modal
     * montam a condição a partir daqui (condicaoFaixaEspaco), então não há como
     * uma dizer 200% e a outra 199%.
     */
    const PCT_ESPACO_CRITICO = 200;
    const PCT_ESPACO_URGENTE = 100;
    const PCT_ESPACO_PROATIVO = 90;

    /**
     * Teto de linhas do modal VER DOMÍNIOS.
     *
     * As faixas de vencimento são naturalmente curtas, mas "sem contrato" pode
     * ser quase a base inteira — e um modal com centenas de linhas deixa de ser
     * lista de conferência. Quando corta, a tela DIZ que cortou e aponta para
     * Servidores > Domínios: teto silencioso faria a lista parecer completa.
     */
    const LIMITE_LISTA_DOMINIOS = 200;

    public function __construct()
    {
        parent::__construct();
    }

    public function sair($message = "Logout feito com sucesso.")
    {
        if ($this->input->get('message')) $message = $this->input->get('message');
        $this->session->sess_destroy();
        redirect(base_url() . "login?success=$message");
    }

    public function sair_custom()
    {
        $this->session->sess_destroy();
        redirect(base_url() . 'login?warning=Sua sessão expirou, por favor efetue login novamente.');
    }

    public function erro404()
    {
        $this->data['menu'] = 'dashboard';

        $this->load->view('header', $this->data);
        $this->load->view('erro404', $this->data);
        $this->load->view('footer', $this->data);
    }

    public function sessao()
    {
        $this->output->enable_profiler(TRUE);
        phpinfo();
    }

    /**
     * Recebe do navegador o corpo bruto que o editor/Dropzone NÃO conseguiu
     * interpretar e grava no log ([UPLOAD-DIAG ... etapa=cliente]).
     *
     * É a única forma de ver o que realmente chegou ao navegador: o servidor
     * pode gravar um JSON válido e algo no caminho (Apache, proxy, mod_security,
     * cache) alterar o corpo. O id vem do header X-Upload-Log-Id devolvido pelo
     * upload, o que casa esta linha com as demais etapas do mesmo request.
     */
    public function editor_upload_diag()
    {
        $corpo = (string) $this->input->post('corpo', FALSE);
        $logId = preg_replace('/[^A-Za-z0-9]/', '', (string) $this->input->post('log_id', FALSE));

        log_message('error', '[UPLOAD-DIAG ' . ($logId !== '' ? $logId : 'SEM-ID') . '] ' . json_encode([
            'etapa' => 'cliente',
            'origem' => (string) $this->input->post('origem', FALSE),      // froala | tinymce | dropzone
            'codigo_erro' => (string) $this->input->post('codigo_erro', FALSE),
            // http_status 0 + evento_xhr "erro de rede" = a requisição nem chegou
            // a completar (o Froala usa o mesmo código 4 dos dois casos).
            'http_status' => (string) $this->input->post('http_status', FALSE),
            'evento_xhr' => (string) $this->input->post('evento_xhr', FALSE),
            'content_type' => (string) $this->input->post('content_type', FALSE),
            'arquivo' => mb_substr((string) $this->input->post('arquivo', FALSE), 0, 255),
            'url' => mb_substr((string) $this->input->post('url', FALSE), 0, 255),
            'corpo_bytes' => strlen($corpo),
            // Hex do início revela BOM (EFBBBF), espaços e HTML colados antes do JSON.
            'corpo_hex_inicio' => strtoupper(bin2hex(substr($corpo, 0, 64))),
            'corpo_recebido' => mb_substr($corpo, 0, 4000),
            'user_agent' => mb_substr((string) $this->input->user_agent(), 0, 200),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => TRUE, 'message' => 'Diagnóstico registrado.', 'data' => [], 'errors' => []]);
        exit;
    }

    public function index()
    {
        $this->data['menu'] = 'dashboard';

        // Indicadores do financeiro. Vieram da listagem de clientes, onde
        // reagiam ao escopo do tenant mas ficavam escondidos atrás do módulo;
        // aqui são a primeira coisa que o usuário vê ao entrar.
        //
        // Contagem direta com binds (e não pelos helpers do Global_model) de
        // propósito: eles aplicariam os filtros gravados na sessão e os cards
        // passariam a refletir a última busca, não a base.
        $idCompany = (int) $this->getCurrentCompanyId();

        $this->data['ind_clientes'] = $this->contarUm(
            'SELECT COUNT(*) AS total FROM crm_customers WHERE id_company = ?',
            [$idCompany]
        );

        // Os contadores de contrato saem de uma query só e cada um é
        // AFIRMATIVO. O de suspensos era uma subtração (total - vigentes), que
        // absorvia em silêncio qualquer status que não fosse vigente — com a
        // chegada de 'encerrado' ela passaria a contar encerrado como suspenso.
        $contratos = $this->indicadoresContratos($idCompany);
        $this->data['ind_clientes_vigentes'] = $contratos['clientes_vigentes'];
        $this->data['ind_contratos'] = $contratos['total'];
        $this->data['ind_contratos_vigentes'] = $contratos['vigentes'];
        $this->data['ind_contratos_suspensos'] = $contratos['suspensos'];
        $this->data['ind_contratos_encerrados'] = $contratos['encerrados'];

        // A contagem de novos no mês sai da MESMA query do gráfico de
        // movimento — do último balde, que é o mês corrente. Eram a mesma
        // pergunta escrita duas vezes, e com regras ligeiramente diferentes (o
        // KPI não tinha teto superior), então o card e a barra verde podiam
        // discordar.
        $this->data['ind_movimento_meses'] = $this->movimentoPorMes($idCompany);
        $mesCorrente = end($this->data['ind_movimento_meses']);
        $this->data['ind_contratos_novos_mes'] = $mesCorrente['entrada_qtd'];

        // Por que os contratos da barra laranja de saídas foram embora: mesma
        // janela do movimento (janelaMovimento), para os dois cards não
        // discordarem sobre o mesmo período.
        $this->data['ind_cancelamentos'] = $this->indicadoresCancelamentos($idCompany);

        $this->data['ind_por_servico'] = $this->contratosVigentesPorServico($idCompany);
        $this->data['ind_dominios'] = $this->indicadoresDominios($idCompany);
        $this->data['ind_espaco'] = $this->indicadoresEspaco($idCompany);

        $this->load->view('header', $this->data);
        $this->load->view('dashboard', $this->data);
        $this->load->view('footer', $this->data);
    }

    /**
     * Situação de registro dos domínios sincronizados dos servidores.
     *
     * A base são os domínios dos SERVIDORES (crm_servers_domains_v), não os dos
     * contratos: é lá que mora o retrato do WHOIS — vencimento real e a faixa
     * `whois_bucket`, que o banco calcula. Domínio de contrato tem o vencimento
     * espelhado do WHOIS quando há vínculo, mas quem responde "este domínio
     * ainda existe?" é sempre a linha do servidor.
     *
     * As três faixas saem do `whois_bucket` de propósito, e não de comparações
     * de data soltas aqui: assim o card, o filtro e a cor da tela de domínios
     * enxergam os mesmos limiares, e as faixas são mutuamente exclusivas — um
     * domínio livre não aparece também como vencido por causa da data velha que
     * continua gravada. A única exceção é a janela de 15 dias, que é deste card:
     * `vence_30` já garante "vence e ainda não venceu", e o INTERVAL aperta a
     * janela sem repetir a regra.
     *
     * Os dois conjuntos (todos e só os gerenciados pela CDW) vêm na MESMA query
     * porque o switch da tela alterna entre eles sem nova requisição — e porque
     * a segunda passada custaria o mesmo JOIN para responder à metade da mesma
     * pergunta.
     *
     * "Gerenciado pela CDW" é atributo do domínio de CONTRATO (`managed_cdw`),
     * não do domínio do servidor; o LEFT JOIN agregado traz o flag sem duplicar
     * linha quando o mesmo domínio está em mais de um contrato — daí o MAX() e
     * não o valor direto.
     *
     * @param  int $idCompany
     * @return array total, criticos, alerta, livres — em 'todos' e em 'cdw'
     */
    private function indicadoresDominios($idCompany)
    {
        $sql = "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN d.whois_bucket = 'vencido' THEN 1 ELSE 0 END) AS criticos,
                    SUM(CASE WHEN d.whois_bucket = 'vence_30' AND d.whois_expiration_date <= CURDATE() + INTERVAL " . self::DIAS_ALERTA_DOMINIO . " DAY THEN 1 ELSE 0 END) AS alerta,
                    SUM(CASE WHEN d.whois_bucket = 'livre' THEN 1 ELSE 0 END) AS livres,
                    SUM(CASE WHEN g.id_server_domain IS NULL THEN 1 ELSE 0 END) AS sem_contrato,
                    SUM(CASE WHEN g.managed_cdw = 1 THEN 1 ELSE 0 END) AS total_cdw,
                    SUM(CASE WHEN g.managed_cdw = 1 AND d.whois_bucket = 'vencido' THEN 1 ELSE 0 END) AS criticos_cdw,
                    SUM(CASE WHEN g.managed_cdw = 1 AND d.whois_bucket = 'vence_30' AND d.whois_expiration_date <= CURDATE() + INTERVAL " . self::DIAS_ALERTA_DOMINIO . " DAY THEN 1 ELSE 0 END) AS alerta_cdw,
                    SUM(CASE WHEN g.managed_cdw = 1 AND d.whois_bucket = 'livre' THEN 1 ELSE 0 END) AS livres_cdw,
                    -- Necessariamente zero (sem contrato não há managed_cdw a
                    -- marcar), mas escrito como fórmula, e não como 0 literal:
                    -- se a origem do flag mudar, isto acompanha em vez de mentir.
                    SUM(CASE WHEN g.managed_cdw = 1 AND g.id_server_domain IS NULL THEN 1 ELSE 0 END) AS sem_contrato_cdw
                  FROM crm_servers_domains_v d
             LEFT JOIN (
                        SELECT id_server_domain, MAX(managed_cdw) AS managed_cdw
                          FROM crm_contracts_domains
                         WHERE id_server_domain IS NOT NULL AND id_company = ?
                      GROUP BY id_server_domain
                       ) g ON g.id_server_domain = d.id
                 WHERE d.id_company = ?";

        // Os binds são do que vem de fora (o tenant). O limiar de dias é
        // constante da classe e entra no texto do SQL — não há entrada de
        // usuário nesse ponto para escapar.
        $row = $this->db->query($sql, [(int) $idCompany, (int) $idCompany])->row();

        if (empty($row)) {
            $vazio = ['total' => 0, 'criticos' => 0, 'alerta' => 0, 'livres' => 0, 'sem_contrato' => 0];
            return ['todos' => $vazio, 'cdw' => $vazio];
        }

        return [
            'todos' => [
                'total' => (int) $row->total,
                'criticos' => (int) $row->criticos,
                'alerta' => (int) $row->alerta,
                'livres' => (int) $row->livres,
                'sem_contrato' => (int) $row->sem_contrato,
            ],
            'cdw' => [
                'total' => (int) $row->total_cdw,
                'criticos' => (int) $row->criticos_cdw,
                'alerta' => (int) $row->alerta_cdw,
                'livres' => (int) $row->livres_cdw,
                'sem_contrato' => (int) $row->sem_contrato_cdw,
            ],
        ];
    }

    /**
     * Contadores de contrato do tenant, numa query só.
     *
     * Cada faixa é contada pelo NOME do status, e não por subtração: quatro
     * round-trips viram um, e um quinto status no futuro não é absorvido em
     * silêncio por nenhum contador.
     *
     * `total` continua sendo o total de linhas — honesto ("contratos que já
     * existiram"); quem qualifica é a legenda do card.
     *
     * @param  int $idCompany
     * @return array
     */
    private function indicadoresContratos($idCompany)
    {
        $sql = "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = 'vigente'   THEN 1 ELSE 0 END) AS vigentes,
                    SUM(CASE WHEN status = 'suspenso'  THEN 1 ELSE 0 END) AS suspensos,
                    SUM(CASE WHEN status = 'encerrado' THEN 1 ELSE 0 END) AS encerrados,
                    COUNT(DISTINCT CASE WHEN status = 'vigente' THEN id_customer END) AS clientes_vigentes
                  FROM crm_contracts
                 WHERE id_company = ?";

        $row = $this->db->query($sql, [(int) $idCompany])->row();

        // SUM sobre zero linhas devolve NULL — os casts resolvem.
        return [
            'total' => !empty($row) ? (int) $row->total : 0,
            'vigentes' => !empty($row) ? (int) $row->vigentes : 0,
            'suspensos' => !empty($row) ? (int) $row->suspensos : 0,
            'encerrados' => !empty($row) ? (int) $row->encerrados : 0,
            'clientes_vigentes' => !empty($row) ? (int) $row->clientes_vigentes : 0,
        ];
    }

    /**
     * Movimento de contratos mês a mês: quanto entrou e quanto saiu em cada um
     * dos últimos MESES_MOVIMENTO meses (o corrente é o último).
     *
     * A entrada é ancorada em `created` e a saída em `ended` (migration 017) —
     * duas perguntas independentes sobre a mesma linha, por isso um contrato
     * criado E encerrado no mesmo mês aparece nas DUAS barras daquele mês. É o
     * correto: escondê-lo faria o mês parecer mais calmo do que foi. E um
     * contrato criado em março e encerrado em agosto aparece na barra verde de
     * março E na laranja de agosto.
     *
     * O valor é o do ciclo cheio, sem rateio: um contrato anual de R$ 1.200
     * soma R$ 1.200 no mês em que entrou. Normalizar para o equivalente mensal
     * seria dividir pelos meses do ciclo aqui — nenhuma coluna mudaria.
     *
     * As duas metades vêm em UNION ALL, e não em SUM(CASE) sobre a tabela
     * inteira, porque cada uma tem a SUA janela sobre a SUA coluna: assim o
     * `WHERE` de cada metade é restritivo (e usa o índice
     * idx_contracts_company_ended na metade das saídas) em vez de varrer todos
     * os contratos do tenant para descartar no CASE.
     *
     * Detalhes que não são acidente:
     *  - a janela é MEIO-ABERTA (`>= dia 1` e `< dia 1 do mês seguinte`) porque
     *    `created`/`ended` são DATETIME: um `<= último dia` perderia tudo que
     *    acontecesse depois das 00:00 daquele dia;
     *  - não há `ended IS NOT NULL`: `NULL >= '...'` devolve NULL, que não é
     *    verdadeiro, então contrato vigente fica de fora sozinho. Escrever a
     *    guarda sugeriria que sem ela vazaria algo;
     *  - os limites saem do PHP, e não de NOW()/CURDATE(): o `ended` gravado
     *    também é hora do PHP (America/Sao_Paulo, fixado no index.php), e
     *    misturar o relógio do MySQL abriria divergência de fuso na virada do
     *    mês;
     *  - os meses são gerados no PHP e o resultado do banco é encaixado neles.
     *    Mês sem nenhum movimento PRECISA aparecer zerado no gráfico — se a
     *    lista viesse só do GROUP BY, os meses parados sumiriam e os que
     *    sobraram ficariam lado a lado, dando a impressão de continuidade onde
     *    houve um buraco.
     *
     * @param  int $idCompany
     * @return array lista ordenada do mais antigo ao mês corrente
     */
    /**
     * Cancelamentos da janela, agrupados pelo motivo do encerramento.
     *
     * Responde três perguntas que a barra de saídas do card ao lado não
     * responde: POR QUE os contratos saíram, QUANTO cada motivo custou e QUANTO
     * TEMPO o contrato durou até sair.
     *
     * Decisões:
     *
     *  - **Uma query só, e os totais derivados dela em PHP.** Um total vindo de
     *    segunda query pode discordar da soma das fatias por diferença de
     *    janela ou de filtro — e o usuário veria o gráfico contradizer o número
     *    logo acima dele.
     *
     *  - **LEFT JOIN no catálogo**: motivo que não está (ou não está mais) em
     *    `crm_end_reasons` continua aparecendo, com o próprio slug de rótulo. O
     *    contrato guarda o slug de propósito (ver a migration 032), e um
     *    INNER JOIN faria o contrato encerrado sumir do gráfico ao aposentarem
     *    o motivo — a soma das fatias deixaria de bater com o total.
     *
     *  - **A vida do contrato vem de `SUM(DATEDIFF(...))`, não de `AVG`**: com
     *    a soma dá para calcular a média por motivo E a média geral ponderada;
     *    com médias por motivo, a "média das médias" daria peso igual a um
     *    motivo com 1 contrato e a outro com 30.
     *
     *  - `ended IS NOT NULL` não é escrito: `NULL >= '...'` devolve NULL, que
     *    não é verdadeiro, então contrato vigente já fica de fora sozinho.
     *
     * @param  int $idCompany
     * @return array motivos (lista), total, valor, dias, meses_medio
     */
    private function indicadoresCancelamentos($idCompany)
    {
        list($inicio, $fim) = $this->janelaMovimento();

        $sql = "SELECT c.ended_reason AS slug,
                       COALESCE(r.name, c.ended_reason) AS nome,
                       COALESCE(NULLIF(r.color, ''), '#6c757d') AS cor,
                       COALESCE(r.sort_order, 999) AS ordem,
                       COUNT(*) AS quantidade,
                       SUM(c.value) AS valor,
                       SUM(DATEDIFF(c.ended, c.created)) AS dias
                  FROM crm_contracts c
             LEFT JOIN crm_end_reasons r ON r.slug = c.ended_reason
                 WHERE c.id_company = ? AND c.ended >= ? AND c.ended < ?
              GROUP BY c.ended_reason, r.name, r.color, r.sort_order
              ORDER BY quantidade DESC, valor DESC";

        $linhas = $this->db->query($sql, [(int) $idCompany, $inicio, $fim])->result();

        $motivos = [];
        $total = 0;
        $valor = 0.0;
        $dias = 0;

        foreach ($linhas as $linha) {
            $quantidade = (int) $linha->quantidade;
            $motivos[] = [
                'slug' => (string) $linha->slug,
                'nome' => (string) $linha->nome,
                'cor' => (string) $linha->cor,
                'quantidade' => $quantidade,
                'valor' => (float) $linha->valor,
                // Contrato sem `created` não tem vida a medir; o DATEDIFF
                // devolveria NULL e o cast o zera, o que só afeta a média dele.
                'dias' => (int) $linha->dias,
            ];

            $total += $quantidade;
            $valor += (float) $linha->valor;
            $dias += (int) $linha->dias;
        }

        return [
            'motivos' => $motivos,
            'total' => $total,
            'valor' => $valor,
            // 30.44 = média de dias do mês no ano. O rótulo diz "meses", e
            // dividir por 30 daria 12,17 meses para um contrato de um ano.
            'meses_medio' => ($total > 0) ? ($dias / $total) / 30.44 : 0.0,
        ];
    }
    /**
     * Limites da janela de MESES_MOVIMENTO meses, meio-aberta.
     *
     * Vive num método próprio porque DOIS cards dependem dela: o movimento de
     * contratos e o de cancelamentos. Se cada um calculasse a sua, bastaria um
     * `-11` virar `-12` de um lado para o valor perdido do card de
     * cancelamentos parar de bater com a soma das barras de saída do outro — e
     * seriam dois números discordando sobre a mesma pergunta, na mesma tela.
     *
     * Ancora no dia 1 ANTES de somar/subtrair meses: `strtotime('+1 month')` a
     * partir de hoje estoura em 31/01 (vira 03/03). E sai do PHP, não de
     * NOW()/CURDATE(): o `ended` gravado também é hora do PHP, e misturar o
     * relógio do MySQL abriria divergência de fuso na virada do mês.
     *
     * Devolve também a ÂNCORA (dia 1 do mês corrente), que o laço dos baldes
     * de mês usa: recalculá-la lá seria abrir a porta para os meses do gráfico
     * e a janela da query divergirem.
     *
     * @return array [inicio, fim, primeiroDoMes]
     */
    private function janelaMovimento()
    {
        $primeiroDoMes = strtotime(date('Y-m-01'));

        return [
            date('Y-m-01 00:00:00', strtotime('-' . (self::MESES_MOVIMENTO - 1) . ' months', $primeiroDoMes)),
            date('Y-m-01 00:00:00', strtotime('+1 month', $primeiroDoMes)),
            $primeiroDoMes,
        ];
    }
    private function movimentoPorMes($idCompany)
    {
        list($inicio, $fim, $primeiroDoMes) = $this->janelaMovimento();

        $sql = "SELECT periodo,
                       SUM(entrada_valor) AS entrada_valor,
                       SUM(entrada_qtd)   AS entrada_qtd,
                       SUM(saida_valor)   AS saida_valor,
                       SUM(saida_qtd)     AS saida_qtd
                  FROM (
                        SELECT DATE_FORMAT(created, '%Y-%m') AS periodo,
                               value AS entrada_valor, 1 AS entrada_qtd,
                               0 AS saida_valor, 0 AS saida_qtd
                          FROM crm_contracts
                         WHERE id_company = ? AND created >= ? AND created < ?
                         UNION ALL
                        SELECT DATE_FORMAT(ended, '%Y-%m'),
                               0, 0,
                               value, 1
                          FROM crm_contracts
                         WHERE id_company = ? AND ended >= ? AND ended < ?
                       ) t
              GROUP BY periodo";

        $linhas = $this->db->query($sql, [
            (int) $idCompany, $inicio, $fim,
            (int) $idCompany, $inicio, $fim,
        ])->result();

        $porPeriodo = [];
        foreach ($linhas as $linha) {
            $porPeriodo[$linha->periodo] = $linha;
        }

        $meses = [];
        for ($i = self::MESES_MOVIMENTO - 1; $i >= 0; $i--) {
            $ts = strtotime('-' . $i . ' months', $primeiroDoMes);
            $periodo = date('Y-m', $ts);
            $linha = isset($porPeriodo[$periodo]) ? $porPeriodo[$periodo] : NULL;

            $meses[] = [
                'periodo' => $periodo,
                'mes' => (int) date('n', $ts),
                'ano' => (int) date('Y', $ts),
                'entrada_valor' => !empty($linha) ? (float) $linha->entrada_valor : 0.0,
                'entrada_qtd' => !empty($linha) ? (int) $linha->entrada_qtd : 0,
                'saida_valor' => !empty($linha) ? (float) $linha->saida_valor : 0.0,
                'saida_qtd' => !empty($linha) ? (int) $linha->saida_qtd : 0,
            ];
        }

        return $meses;
    }

    /**
     * Contratos VIGENTES agrupados por tipo de serviço, do maior para o menor.
     *
     * Lê da crm_contracts_services_v, que já carrega o nome do tipo, o
     * id_company e (desde a migration 013) o status do contrato — o indicador
     * sai em uma query só. COUNT(DISTINCT id_contract) porque o par
     * (contrato, tipo) é único, mas o COUNT simples ficaria refém disso.
     *
     * Tipos sem nenhum contrato vigente não entram: o gráfico é compacto e
     * uma lista de zeros só ocuparia espaço.
     *
     * @param  int $idCompany
     * @return array
     */
    private function contratosVigentesPorServico($idCompany)
    {
        $sql = "SELECT id_service_type, service_type_name, COUNT(DISTINCT id_contract) AS total
                  FROM crm_contracts_services_v
                 WHERE id_company = ? AND contract_status = 'vigente'
              GROUP BY id_service_type, service_type_name
              ORDER BY total DESC, service_type_name ASC";

        return $this->db->query($sql, [(int) $idCompany])->result();
    }

    /**
     * Uso de espaço por contrato VIGENTE do tenant — um contrato por linha.
     *
     * É a fonte das duas consultas do card (a contagem e a lista do modal), para
     * que a régua do número e a da lista sejam literalmente a mesma. Leva dois
     * binds, ambos o tenant: o de dentro (a subconsulta de vínculos) e o de fora.
     *
     * A regra do uso é a das telas de contrato e de cliente: soma só os domínios
     * COM vínculo — domínio sem correspondência no servidor não entra.
     *
     * O `SELECT DISTINCT id_contract, id_server_domain` não é enfeite: a busca do
     * cadastro procura o nome exato E a variante com/sem "www.", então `foo.com`
     * e `www.foo.com` no mesmo contrato apontam para a MESMA linha de
     * crm_servers_domains (a UNIQUE da 010 é por nome, e os nomes diferem). Sem o
     * DISTINCT o disco seria contado duas vezes e o contrato subiria de faixa
     * sozinho. Dedupe pelo PAR (contrato, domínio de servidor), e não
     * SUM(DISTINCT disk_used_mb), que colapsaria dois domínios diferentes de
     * mesmo tamanho — o caso comum de dois sites pequenos.
     *
     * Os dois LEFT JOIN mantêm na base o contrato sem domínio nenhum (uso 0, fora
     * das faixas); a condição do vínculo mora no ON, porque no WHERE ela viraria
     * INNER JOIN e derrubaria justamente esses contratos.
     *
     * Guarda o espaço em MB (`space_mb`) em vez de converter o uso para Gb: assim
     * a comparação das faixas é multiplicação de DECIMAL — exata — em vez de uma
     * divisão por 1024 que deixaria a fronteira dos 100% no arredondamento.
     *
     * @return string SQL da subconsulta, sem o SELECT externo
     */
    private function subconsultaUsoContrato()
    {
        return "SELECT c.id AS id,
                               c.id_customer AS id_customer,
                               c.customer_name AS customer_name,
                               c.space_gb AS space_gb,
                               c.space_gb * 1024 AS space_mb,
                               COALESCE(SUM(sd.disk_used_mb), 0) AS used_mb,
                               COUNT(cd.id_server_domain) AS dominios_vinculados,
                               SUM(CASE WHEN sd.id IS NOT NULL AND sd.disk_used_mb IS NULL THEN 1 ELSE 0 END) AS dominios_sem_dado
                          FROM crm_contracts_v c
                     LEFT JOIN (
                                SELECT DISTINCT id_contract, id_server_domain
                                  FROM crm_contracts_domains
                                 WHERE id_company = ? AND id_server_domain IS NOT NULL
                               ) cd ON cd.id_contract = c.id
                     LEFT JOIN crm_servers_domains sd ON sd.id = cd.id_server_domain
                         WHERE c.id_company = ? AND c.status = 'vigente'
                      GROUP BY c.id, c.id_customer, c.customer_name, c.space_gb";
    }

    /**
     * Faixas que o botão VER CONTRATOS pode pedir — allowlist, na ordem do card.
     *
     * @return array
     */
    private function faixasEspaco()
    {
        return ['critico', 'urgente', 'proativo'];
    }

    /**
     * Condição SQL de uma faixa, sobre as colunas da subconsultaUsoContrato().
     *
     * Comparação por multiplicação (`used_mb >= space_mb * 2.00`), e não por
     * percentual calculado: em DECIMAL a multiplicação é exata, então "exatamente
     * 100%" e "exatamente 200%" caem sempre do mesmo lado. As faixas são
     * mutuamente exclusivas e cobrem [90%, ∞) sem buraco — 100% é o teto do
     * pró-ativo (não excedeu) e 200% é o piso do crítico.
     *
     * O multiplicador entra no SQL por number_format, e não por concatenação do
     * float: o texto tem que ser "2.00" em qualquer locale.
     *
     * @param  string $faixa uma das faixasEspaco()
     * @return string|null   NULL para faixa desconhecida
     */
    private function condicaoFaixaEspaco($faixa)
    {
        $critico = number_format(self::PCT_ESPACO_CRITICO / 100, 2, '.', '');
        $urgente = number_format(self::PCT_ESPACO_URGENTE / 100, 2, '.', '');
        $proativo = number_format(self::PCT_ESPACO_PROATIVO / 100, 2, '.', '');

        switch ($faixa) {
            case 'critico':
                return "u.used_mb >= u.space_mb * " . $critico;
            case 'urgente':
                return "u.used_mb > u.space_mb * " . $urgente . " AND u.used_mb < u.space_mb * " . $critico;
            case 'proativo':
                return "u.used_mb >= u.space_mb * " . $proativo . " AND u.used_mb <= u.space_mb * " . $urgente;
        }

        return NULL;
    }

    /**
     * Contratos vigentes por faixa de uso do espaço contratado.
     *
     * Só contratos VIGENTES: espaço estourado é conversa comercial (upsell ou
     * cobrança), e contrato suspenso não tem essa conversa em aberto.
     *
     * `avaliados` (e não `total`) é o denominador dos percentuais da tela: quem
     * não tem espaço contratado definido não tem como estar a 90% de coisa
     * nenhuma, e mantê-lo no denominador encolheria as três faixas à toa. Esses
     * contratos saem em `sem_espaco`, e o recorte que interessa de verdade é o
     * `sem_espaco_com_uso`: consome disco sem plano contratado — ninguém está
     * cobrando por ele.
     *
     * @param  int $idCompany
     * @return array
     */
    private function indicadoresEspaco($idCompany)
    {
        $sql = "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN u.space_mb > 0 THEN 1 ELSE 0 END) AS avaliados,
                    SUM(CASE WHEN u.space_mb <= 0 THEN 1 ELSE 0 END) AS sem_espaco,
                    SUM(CASE WHEN u.space_mb <= 0 AND u.used_mb > 0 THEN 1 ELSE 0 END) AS sem_espaco_com_uso,
                    SUM(CASE WHEN u.space_mb > 0 AND (" . $this->condicaoFaixaEspaco('critico') . ") THEN 1 ELSE 0 END) AS criticos,
                    SUM(CASE WHEN u.space_mb > 0 AND (" . $this->condicaoFaixaEspaco('urgente') . ") THEN 1 ELSE 0 END) AS urgentes,
                    SUM(CASE WHEN u.space_mb > 0 AND (" . $this->condicaoFaixaEspaco('proativo') . ") THEN 1 ELSE 0 END) AS proativos
                  FROM (
                        " . $this->subconsultaUsoContrato() . "
                       ) u";

        // Os binds são do que vem de fora (o tenant, duas vezes). Os limiares são
        // constantes da classe e entram no texto do SQL — não há entrada de
        // usuário nesse ponto para escapar.
        $row = $this->db->query($sql, [(int) $idCompany, (int) $idCompany])->row();

        if (empty($row)) {
            return [
                'total' => 0, 'avaliados' => 0, 'sem_espaco' => 0, 'sem_espaco_com_uso' => 0,
                'criticos' => 0, 'urgentes' => 0, 'proativos' => 0,
            ];
        }

        return [
            'total' => (int) $row->total,
            'avaliados' => (int) $row->avaliados,
            'sem_espaco' => (int) $row->sem_espaco,
            'sem_espaco_com_uso' => (int) $row->sem_espaco_com_uso,
            'criticos' => (int) $row->criticos,
            'urgentes' => (int) $row->urgentes,
            'proativos' => (int) $row->proativos,
        ];
    }

    /**
     * Contratos de UMA faixa do card de espaço, para o botão VER CONTRATOS.
     *
     * Mesma proteção do card de domínios: a faixa vem no POST e é validada contra
     * a allowlist — o que entra na condição é a constante, nunca o texto recebido.
     * Faixa desconhecida é erro, e não "traz tudo".
     *
     * O filtro é `space_mb > 0` mais a condição da faixa. Sem o primeiro, um
     * contrato sem espaço definido passaria em todas: com `space_mb = 0`, o
     * `used_mb >= space_mb * 2.00` vira `used_mb >= 0`, que é sempre verdade.
     *
     * Não há paginação de propósito: a lista é curta por natureza (o que estourou)
     * e é justamente o recorte acionável que o card acusou.
     */
    public function json_getcontratoscard()
    {
        header('Content-Type: application/json; charset=utf-8');

        $idCompany = (int) $this->getCurrentCompanyId();
        $faixa = (string) $this->input->post('faixa');

        if (!in_array($faixa, $this->faixasEspaco(), TRUE)) {
            echo json_encode([
                'success' => FALSE,
                'return' => FALSE,
                'message' => 'Faixa de espaço inválida.',
                'data' => NULL,
                'errors' => ['faixa' => 'Faixa de espaço inválida.'],
            ]);
            return;
        }

        // A divisão do percentual só existe aqui, para exibição, e é segura: o
        // WHERE já garantiu space_mb > 0. Quem decide a faixa é a comparação por
        // multiplicação, não este número arredondado.
        $sql = "SELECT
                    u.id,
                    u.id_customer,
                    u.customer_name,
                    u.space_gb,
                    u.used_mb,
                    u.dominios_vinculados,
                    u.dominios_sem_dado,
                    ROUND(u.used_mb / u.space_mb * 100, 1) AS pct,
                    (SELECT COUNT(*) FROM crm_contracts_domains WHERE id_contract = u.id) AS dominios_total
                  FROM (
                        " . $this->subconsultaUsoContrato() . "
                       ) u
                 WHERE u.space_mb > 0 AND (" . $this->condicaoFaixaEspaco($faixa) . ")
              ORDER BY pct DESC, u.customer_name ASC";

        $linhas = $this->db->query($sql, [$idCompany, $idCompany])->result();

        $contratos = [];
        foreach ($linhas as $linha) {
            $usoGb = (float) $linha->used_mb / 1024;

            $contratos[] = [
                'cliente' => $linha->customer_name,
                'espaco' => number_format((float) $linha->space_gb, 2, ',', '.') . ' Gb',
                'em_uso' => number_format($usoGb, 2, ',', '.') . ' Gb',
                'pct' => number_format((float) $linha->pct, 1, ',', '.') . '%',
                // A barra satura em 100%: o número passa (e é o que interessa),
                // a barra não tem para onde crescer.
                'pct_barra' => min(100, (float) $linha->pct),
                'dominios' => (int) $linha->dominios_vinculados . ' de ' . (int) $linha->dominios_total,
                // Vínculo sem leitura de disco é o caso que faz um contrato
                // parecer saudável sem ser: sem o aviso, o zero passa por "vazio".
                'dominios_sem_dado' => (int) $linha->dominios_sem_dado,
                'url_contrato' => base_url('contratos/info?id=' . (int) $linha->id),
                'url_cliente' => base_url('clientes/info?id=' . (int) $linha->id_customer),
            ];
        }

        echo json_encode([
            'success' => TRUE,
            'return' => TRUE,
            'message' => '',
            'data' => [
                'contratos' => $contratos,
                'total' => count($contratos),
            ],
            'errors' => [],
        ]);
    }

    /**
     * Faixas que o botão VER DOMÍNIOS pode pedir — allowlist, na ordem do card.
     *
     * As três primeiras são valores de `whois_bucket` da crm_servers_domains_v.
     * `sem_contrato` NÃO é bucket: pergunta se o domínio está em algum contrato,
     * e por isso cruza com as outras (um domínio pode ser vencido E sem
     * contrato). Ela entra aqui porque o que esta lista guarda é "o que o botão
     * pode pedir", não "o que a view do banco calcula".
     *
     * Só as chaves: o rótulo de cada faixa é o título do bloco, que a view já
     * tem e o botão manda ao modal. Repeti-lo aqui criaria duas escritas do
     * mesmo nome, livres para divergir.
     *
     * @return array
     */
    private function faixasDominio()
    {
        return ['vencido', 'vence_30', 'livre', 'sem_contrato'];
    }

    /**
     * Domínios de UMA faixa do card, para o botão VER DOMÍNIOS do indicador.
     *
     * A faixa vem no POST e é validada contra o catálogo — nunca entra no SQL:
     * o que a condição usa é a constante da allowlist, não o texto recebido.
     * Faixa desconhecida é erro, e não "traz tudo": um engano na tela devolveria
     * silenciosamente uma lista maior do que a que o usuário pediu.
     *
     * Lista só o que está NA faixa — a base inteira já tem tela própria, com
     * filtro e paginação, em Servidores > Domínios. Aqui o valor é justamente
     * ser a lista curta e acionável do que aquele indicador acusou.
     *
     * As colunas seguem a aba Domínios da visão geral do cliente. Como lá a
     * origem é o domínio de CONTRATO e aqui é o de SERVIDOR, as colunas que
     * pertencem ao contrato (local de registro, gerenciado CDW, observações)
     * vêm pelo vínculo, por LEFT JOIN — domínio hospedado sem contrato aparece
     * assim mesmo, com essas colunas vazias, porque some-lo esconderia
     * exatamente o caso que mais merece atenção.
     *
     * O GROUP BY existe para o mesmo domínio em dois contratos não virar duas
     * linhas e a lista discordar da contagem do card. Os agregados repetem a
     * regra da contagem (MAX no managed_cdw) para que o switch inclua e exclua
     * as mesmas linhas nos dois lugares.
     */
    public function json_getdominioscard()
    {
        header('Content-Type: application/json; charset=utf-8');

        $idCompany = (int) $this->getCurrentCompanyId();
        $somenteCdw = ($this->input->post('gerenciados') === '1');
        $faixa = (string) $this->input->post('faixa');

        if (!in_array($faixa, $this->faixasDominio(), TRUE)) {
            echo json_encode([
                'success' => FALSE,
                'return' => FALSE,
                'message' => 'Faixa de domínios inválida.',
                'data' => NULL,
                'errors' => ['faixa' => 'Faixa de domínios inválida.'],
            ]);
            return;
        }

        // Cada faixa vira sua condição:
        //  - vence_30: a janela de 15 dias é do card e não está na view. O
        //    bucket já garante "vence e ainda não venceu"; o INTERVAL só aperta.
        //  - sem_contrato: anti-join. `cd.id IS NULL` com o LEFT JOIN abaixo é a
        //    mesma pergunta que o `g.id_server_domain IS NULL` da contagem, para
        //    lista e card não divergirem.
        //  - demais: a própria coluna derivada da view.
        if ($faixa === 'vence_30') {
            $condicao = "d.whois_bucket = 'vence_30' AND d.whois_expiration_date <= CURDATE() + INTERVAL " . self::DIAS_ALERTA_DOMINIO . " DAY";
        } elseif ($faixa === 'sem_contrato') {
            $condicao = "cd.id IS NULL";
        } else {
            $condicao = "d.whois_bucket = " . $this->db->escape($faixa);
        }

        $sql = "SELECT
                    d.id,
                    d.domain,
                    d.disk_used_mb,
                    d.whois_expiration_date,
                    d.whois_registrar,
                    d.whois_bucket,
                    d.server_name,
                    d.status,
                    MIN(cd.id_contract) AS id_contract,
                    COUNT(DISTINCT cd.id_contract) AS contratos,
                    MAX(cd.managed_cdw) AS managed_cdw,
                    MAX(cd.registrar) AS contract_registrar,
                    MAX(cd.comments) AS comments
                  FROM crm_servers_domains_v d
             LEFT JOIN crm_contracts_domains cd
                    ON cd.id_server_domain = d.id AND cd.id_company = ?
                 WHERE d.id_company = ?
                   AND (" . $condicao . ")
              GROUP BY d.id, d.domain, d.disk_used_mb, d.whois_expiration_date, d.whois_registrar,
                       d.whois_bucket, d.server_name, d.status";

        // HAVING, e não WHERE: o flag é agregado (o domínio pode estar em mais
        // de um contrato e basta um marcá-lo como gerenciado).
        if ($somenteCdw) $sql .= " HAVING MAX(cd.managed_cdw) = 1";

        // Vencimento mais antigo primeiro — o mais urgente no topo. Sem data (o
        // caso do domínio livre) vai para o fim: no MySQL o NULL subiria na
        // frente dos vencidos.
        $sql .= " ORDER BY d.whois_expiration_date IS NULL, d.whois_expiration_date ASC, d.domain ASC";

        // Uma linha a mais que o teto: é como se sabe que havia mais sem pagar
        // um COUNT à parte. A extra é descartada antes de montar o retorno.
        $sql .= " LIMIT " . (self::LIMITE_LISTA_DOMINIOS + 1);

        $linhas = $this->db->query($sql, [$idCompany, $idCompany])->result();

        $truncado = (count($linhas) > self::LIMITE_LISTA_DOMINIOS);
        if ($truncado) $linhas = array_slice($linhas, 0, self::LIMITE_LISTA_DOMINIOS);

        $dominios = [];
        foreach ($linhas as $linha) {
            // Local de registro: o do contrato tem precedência por ser o que o
            // usuário digitou; o do WHOIS entra como o que a origem informou.
            $registrador = !empty($linha->contract_registrar) ? $linha->contract_registrar : $linha->whois_registrar;

            $dominios[] = [
                'domain' => $linha->domain,
                'servidor' => $linha->server_name,
                'servidor_status' => (string) $linha->status,
                'em_uso' => ($linha->disk_used_mb !== NULL && $linha->disk_used_mb !== '')
                    ? number_format((float) $linha->disk_used_mb, 0, ',', '.') . ' MB'
                    : NULL,
                // Domínio livre não tem vencimento a mostrar: a data que sobrou
                // na coluna é de um registro que não existe mais.
                'vencimento' => ($linha->whois_bucket !== 'livre' && !empty($linha->whois_expiration_date))
                    ? date('d/m/Y', strtotime($linha->whois_expiration_date))
                    : NULL,
                'registrador' => !empty($registrador) ? $registrador : NULL,
                'gerenciado' => ((int) $linha->managed_cdw === 1),
                'observacoes' => !empty($linha->comments) ? $linha->comments : NULL,
                'url_contrato' => !empty($linha->id_contract)
                    ? base_url('contratos/info?id=' . (int) $linha->id_contract)
                    : NULL,
                'contratos' => (int) $linha->contratos,
            ];
        }

        echo json_encode([
            'success' => TRUE,
            'return' => TRUE,
            'message' => '',
            'data' => [
                'dominios' => $dominios,
                'total' => count($dominios),
                'truncado' => $truncado,
                'limite' => self::LIMITE_LISTA_DOMINIOS,
                'somente_cdw' => $somenteCdw,
            ],
            'errors' => [],
        ]);
    }

    /**
     * Executa um COUNT e devolve o inteiro (0 quando não há linha).
     *
     * @param  string $sql
     * @param  array  $binds
     * @return int
     */
    private function contarUm($sql, $binds = [])
    {
        $row = $this->db->query($sql, $binds)->row();
        return !empty($row) ? (int) $row->total : 0;
    }


    public function painel_filtrar()
    {
        if (empty($this->input->post('f_company'))) redirect(base_url());

        $array = array_merge($this->session->userdata('f_company'), $this->input->post('f_company'));
        // Validar o post do id_company (vazio deverá receber 0)
        if (empty($this->input->post('f_company')['id_company'])) {
            $array['id_company'] = $this->session->userdata('user')->id_company;
            $array['select2_companies'] = $this->setOptionSelect2($this->session->userdata('user')->id_company);
        } else {
            $array['select2_companies'] = $this->setOptionSelect2($this->input->post('f_company')['id_company']);
        }
        $this->session->set_userdata('f_company', $array);
        redirect($this->input->post('url'));
    }

    public function minha_conta()
    {
        $this->data['menu'] = 'painel';
        $this->data['result'] = $this->global_model->getWhere_off('crm_users', array('id' => $this->session->userdata('user')->id), TRUE);
        $this->data['company'] = $this->global_model->getWhere_off('crm_companies', array('id' => $this->session->userdata('user')->id_company), TRUE);
        $this->data['states'] = $this->global_model->getWhereOrderBy_off('crm_country_states', "1=1", "name", "asc");
        $this->data['cities'] = $this->global_model->getWhereOrderBy_off('crm_country_cities', array("id_state" => $this->session->userdata('company')->id_state), "name", "asc", FALSE);
        $this->form_validation->set_rules('name', '', 'required');
        if ($this->form_validation->run() == FALSE) {
            $this->load->view('header', $this->data);
            $this->load->view('myaccount', $this->data);
            $this->load->view('footer', $this->data);
        } else {
            $data = [
                'name' => mb_strtoupper($this->input->post('name')),
                'cellphone' => $this->input->post('cellphone'),
                'email' => $this->input->post('email'),
                'modified' => date("Y-m-d H:i:s"),
                'modified_by' => $this->session->userdata('user')->id
            ];

            // Validar a senha de acesso
            if ($this->input->post('passw1') != $this->input->post('passw2')) {
                $this->session->set_flashdata('error', 'As senhas deverão ser iguais.');
                redirect(base_url('painel/minha_conta'));
            }

            if (!empty($this->input->post('passw1'))) {
                $ret = password_strengh($this->input->post('passw1'));
                if (!$ret) {
                    $this->session->set_flashdata('error', 'A senha não cumpre os requisitos de segurança (8 caracteres com letras e números).');
                    redirect(base_url('painel/minha_conta'));
                }
                $data['password'] = password_hash($this->input->post('passw1'), PASSWORD_DEFAULT);
            }

            $new_image = $this->uploadFileLocal('foto', 'users', ["jpg", "JPG", 'jpeg', 'JPEG', 'png', 'PNG', 'jfif', 'JFIF']);
            if (!empty($new_image)) {
                $data['image'] = $new_image;
            }

            # Editar registro na tabela
            if ($this->global_model->edit('crm_users', $data, 'id', $this->input->post('id')) == TRUE) {
                # Renovara sessão do usuário
                $this->renovar_sessao();
                $this->session->set_flashdata('success', 'Registro atualizado com sucesso.');
            } else {
                $this->session->set_flashdata('error', 'Houve um erro ao atualizar o registro.');
            }
            redirect(base_url('painel/minha_conta'));
        }
    }

    public function json_setsidebar($parameter)
    {
        header('Content-Type: application/x-json; charset=utf-8');
        $this->session->set_userdata('preference_sidebar', $parameter);
        echo json_encode(['return' => TRUE]);
    }

    public function cron()
    {
        if ($this->session->userdata('user')->id_permission != 1) {
            $this->session->set_flashdata('error', 'Sem permissão de acesso.');
            redirect(base_url('painel'));
        }

        $this->data['menu'] = "painel/cron";
        $this->data['title'] = "Indicador CRON";

        // O controller Cron exige o token quando a chamada vem por URL (é como o
        // crontab dispara). Sem ele o botão EXECUTAR responderia 403, então o
        // link já sai assinado. Vazio = token não configurado na config.php
        // deste ambiente; a view avisa em vez de oferecer um botão que falha.
        $this->data['cron_token'] = (string) $this->config->item('cron_token');

        $this->data['crm_cron_logs'] = $this->global_model->getWhereOrderBy_off('crm_cron_logs', '1=1', 'name', 'asc', FALSE);
        $this->data['crm_cron_email'] = $this->global_model->getWhereOrderBy_off('crm_cron', ['service' => 'EnviarEmail'], 'id', 'desc', FALSE);
        $this->data['migrations'] = $this->global_model->getFieldsWhereSingle_off('migrations', ['version'], "1=1", TRUE);

        $this->load->view('header', $this->data);
        $this->load->view('indicators', $this->data);
        $this->load->view('footer', $this->data);
    }

    public function json_get_mail_body()
    {
        header('Content-Type: application/x-json; charset=utf-8');
        $result = $this->global_model->getWhere_off('crm_cron', ['id' => $this->input->post('id')], TRUE);
        if (empty($result)) {
            echo json_encode(['return' => FALSE]);
            exit;
        }

        $parameters = json_decode($result->parameters);
        echo json_encode(['return' => TRUE, 'title' => $parameters->title, 'body' => $parameters->body]);
    }

    public function json_posttoggle_cron_active()
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($this->session->userdata('user')->id_permission != 1) {
            echo json_encode([
                'success' => FALSE,
                'return' => FALSE,
                'message' => 'Sem permissão de acesso.',
                'data' => NULL,
                'errors' => ['permission' => 'Sem permissão de acesso.']
            ]);
            return;
        }

        $id = (int) $this->input->post('id');
        $ativar = $this->input->post('ativar') === '1';

        if ($id <= 0) {
            echo json_encode([
                'success' => FALSE,
                'return' => FALSE,
                'message' => 'Dados inválidos.',
                'data' => NULL,
                'errors' => ['id' => 'Dados inválidos.']
            ]);
            return;
        }

        $record = $this->global_model->getWhere_off('crm_cron_logs', ['id' => $id], TRUE);
        if (empty($record)) {
            echo json_encode([
                'success' => FALSE,
                'return' => FALSE,
                'message' => 'Registro não encontrado.',
                'data' => NULL,
                'errors' => ['id' => 'Registro não encontrado.']
            ]);
            return;
        }

        $active = $ativar ? 'S' : 'N';
        $this->global_model->edit('crm_cron_logs', ['active' => $active], 'id', $id);

        echo json_encode([
            'success' => TRUE,
            'return' => TRUE,
            'message' => $ativar ? 'Processo automático ativado com sucesso.' : 'Processo automático desativado com sucesso.',
            'data' => [
                'id' => $id,
                'active' => $active
            ],
            'errors' => []
        ]);
    }

}
