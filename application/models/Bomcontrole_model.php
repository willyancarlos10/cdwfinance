<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Regras da integração com o ERP Bom Controle: config por tenant, vínculo do
 * contrato, montagem do Extrato Bom Controle e sincronização do cadastro do
 * cliente. Os controllers só chamam este model — quem fala HTTP é a library
 * Bom_controle.
 *
 * A integração tem duas metades com naturezas opostas: o extrato é LEITURA ao
 * vivo (nada é copiado para cá), e a sincronização de cadastro é ESCRITA de mão
 * única (daqui para lá) — do ERP só se guarda o Id do cliente e a data da
 * última sincronização.
 *
 * A config vive em `crm_companies` (`bomcontrole_active`, `bomcontrole_base_url`,
 * `bomcontrole_secret`): cada empresa tem a própria conta no Bom Controle. A
 * credencial trafega cifrada e só é decifrada aqui (getApiKey), mesmo desenho
 * do Server_model.
 *
 * A ponte entre os dois sistemas é o CPF/CNPJ do cliente:
 * `crm_customers.document` (só dígitos) → `VendaContrato/Pesquisar` → refiltro
 * pelo documento EXATO do Cliente retornado (a busca da API é ampla e devolve
 * itens de outros clientes) → revalidação do documento de novo na hora de
 * gravar o vínculo (o POST nunca é fonte de verdade).
 *
 * O extrato sai inteiro do `VendaContrato/Obter` do PRÓPRIO contrato, em duas
 * chamadas (em aberto e quitadas) — ver buscarFaturasPagas() para o fallback.
 */
class Bomcontrole_model extends CI_Model
{
    /** Faturas a vencer entram no extrato até este horizonte. */
    const DIAS_FUTURO = 60;

    /** Faturas pagas entram até este passado — 13 e não 12: cliente que paga 1x/ano. */
    const MESES_PAGAS = 13;

    /** Teto de candidatos no modal de vínculo. */
    const MAX_CANDIDATOS = 10;

    /** Teto de páginas por listagem da API (100 itens cada). */
    const MAX_PAGINAS = 5;

    /** Teto de serviços do catálogo devolvidos à tela por busca. */
    const MAX_SERVICOS = 100;

    /**
     * Timeout do caminho SÍNCRONO da sincronização de cadastro (wizard público
     * e edição no painel), em segundos.
     *
     * Menor que o TIMEOUT_PADRAO da library de propósito: a sincronização gasta
     * até três chamadas, e a 30s cada o cliente final poderia esperar 90s
     * olhando para um formulário travado. O botão manual, que roda em AJAX e
     * tem o usuário ciente de que é uma operação de rede, usa o padrão.
     */
    const TIMEOUT_SINCRONIZACAO = 12;

    /**
     * Fonte das faturas PAGAS do extrato.
     *
     * FALSE = `VendaContrato/Obter` com `quitadas=true` (por CONTRATO — o
     * comportamento que a collection oficial documenta). O gestor-interno
     * observou que o Obter SEM o parâmetro não devolvia pagas; se o primeiro
     * teste com chave real mostrar que `quitadas=true` também não devolve,
     * vire para TRUE: as pagas passam a vir do `Financeiro/Pesquisar`, que
     * filtra por CLIENTE — parcelas de outros contratos do mesmo cliente se
     * misturam, e a UI mostra a nota (`aviso_pagas`).
     */
    const PAGAS_VIA_FINANCEIRO = FALSE;

    public function __construct()
    {
        parent::__construct();
        $this->load->library('secret_crypto');
    }

    // ------------------------------------------------------------------
    // Config por tenant
    // ------------------------------------------------------------------

    /**
     * @param  int $idCompany
     * @return bool
     */
    public function isActive($idCompany)
    {
        $linha = $this->global_model->getFieldsWhereSingle_off(
            'crm_companies',
            'bomcontrole_active',
            ['id' => (int) $idCompany],
            TRUE
        );
        return !empty($linha) && (int) $linha->bomcontrole_active === 1;
    }

    /**
     * Chave da API da empresa, decifrada.
     *
     * O decrypt mora aqui, e não no controller, pelo mesmo motivo do
     * Server_model::getConnectionConfig: um ponto só decifra.
     *
     * @param  int $idCompany
     * @return string|bool '' = nunca cadastrada; FALSE = ilegível (chave de
     *                     criptografia trocada); string = ok
     */
    public function getApiKey($idCompany)
    {
        $linha = $this->global_model->getFieldsWhereSingle_off(
            'crm_companies',
            'bomcontrole_secret',
            ['id' => (int) $idCompany],
            TRUE
        );

        $cifrada = empty($linha) ? '' : (string) $linha->bomcontrole_secret;
        if ($cifrada === '') {
            return '';
        }

        // === FALSE, nunca empty(): '' é retorno válido da Secret_crypto.
        $plana = $this->secret_crypto->decrypt($cifrada);
        if ($plana === FALSE) {
            return FALSE;
        }

        return trim($plana);
    }

    /**
     * Empresa tem chave gravada? (sem decifrar — para a tela mostrar o badge.)
     *
     * @param  int $idCompany
     * @return bool
     */
    public function hasKey($idCompany)
    {
        $linha = $this->global_model->getFieldsWhereSingle_off(
            'crm_companies',
            'bomcontrole_secret',
            ['id' => (int) $idCompany],
            TRUE
        );
        return !empty($linha) && (string) $linha->bomcontrole_secret !== '';
    }

    /**
     * Config de conexão pronta para a library, com o diagnóstico certo para
     * cada estado inválido.
     *
     * @param  int $idCompany
     * @return array success, message, config => ['base_url', 'api_key']
     */
    public function getConfig($idCompany)
    {
        $empresa = $this->global_model->getFieldsWhereSingle_off(
            'crm_companies',
            'id, bomcontrole_active, bomcontrole_base_url',
            ['id' => (int) $idCompany],
            TRUE
        );
        if (empty($empresa)) {
            return ['success' => FALSE, 'message' => 'Empresa não encontrada.', 'config' => NULL];
        }

        if ((int) $empresa->bomcontrole_active !== 1) {
            return [
                'success' => FALSE,
                'message' => 'Integração com o Bom Controle desativada para esta empresa. Ative-a no cadastro da empresa.',
                'config' => NULL,
            ];
        }

        $chave = $this->getApiKey($idCompany);
        if ($chave === '') {
            return [
                'success' => FALSE,
                'message' => 'Nenhuma chave do Bom Controle cadastrada para esta empresa.',
                'config' => NULL,
            ];
        }
        if ($chave === FALSE) {
            return [
                'success' => FALSE,
                'message' => 'Não foi possível ler a chave do Bom Controle desta empresa. Recadastre-a — a chave de criptografia pode ter sido trocada.',
                'config' => NULL,
            ];
        }

        return [
            'success' => TRUE,
            'message' => '',
            'config' => [
                'base_url' => (string) $empresa->bomcontrole_base_url,
                'api_key' => $chave,
            ],
        ];
    }

    /**
     * Grava a config da empresa. Chave NULL = manter a atual (campo em branco
     * na tela); string = cifrar e trocar.
     *
     * @param  int         $idCompany
     * @param  bool        $active
     * @param  string      $baseUrl já validada pelo controller
     * @param  string|null $chave
     * @param  int         $idUser
     * @return array success, message
     */
    public function salvarConfig($idCompany, $active, $baseUrl, $chave, $idUser)
    {
        $empresa = $this->global_model->getFieldsWhereSingle_off(
            'crm_companies',
            'id',
            ['id' => (int) $idCompany],
            TRUE
        );
        if (empty($empresa)) {
            return ['success' => FALSE, 'message' => 'Empresa não encontrada.'];
        }

        $values = [
            'bomcontrole_active' => $active ? 1 : 0,
            'bomcontrole_base_url' => (string) $baseUrl,
            'modified' => date('Y-m-d H:i:s'),
            'modified_by' => (int) $idUser,
        ];

        if ($chave !== NULL) {
            if (!$this->secret_crypto->isReady()) {
                return [
                    'success' => FALSE,
                    'message' => 'A chave de criptografia (secret_crypto_key) não está configurada — a chave da API não pode ser gravada.',
                ];
            }

            $cifrada = $this->secret_crypto->encrypt($chave);
            if ($cifrada === FALSE) {
                return ['success' => FALSE, 'message' => 'Não foi possível cifrar a chave da API. Nada foi gravado.'];
            }

            $values['bomcontrole_secret'] = $cifrada;
        }

        $this->global_model->edit('crm_companies', $values, 'id', (int) $idCompany);

        return ['success' => TRUE, 'message' => ''];
    }

    // ------------------------------------------------------------------
    // Operações
    // ------------------------------------------------------------------

    /**
     * Testa a chave SALVA da empresa contra a API.
     *
     * @param  int $idCompany
     * @return array success, message
     */
    public function testConnection($idCompany)
    {
        $config = $this->getConfig($idCompany);
        if (!$config['success']) {
            return ['success' => FALSE, 'message' => $config['message']];
        }

        $cliente = $this->client();

        // Chamada de rede longa: solta o lock de sessão do MySQL antes. Nada
        // de escrever na sessão até a retomada.
        $sessao = sessao_suspender();
        try {
            $resultado = $cliente->test($config['config']);
        } catch (Throwable $e) {
            $resultado = ['success' => FALSE, 'message' => 'Falha inesperada: ' . $e->getMessage()];
        } finally {
            sessao_retomar($sessao);
        }

        return ['success' => !empty($resultado['success']), 'message' => (string) $resultado['message']];
    }

    /**
     * Contratos do Bom Controle candidatos ao vínculo: os que pertencem ao
     * documento EXATO do cliente do contrato local.
     *
     * @param  int $idContract
     * @param  int $idCompany
     * @return array success, message, data => ['documento', 'candidatos']
     */
    public function buscarCandidatos($idContract, $idCompany)
    {
        $contexto = $this->carregarContexto($idContract, $idCompany);
        if (!$contexto['success']) {
            return $contexto;
        }

        $documento = $contexto['documento'];
        $config = $contexto['config'];

        $sessao = sessao_suspender();
        try {
            $resultado = $this->pesquisarPorDocumento($config, $documento);
            if ($resultado['success']) {
                // Detalhe de cada candidato: o Valor da pesquisa é o valor
                // inicial do contrato, sem reajustes — a fatura mais recente
                // conta a história atual. Falha individual não derruba a
                // lista: o candidato sai sem fatura_recente.
                foreach ($resultado['candidatos'] as $i => $candidato) {
                    $detalhe = $this->client()->obterContrato($config, $candidato['id']);
                    if ($detalhe['success'] && is_array($detalhe['data'])) {
                        $faturas = isset($detalhe['data']['Faturas']) && is_array($detalhe['data']['Faturas'])
                            ? $detalhe['data']['Faturas'] : [];
                        $resultado['candidatos'][$i]['fatura_recente'] = $this->faturaMaisRecente($faturas);
                    }
                }
            }
        } catch (Throwable $e) {
            $resultado = ['success' => FALSE, 'message' => 'Falha inesperada: ' . $e->getMessage()];
        } finally {
            sessao_retomar($sessao);
        }

        if (!$resultado['success']) {
            return ['success' => FALSE, 'message' => $resultado['message'], 'data' => NULL];
        }

        // Aviso de reuso: outro contrato local do tenant já usa este id BC.
        // Query local, fora do bloco de rede.
        foreach ($resultado['candidatos'] as $i => $candidato) {
            $emUso = $this->global_model->getFieldsWhereSingle_off(
                'crm_contracts',
                'id',
                ['bomcontrole_contract_id' => $candidato['id'], 'id_company' => (int) $idCompany],
                TRUE
            );
            $resultado['candidatos'][$i]['ja_vinculado_ao_contrato'] =
                (!empty($emUso) && (int) $emUso->id !== (int) $idContract) ? (int) $emUso->id : NULL;
        }

        return [
            'success' => TRUE,
            'message' => '',
            'data' => [
                'documento' => $documento,
                'candidatos' => $resultado['candidatos'],
            ],
        ];
    }

    /**
     * Grava o vínculo, revalidando no servidor que o contrato BC pertence ao
     * documento do cliente — o id que veio do POST nunca é fonte de verdade.
     *
     * Contrato encerrado PODE vincular: consultar extrato é leitura, como
     * anexar o distrato depois do encerramento.
     *
     * @param  int $idContract
     * @param  int $idCompany
     * @param  int $idBcContract
     * @return array success, message, data
     */
    public function vincular($idContract, $idCompany, $idBcContract)
    {
        $idBcContract = (int) $idBcContract;
        if ($idBcContract <= 0) {
            return ['success' => FALSE, 'message' => 'Contrato do Bom Controle inválido.', 'data' => NULL];
        }

        $contexto = $this->carregarContexto($idContract, $idCompany);
        if (!$contexto['success']) {
            return $contexto;
        }

        $sessao = sessao_suspender();
        try {
            $detalhe = $this->client()->obterContrato($contexto['config'], $idBcContract);
        } catch (Throwable $e) {
            $detalhe = ['success' => FALSE, 'message' => 'Falha inesperada: ' . $e->getMessage()];
        } finally {
            sessao_retomar($sessao);
        }

        if (!$detalhe['success']) {
            return ['success' => FALSE, 'message' => $detalhe['message'], 'data' => NULL];
        }

        $clienteBc = isset($detalhe['data']['Cliente']) && is_array($detalhe['data']['Cliente'])
            ? $detalhe['data']['Cliente'] : [];
        if ($this->documentoDoCliente($clienteBc) !== $contexto['documento']) {
            return [
                'success' => FALSE,
                'message' => 'O contrato #' . $idBcContract . ' do Bom Controle pertence a outro documento — o CPF/CNPJ não confere com o do cliente.',
                'data' => NULL,
            ];
        }

        // Não mexe em modified/modified_by: são a trilha da edição de dados
        // gerais, e o vínculo tem carimbo próprio.
        $this->global_model->edit('crm_contracts', [
            'bomcontrole_contract_id' => $idBcContract,
            'bomcontrole_linked' => date('Y-m-d H:i:s'),
        ], 'id', (int) $idContract);

        return [
            'success' => TRUE,
            'message' => 'Contrato vinculado ao Bom Controle #' . $idBcContract . '.',
            'data' => ['bomcontrole_contract_id' => $idBcContract],
        ];
    }

    /**
     * @param  int $idContract
     * @param  int $idCompany
     * @return array success, message, data
     */
    public function desvincular($idContract, $idCompany)
    {
        $contrato = $this->global_model->getWhere_off(
            'crm_contracts',
            ['id' => (int) $idContract, 'id_company' => (int) $idCompany],
            TRUE
        );
        if (empty($contrato)) {
            return ['success' => FALSE, 'message' => 'Contrato não encontrado.', 'data' => NULL];
        }

        $this->global_model->edit('crm_contracts', [
            'bomcontrole_contract_id' => NULL,
            'bomcontrole_linked' => NULL,
        ], 'id', (int) $idContract);

        return ['success' => TRUE, 'message' => 'Vínculo com o Bom Controle removido.', 'data' => NULL];
    }

    // ------------------------------------------------------------------
    // Catálogo de serviços do ERP
    //
    // O contrato guarda o Id do serviço que a emissão vai usar em
    // `Servicos[].IdServico`. O vínculo é por CONTRATO (e não por tipo de
    // serviço) porque a fatura tem um valor só: com um serviço por contrato, a
    // venda no ERP sai com um item e o valor da fatura, sem rateio inventado
    // entre os dois ou três tipos que a maioria dos contratos tem.
    // ------------------------------------------------------------------

    /**
     * Busca serviços no catálogo do ERP.
     *
     * Sob demanda, a partir do que o usuário digitou: o rate limit do Bom
     * Controle é agressivo (uma dúzia de chamadas seguidas já devolve 429),
     * então varrer o catálogo a cada abertura de tela não é opção.
     *
     * @param  int    $idCompany
     * @param  string $termo vazio = primeira página do catálogo
     * @return array success, message, data => ['servicos', 'total', 'exibindo']
     */
    public function buscarServicos($idCompany, $termo = '')
    {
        $config = $this->getConfig($idCompany);
        if (!$config['success']) {
            return ['success' => FALSE, 'message' => $config['message'], 'data' => NULL];
        }

        $suspendeu = sessao_suspender();

        try {
            $resposta = $this->client()->pesquisarServicos(
                $config['config'],
                trim((string) $termo),
                self::MAX_SERVICOS,
                1
            );
        } catch (Throwable $e) {
            $this->logarErroServico($idCompany, 'pesquisar', $e->getMessage());
            return ['success' => FALSE, 'message' => 'Falha inesperada ao consultar o catálogo do Bom Controle.', 'data' => NULL];
        } finally {
            sessao_retomar($suspendeu);
        }

        if (!$resposta['success']) {
            $this->logarErroServico($idCompany, 'pesquisar', $resposta['message']);
            return ['success' => FALSE, 'message' => $resposta['message'], 'data' => NULL];
        }

        $servicos = [];
        foreach ((array) $resposta['data']['Itens'] as $item) {
            if (!is_array($item)) continue;

            $id = (int) $this->campo($item, ['Id'], 0);
            if ($id <= 0) continue;

            $servicos[] = [
                'id' => $id,
                'nome' => trim((string) $this->campo($item, ['Nome'], '')),
                'observacao' => trim((string) $this->campo($item, ['Observacao'], '')),
                // O tipo de serviço é o código fiscal (LC 116) e determina a
                // tributação da NFS-e — por isso vai para a tela, e não só o nome.
                'tipo' => trim((string) $this->campo($item, ['NomeTipoServico'], '')),
            ];
        }

        return [
            'success' => TRUE,
            'message' => '',
            'data' => [
                'servicos' => $servicos,
                'total' => (int) $resposta['data']['TotalItens'],
                'exibindo' => count($servicos),
            ],
        ];
    }

    /**
     * Vincula o serviço do ERP ao contrato.
     *
     * O Id vem da tela, mas quem confirma que ele existe é o `Servico/Obter` —
     * o POST nunca é fonte de verdade, mesma regra do vínculo de contrato. É de
     * lá que sai o nome gravado, e não do POST: assim o retrato guardado é o do
     * ERP, não o que o navegador mandou.
     *
     * @param  int $idContract
     * @param  int $idCompany
     * @param  int $idServicoBc
     * @param  int $idUser
     * @return array success, message, data
     */
    public function vincularServico($idContract, $idCompany, $idServicoBc, $idUser)
    {
        $contrato = $this->global_model->getWhere_off('crm_contracts', [
            'id' => (int) $idContract,
            'id_company' => (int) $idCompany,
        ], TRUE);

        if (empty($contrato)) {
            return ['success' => FALSE, 'message' => 'Contrato não encontrado.', 'data' => NULL];
        }

        $idServicoBc = (int) $idServicoBc;
        if ($idServicoBc <= 0) {
            return ['success' => FALSE, 'message' => 'Selecione um serviço do catálogo.', 'data' => NULL];
        }

        $config = $this->getConfig($idCompany);
        if (!$config['success']) {
            return ['success' => FALSE, 'message' => $config['message'], 'data' => NULL];
        }

        $suspendeu = sessao_suspender();

        try {
            $resposta = $this->client()->obterServico($config['config'], $idServicoBc);
        } catch (Throwable $e) {
            $this->logarErroServico($idCompany, 'obter', $e->getMessage());
            return ['success' => FALSE, 'message' => 'Falha inesperada ao confirmar o serviço no Bom Controle.', 'data' => NULL];
        } finally {
            sessao_retomar($suspendeu);
        }

        if (!$resposta['success']) {
            $this->logarErroServico($idCompany, 'obter#' . $idServicoBc, $resposta['message']);
            return ['success' => FALSE, 'message' => 'O Bom Controle não reconheceu o serviço #' . $idServicoBc . '. ' . $resposta['message'], 'data' => NULL];
        }

        $servico = is_array($resposta['data']) ? $resposta['data'] : [];
        $idConfirmado = (int) $this->campo($servico, ['Id'], 0);
        if ($idConfirmado !== $idServicoBc) {
            return ['success' => FALSE, 'message' => 'A resposta do Bom Controle não corresponde ao serviço pedido.', 'data' => NULL];
        }

        $nome = trim((string) $this->campo($servico, ['Nome'], ''));

        $this->global_model->edit('crm_contracts', [
            'bomcontrole_service_id' => $idServicoBc,
            'bomcontrole_service_name' => mb_substr($nome, 0, 200),
            'modified' => date('Y-m-d H:i:s'),
            'modified_by' => (int) $idUser,
        ], 'id', (int) $idContract);

        return [
            'success' => TRUE,
            'message' => 'Serviço vinculado: ' . $nome,
            'data' => [
                'id' => $idServicoBc,
                'nome' => $nome,
                'tipo' => trim((string) $this->campo($servico, ['NomeTipoServico'], '')),
            ],
        ];
    }

    /**
     * Remove o vínculo. Não toca no ERP — o catálogo de lá não muda.
     *
     * @param  int $idContract
     * @param  int $idCompany
     * @param  int $idUser
     * @return array success, message, data
     */
    public function desvincularServico($idContract, $idCompany, $idUser)
    {
        $contrato = $this->global_model->getWhere_off('crm_contracts', [
            'id' => (int) $idContract,
            'id_company' => (int) $idCompany,
        ], TRUE);

        if (empty($contrato)) {
            return ['success' => FALSE, 'message' => 'Contrato não encontrado.', 'data' => NULL];
        }

        $this->global_model->edit('crm_contracts', [
            'bomcontrole_service_id' => NULL,
            'bomcontrole_service_name' => NULL,
            'modified' => date('Y-m-d H:i:s'),
            'modified_by' => (int) $idUser,
        ], 'id', (int) $idContract);

        return ['success' => TRUE, 'message' => 'Serviço desvinculado.', 'data' => NULL];
    }

    /**
     * @param int    $idCompany
     * @param string $operacao
     * @param string $mensagem
     */
    private function logarErroServico($idCompany, $operacao, $mensagem)
    {
        log_message('error', sprintf(
            '[BOMCONTROLE] Catalogo de servicos — empresa=%d operacao=%s: %s',
            (int) $idCompany,
            (string) $operacao,
            (string) $mensagem
        ));
    }

    // ------------------------------------------------------------------
    // Sincronização do CADASTRO do cliente
    //
    // Sobe crm_customers para o ERP. Diferente do vínculo de contrato, que é
    // leitura, aqui se escreve — e o desenho todo gira em torno de não criar
    // cliente duplicado no ERP:
    //
    //  - o Id gravado localmente é a primeira fonte;
    //  - sem ele, procura pelo documento e ADOTA o que já existir (a base foi
    //    cadastrada à mão no ERP antes deste sistema existir);
    //  - só cria quando a busca não achou nada.
    //
    // Nenhum dado cadastral do ERP é copiado para cá: o fluxo é de mão única
    // (daqui para lá), e o que se guarda é só o Id e a data.
    // ------------------------------------------------------------------

    /**
     * Cria ou atualiza o cadastro do cliente no Bom Controle.
     *
     * @param  int      $idCustomer
     * @param  int      $idCompany
     * @param  int|null $timeout segundos; NULL = padrão da library
     * @return array success, message, data => ['id_bc', 'acao', 'synced']
     *               acao: criado | adotado | atualizado | ignorado
     */
    public function sincronizarCliente($idCustomer, $idCompany, $timeout = NULL)
    {
        $idCustomer = (int) $idCustomer;
        $idCompany = (int) $idCompany;

        $config = $this->getConfig($idCompany);
        if (!$config['success']) {
            // Tenant sem a integração não é erro: quem chama trata como
            // silêncio, senão todo cadastro de quem não usa o ERP viraria
            // aviso na tela.
            return $this->respostaSync(FALSE, $config['message'], NULL, 'ignorado');
        }

        $cliente = $this->global_model->getWhere_off(
            'crm_customers_v',
            ['id' => $idCustomer, 'id_company' => $idCompany],
            TRUE
        );
        if (empty($cliente)) {
            return $this->respostaSync(FALSE, 'Cliente não encontrado.', NULL, 'ignorado');
        }

        $documento = preg_replace('/\D/', '', (string) $cliente->document);
        if (!in_array(strlen($documento), [11, 14], TRUE)) {
            $mensagem = 'O documento do cliente não é um CPF/CNPJ válido — corrija o cadastro antes de sincronizar.';
            $this->logarErroCliente($idCompany, $idCustomer, $documento, 'validacao', $mensagem);
            return $this->respostaSync(FALSE, $mensagem, NULL, 'ignorado');
        }

        if ($timeout !== NULL) {
            $config['config']['timeout'] = (int) $timeout;
        }

        $idBc = (int) $cliente->bomcontrole_customer_id;
        $suspendeu = sessao_suspender();

        try {
            // 1. Descobrir o Id no ERP, quando ainda não há vínculo.
            if ($idBc <= 0) {
                $encontrado = $this->procurarClientePorDocumento($config['config'], $documento);
                if (!$encontrado['success']) {
                    $this->logarErroCliente($idCompany, $idCustomer, $documento, 'pesquisar', $encontrado['message']);
                    return $this->respostaSync(FALSE, $encontrado['message'], NULL, 'ignorado');
                }

                if ($encontrado['id'] > 0) {
                    $idBc = $encontrado['id'];
                    $acao = 'adotado';
                } else {
                    // 2. Não existe lá: cria com identificação + endereço +
                    //    contatos (o Criar é o único que aceita os três juntos).
                    $contatos = $this->contatosParaBomControle($cliente);
                    $payload = $this->montarPayloadCliente($cliente, $documento);
                    $payload['Endereco'] = $this->montarEnderecoCliente($cliente);
                    if (!empty($contatos)) {
                        $payload['Contatos'] = $contatos;
                    }

                    $criado = $this->client()->criarCliente($config['config'], $payload);
                    if (!$criado['success']) {
                        $this->logarErroCliente($idCompany, $idCustomer, $documento, 'criar', $criado['message']);
                        return $this->respostaSync(FALSE, $criado['message'], NULL, 'ignorado');
                    }

                    // O Criar responde o Id como inteiro puro no corpo.
                    $idBc = (int) $criado['data'];
                    if ($idBc <= 0) {
                        $mensagem = 'O Bom Controle não devolveu o Id do cliente criado.';
                        $this->logarErroCliente($idCompany, $idCustomer, $documento, 'criar', $mensagem);
                        return $this->respostaSync(FALSE, $mensagem, NULL, 'ignorado');
                    }

                    $acao = 'criado';
                }
            } else {
                $acao = 'atualizado';
            }

            // 3. Atualiza quem já existia lá (adotado ou já vinculado). Duas
            //    chamadas porque a API separa identificação de endereço — e o
            //    Alterar não aceita Endereco.
            if ($acao !== 'criado') {
                $alterado = $this->client()->alterarCliente(
                    $config['config'],
                    $idBc,
                    $this->montarPayloadCliente($cliente, $documento)
                );
                if (!$alterado['success']) {
                    $this->logarErroCliente($idCompany, $idCustomer, $documento, 'alterar', $alterado['message']);
                    return $this->respostaSync(FALSE, $alterado['message'], NULL, 'ignorado');
                }

                $endereco = $this->client()->alterarEnderecoCliente(
                    $config['config'],
                    $idBc,
                    $this->montarEnderecoCliente($cliente)
                );
                if (!$endereco['success']) {
                    $this->logarErroCliente($idCompany, $idCustomer, $documento, 'alterar_endereco', $endereco['message']);
                    return $this->respostaSync(FALSE, $endereco['message'], NULL, 'ignorado');
                }
            }
        } catch (Throwable $e) {
            $this->logarErroCliente($idCompany, $idCustomer, $documento, 'excecao', $e->getMessage());
            return $this->respostaSync(FALSE, 'Falha inesperada ao falar com o Bom Controle.', NULL, 'ignorado');
        } finally {
            sessao_retomar($suspendeu);
        }

        // 4. Grava o vínculo FORA do bloco de rede (a sessão já voltou).
        $agora = date('Y-m-d H:i:s');
        $this->global_model->edit('crm_customers', [
            'bomcontrole_customer_id' => $idBc,
            'bomcontrole_synced' => $agora,
        ], 'id', $idCustomer);

        $rotulos = [
            'criado' => 'Cliente cadastrado no Bom Controle.',
            'adotado' => 'Cliente já existia no Bom Controle: cadastro vinculado e atualizado.',
            'atualizado' => 'Cadastro atualizado no Bom Controle.',
        ];

        return $this->respostaSync(TRUE, $rotulos[$acao], $idBc, $acao, $agora);
    }

    /**
     * Procura o cliente no ERP pelo documento exato.
     *
     * A busca da API é ampla (casa por parte do nome OU do documento), então o
     * refiltro exato é obrigatório — mesma regra do vínculo de contrato.
     *
     * Mais de um cliente com o MESMO documento é recusa, não escolha: adotar o
     * primeiro carimbaria o vínculo num registro possivelmente errado, e daí em
     * diante todo extrato sairia do cliente errado. Vínculo ausente se resolve;
     * vínculo errado passa despercebido.
     *
     * @param  array  $config
     * @param  string $documento só dígitos
     * @return array success, message, id (0 = não existe lá)
     */
    private function procurarClientePorDocumento(array $config, $documento)
    {
        $resposta = $this->client()->pesquisarClientes($config, $documento);
        if (!$resposta['success']) {
            return ['success' => FALSE, 'message' => $resposta['message'], 'id' => 0];
        }

        $ids = [];
        foreach ((array) $resposta['data'] as $item) {
            if (!is_array($item)) continue;
            if ($this->documentoDoCliente($item) !== $documento) continue;

            $id = (int) $this->campo($item, ['Id'], 0);
            if ($id > 0) $ids[$id] = TRUE;
        }

        $ids = array_keys($ids);

        if (count($ids) > 1) {
            return [
                'success' => FALSE,
                'message' => 'O Bom Controle tem mais de um cliente com este CPF/CNPJ (ids ' . implode(', ', $ids)
                    . '). Resolva a duplicidade no ERP antes de sincronizar.',
                'id' => 0,
            ];
        }

        return ['success' => TRUE, 'message' => '', 'id' => empty($ids) ? 0 : (int) $ids[0]];
    }

    /**
     * Identificação do cliente no formato do ERP.
     *
     * Só um dos dois objetos vai: `type` decide. Campos que este cadastro não
     * coleta (Sexo, InscricaoMunicipal, RamoAtividade) ficam de FORA — mandar
     * NULL num cliente adotado apagaria dado bom que já existe lá, o mesmo
     * cuidado que o módulo de Servidores tem com plano/IP/cota.
     *
     * @param  object $cliente linha da crm_customers_v
     * @param  string $documento só dígitos
     * @return array
     */
    private function montarPayloadCliente($cliente, $documento)
    {
        $nome = trim((string) $cliente->name);

        if ((string) $cliente->type === 'F') {
            return [
                'PessoaFisica' => [
                    'Documento' => $documento,
                    'Nome' => $nome,
                ],
            ];
        }

        // NomeFantasia é obrigatório no ERP e `byname` é NULLable aqui.
        $fantasia = trim((string) $cliente->byname);
        if ($fantasia === '') $fantasia = $nome;

        $pj = [
            'Documento' => $documento,
            'RazaoSocial' => $nome,
            'NomeFantasia' => $fantasia,
        ];

        // O trio da inscrição estadual anda junto: mandar o número sem virar o
        // isento para FALSE deixaria o ERP num estado contraditório.
        $ie = $this->inscricaoEstadual($cliente);
        if ($ie === '') {
            $pj['IsentoInscricaoEstadual'] = TRUE;
            $pj['InscricaoEstadual'] = NULL;
            $pj['UFInscricaoEstadual'] = NULL;
        } else {
            $pj['IsentoInscricaoEstadual'] = FALSE;
            $pj['InscricaoEstadual'] = $ie;
            $pj['UFInscricaoEstadual'] = trim((string) $cliente->state_uf);
        }

        return ['PessoaFisica' => NULL, 'PessoaJuridica' => $pj];
    }

    /**
     * Inscrição estadual efetiva: '' quando isento.
     *
     * Só PJ tem inscrição estadual — em PF a coluna fica com o default e nunca
     * chega ao ERP (o objeto PessoaFisica não tem essas chaves).
     *
     * @param  object $cliente
     * @return string
     */
    private function inscricaoEstadual($cliente)
    {
        $ie = trim((string) $cliente->state_registration);
        return (mb_strtoupper($ie) === 'ISENTO') ? '' : $ie;
    }

    /**
     * Endereço no formato PLANO — é o que o AlterarEndereco espera; no Criar
     * ele entra embrulhado na chave `Endereco`.
     *
     * @param  object $cliente linha da crm_customers_v
     * @return array
     */
    private function montarEnderecoCliente($cliente)
    {
        $logradouro = $this->separarTipoLogradouro((string) $cliente->address);

        $endereco = [
            'Logradouro' => $logradouro['logradouro'],
            'Numero' => trim((string) $cliente->address_number),
            'Complemento' => trim((string) $cliente->address_complement),
            'Bairro' => trim((string) $cliente->address_district),
            'Cep' => $this->cepParaBomControle((string) $cliente->address_zip),
            // city_name/state_uf vêm por LEFT JOIN na view: são NULL quando a
            // cidade não foi resolvida, e o ERP aceita endereço incompleto.
            'Cidade' => trim((string) $cliente->city_name),
            'Uf' => trim((string) $cliente->state_uf),
        ];

        // Omitido quando não se reconheceu o tipo: o ERP assume "Rua" sozinho,
        // e chutar aqui inventaria um dado que o cadastro não tem.
        if ($logradouro['tipo'] !== '') {
            $endereco['TipoLogradouro'] = $logradouro['tipo'];
        }

        return $endereco;
    }

    /**
     * Separa "Rua Niterói" em tipo + logradouro.
     *
     * O ERP tem TipoLogradouro como campo próprio (enum) e assume "Rua" quando
     * ele falta; nosso `address` vem do ViaCEP com o tipo embutido. Mandar o
     * texto inteiro produziria "Rua Rua Niterói" na tela do ERP.
     *
     * @param  string $address
     * @return array ['tipo' => string, 'logradouro' => string]
     */
    private function separarTipoLogradouro($address)
    {
        $address = trim(preg_replace('/\s+/u', ' ', $address));
        if ($address === '') {
            return ['tipo' => '', 'logradouro' => ''];
        }

        // Enum documentado do ERP, com as abreviações que de fato aparecem na
        // base ("Av Paraná" viraria "Rua Av Paraná" no ERP sem isto). 'PSG'
        // (passagem) fica de fora: não aparece escrita por extenso no ViaCEP.
        $tipos = [
            'RUA' => 'Rua',
            'R' => 'Rua',
            'AVENIDA' => 'Avenida',
            'AV' => 'Avenida',
            'AVE' => 'Avenida',
            'TRAVESSA' => 'Travessa',
            'TV' => 'Travessa',
            'TRAV' => 'Travessa',
            'ALAMEDA' => 'Alameda',
            'AL' => 'Alameda',
            'ESTRADA' => 'Estrada',
            'EST' => 'Estrada',
            'RODOVIA' => 'Rodovia',
            'ROD' => 'Rodovia',
            'QUADRA' => 'Quadra',
        ];

        $partes = explode(' ', $address, 2);
        // O ponto da abreviação ("Av.") não faz parte do nome do tipo.
        $primeira = mb_strtoupper($this->semAcento(rtrim($partes[0], '.')));

        if (isset($tipos[$primeira]) && isset($partes[1]) && trim($partes[1]) !== '') {
            return ['tipo' => $tipos[$primeira], 'logradouro' => trim($partes[1])];
        }

        return ['tipo' => '', 'logradouro' => $address];
    }

    /**
     * @param  string $texto
     * @return string
     */
    private function semAcento($texto)
    {
        $de = ['á','à','ã','â','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','õ','ô','ö','ú','ù','û','ü','ç'];
        $para = ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c'];
        return str_replace($de, $para, mb_strtolower($texto));
    }

    /**
     * O cadastro guarda `00.000-000`; o ERP usa `00000-000`.
     *
     * @param  string $cep
     * @return string
     */
    private function cepParaBomControle($cep)
    {
        $digitos = preg_replace('/\D/', '', (string) $cep);
        if (strlen($digitos) !== 8) {
            return trim((string) $cep);
        }

        return substr($digitos, 0, 5) . '-' . substr($digitos, 5);
    }

    /**
     * Contatos para o `Cliente/Criar` — e SÓ para ele.
     *
     * O ERP exige Nome, Email e Telefone em cada item, enquanto `email` e
     * `phone` são NULLable aqui: contato incompleto é descartado em vez de
     * derrubar o cadastro inteiro.
     *
     * A lista sai de crm_customers_contacts (caso do botão manual sobre a base
     * já cadastrada) e, quando não houver nenhum, do responsável financeiro do
     * `attributes` — que é o único contato que o wizard público coleta.
     *
     * @param  object $cliente linha da crm_customers_v
     * @return array
     */
    private function contatosParaBomControle($cliente)
    {
        $contatos = [];

        $linhas = $this->global_model->getWhere_off(
            'crm_customers_contacts',
            ['id_customer' => (int) $cliente->id, 'id_company' => (int) $cliente->id_company],
            FALSE
        );

        foreach ((array) $linhas as $linha) {
            $item = $this->contatoBomControle(
                $linha->name,
                $linha->email,
                $linha->phone,
                ((string) $linha->type === 'financeiro')
            );
            if ($item !== NULL) $contatos[] = $item;
        }

        if (!empty($contatos)) {
            return $contatos;
        }

        $attrs = json_decode((string) $cliente->attributes, TRUE);
        $billing = (is_array($attrs) && isset($attrs['billing']) && is_array($attrs['billing']))
            ? $attrs['billing']
            : [];

        $item = $this->contatoBomControle(
            isset($billing['name']) ? $billing['name'] : '',
            isset($billing['email']) ? $billing['email'] : '',
            isset($billing['whatsapp']) ? $billing['whatsapp'] : '',
            TRUE
        );

        return ($item === NULL) ? [] : [$item];
    }

    /**
     * @param  string $nome
     * @param  string $email
     * @param  string $telefone
     * @param  bool   $cobranca contato principal e de cobrança
     * @return array|null NULL quando falta algum dos três obrigatórios
     */
    private function contatoBomControle($nome, $email, $telefone, $cobranca)
    {
        $nome = trim((string) $nome);
        $email = trim((string) $email);
        $telefone = preg_replace('/\D/', '', (string) $telefone);

        if ($nome === '' || $email === '' || $telefone === '') {
            return NULL;
        }

        return [
            'Nome' => mb_substr($nome, 0, 150),
            'Email' => mb_strtolower($email),
            'Telefone' => $telefone,
            'Padrao' => (bool) $cobranca,
            'Cobranca' => (bool) $cobranca,
        ];
    }

    /**
     * Toda falha de integração vai para o log — é lá que o diagnóstico é feito
     * depois, com o contexto que permite reproduzir o caso.
     *
     * @param int    $idCompany
     * @param int    $idCustomer
     * @param string $documento
     * @param string $operacao
     * @param string $mensagem
     */
    private function logarErroCliente($idCompany, $idCustomer, $documento, $operacao, $mensagem)
    {
        log_message('error', sprintf(
            '[BOMCONTROLE] Sincronizacao de cliente falhou — empresa=%d cliente=%d documento=%s operacao=%s: %s',
            (int) $idCompany,
            (int) $idCustomer,
            (string) $documento,
            (string) $operacao,
            (string) $mensagem
        ));
    }

    /**
     * @param  bool        $success
     * @param  string      $message
     * @param  int|null    $idBc
     * @param  string      $acao
     * @param  string|null $synced
     * @return array
     */
    private function respostaSync($success, $message, $idBc, $acao, $synced = NULL)
    {
        return [
            'success' => (bool) $success,
            'message' => (string) $message,
            'data' => [
                'id_bc' => ($idBc === NULL) ? NULL : (int) $idBc,
                'acao' => (string) $acao,
                'synced' => $synced,
            ],
        ];
    }

    /**
     * Extrato Bom Controle do contrato: faturas em aberto (todas as vencidas +
     * a vencer em até DIAS_FUTURO dias) e pagas (últimos MESES_PAGAS meses),
     * ordenadas do vencimento mais recente para o mais antigo.
     *
     * @param  int $idContract
     * @param  int $idCompany
     * @return array success, message, data
     */
    public function montarExtratoContrato($idContract, $idCompany)
    {
        $contrato = $this->global_model->getWhere_off(
            'crm_contracts',
            ['id' => (int) $idContract, 'id_company' => (int) $idCompany],
            TRUE
        );
        if (empty($contrato)) {
            return ['success' => FALSE, 'message' => 'Contrato não encontrado.', 'data' => NULL];
        }

        if (empty($contrato->bomcontrole_contract_id)) {
            // Sem vínculo não é erro: é o estado que a aba mostra com o botão
            // de vincular.
            return ['success' => TRUE, 'message' => '', 'data' => ['vinculado' => FALSE]];
        }

        $config = $this->getConfig($idCompany);
        if (!$config['success']) {
            return ['success' => FALSE, 'message' => $config['message'], 'data' => NULL];
        }

        $sessao = sessao_suspender();
        try {
            $resultado = $this->consultarExtratoDoContrato($config['config'], (int) $contrato->bomcontrole_contract_id);
        } catch (Throwable $e) {
            $resultado = ['success' => FALSE, 'message' => 'Falha inesperada: ' . $e->getMessage()];
        } finally {
            sessao_retomar($sessao);
        }

        if (!$resultado['success']) {
            return ['success' => FALSE, 'message' => $resultado['message'], 'data' => NULL];
        }

        $itens = $this->consolidarItens($resultado['abertas'], $resultado['pagas']);

        return [
            'success' => TRUE,
            'message' => '',
            'data' => [
                'vinculado' => TRUE,
                'contrato_bc' => $resultado['contrato_bc'] + [
                    'vinculado_em' => !empty($contrato->bomcontrole_linked)
                        ? date('d/m/Y H:i', strtotime($contrato->bomcontrole_linked)) : NULL,
                ],
                'itens' => $itens,
                'aviso_pagas' => $resultado['aviso_pagas'],
            ],
        ];
    }

    /**
     * Extrato agregado do cliente: os mesmos dois GETs do extrato do contrato,
     * para CADA contrato vinculado, com cada item carimbado com o contrato de
     * origem. Falha em um contrato não esconde os demais — vira aviso.
     *
     * @param  int $idCustomer
     * @param  int $idCompany
     * @return array success, message, data
     */
    public function montarExtratoCliente($idCustomer, $idCompany)
    {
        $cliente = $this->global_model->getWhere_off(
            'crm_customers',
            ['id' => (int) $idCustomer, 'id_company' => (int) $idCompany],
            TRUE
        );
        if (empty($cliente)) {
            return ['success' => FALSE, 'message' => 'Cliente não encontrado.', 'data' => NULL];
        }

        $contratos = $this->global_model->getWhere_off(
            'crm_contracts',
            ['id_customer' => (int) $idCustomer, 'id_company' => (int) $idCompany]
        );
        $vinculados = array_values(array_filter((array) $contratos, function ($c) {
            return !empty($c->bomcontrole_contract_id);
        }));

        if (empty($vinculados)) {
            return ['success' => TRUE, 'message' => '', 'data' => ['vinculado' => FALSE]];
        }

        $config = $this->getConfig($idCompany);
        if (!$config['success']) {
            return ['success' => FALSE, 'message' => $config['message'], 'data' => NULL];
        }

        $itens = [];
        $avisos = [];
        $avisoPagas = FALSE;

        $sessao = sessao_suspender();
        try {
            foreach ($vinculados as $contrato) {
                $resultado = $this->consultarExtratoDoContrato($config['config'], (int) $contrato->bomcontrole_contract_id);
                if (!$resultado['success']) {
                    $avisos[] = 'Contrato #' . (int) $contrato->id . ': ' . $resultado['message'];
                    continue;
                }

                $avisoPagas = $avisoPagas || $resultado['aviso_pagas'];
                foreach ($this->consolidarItens($resultado['abertas'], $resultado['pagas']) as $item) {
                    $item['contrato'] = (int) $contrato->id;
                    $item['contrato_bc'] = (int) $contrato->bomcontrole_contract_id;
                    $itens[] = $item;
                }
            }
        } catch (Throwable $e) {
            return ['success' => FALSE, 'message' => 'Falha inesperada: ' . $e->getMessage(), 'data' => NULL];
        } finally {
            sessao_retomar($sessao);
        }

        if (empty($itens) && count($avisos) === count($vinculados)) {
            // Todos falharam: é erro, não extrato vazio.
            return ['success' => FALSE, 'message' => $avisos[0], 'data' => NULL];
        }

        $this->ordenarPorVencimento($itens);

        return [
            'success' => TRUE,
            'message' => '',
            'data' => [
                'vinculado' => TRUE,
                'contratos' => count($vinculados),
                'itens' => $itens,
                'avisos' => $avisos,
                'aviso_pagas' => $avisoPagas,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Miolo da consulta
    // ------------------------------------------------------------------

    /**
     * Os dois GETs que montam o extrato de um contrato BC. Roda DENTRO do
     * bloco de sessão suspensa de quem chama.
     *
     * @param  array $config
     * @param  int   $idBcContract
     * @return array success, message, contrato_bc, abertas, pagas, aviso_pagas
     */
    private function consultarExtratoDoContrato(array $config, $idBcContract)
    {
        // Janela ancorada no dia 1 antes de subtrair meses — strtotime('-13
        // months') a partir de um dia 31 escorrega de mês (mesma armadilha
        // tratada no dashboard).
        $inicioPagas = date('Y-m-01 00:00:00', strtotime('-' . self::MESES_PAGAS . ' months', strtotime(date('Y-m-01'))));
        $terminoAbertas = date('Y-m-d 23:59:59', strtotime('+' . self::DIAS_FUTURO . ' days'));

        // Em aberto: sem inicioPeriodo de propósito — vencida entra TODA, não
        // importa há quanto tempo.
        $abertas = $this->client()->obterContrato($config, $idBcContract, [
            'quitadas' => FALSE,
            'terminoPeriodo' => $terminoAbertas,
        ]);
        if (!$abertas['success']) {
            return ['success' => FALSE, 'message' => $abertas['message']];
        }

        $detalhe = is_array($abertas['data']) ? $abertas['data'] : [];
        $clienteBc = isset($detalhe['Cliente']) && is_array($detalhe['Cliente']) ? $detalhe['Cliente'] : [];

        $pagas = $this->buscarFaturasPagas($config, $idBcContract, $clienteBc, $inicioPagas);
        if (!$pagas['success']) {
            return ['success' => FALSE, 'message' => $pagas['message']];
        }

        $faturasAbertas = isset($detalhe['Faturas']) && is_array($detalhe['Faturas']) ? $detalhe['Faturas'] : [];

        return [
            'success' => TRUE,
            'message' => '',
            'contrato_bc' => [
                'id' => (int) $this->campo($detalhe, ['Id'], $idBcContract),
                'id_venda' => (int) $this->campo($detalhe, ['IdVenda'], 0),
                'encerrado' => (bool) $this->campo($detalhe, ['Encerrado'], FALSE),
                'valor' => (float) $this->campo($detalhe, ['Valor'], 0),
                'cliente_nome' => $this->nomeDoCliente($clienteBc),
            ],
            'abertas' => array_map([$this, 'normalizarFatura'], $faturasAbertas),
            'pagas' => $pagas['itens'],
            'aviso_pagas' => $pagas['aviso_pagas'],
        ];
    }

    /**
     * Faturas PAGAS do contrato — o ponto único que decide a fonte.
     *
     * Caminho principal: Obter com quitadas=true (por contrato). Fallback
     * (PAGAS_VIA_FINANCEIRO): Financeiro/Pesquisar por CLIENTE, que mistura os
     * contratos do cliente — daí o aviso_pagas que a UI mostra.
     *
     * @param  array  $config
     * @param  int    $idBcContract
     * @param  array  $clienteBc   objeto Cliente do Obter (para o Cliente.Id do fallback)
     * @param  string $inicioPagas 'Y-m-d H:i:s'
     * @return array success, message, itens, aviso_pagas
     */
    private function buscarFaturasPagas(array $config, $idBcContract, array $clienteBc, $inicioPagas)
    {
        if (!self::PAGAS_VIA_FINANCEIRO) {
            $quitadas = $this->client()->obterContrato($config, $idBcContract, [
                'quitadas' => TRUE,
                'inicioPeriodo' => $inicioPagas,
            ]);
            if (!$quitadas['success']) {
                return ['success' => FALSE, 'message' => $quitadas['message'], 'itens' => [], 'aviso_pagas' => FALSE];
            }

            $faturas = isset($quitadas['data']['Faturas']) && is_array($quitadas['data']['Faturas'])
                ? $quitadas['data']['Faturas'] : [];

            return [
                'success' => TRUE,
                'message' => '',
                'itens' => array_map([$this, 'normalizarFatura'], $faturas),
                'aviso_pagas' => FALSE,
            ];
        }

        $idClienteBc = (int) $this->campo($clienteBc, ['Id'], 0);
        if ($idClienteBc <= 0) {
            // Sem o id interno do cliente não há o que pesquisar — extrato só
            // com as abertas, sem inventar erro.
            return ['success' => TRUE, 'message' => '', 'itens' => [], 'aviso_pagas' => FALSE];
        }

        $dataTermino = date('Y-m-d 23:59:59');
        $itens = [];

        for ($pagina = 1; $pagina <= self::MAX_PAGINAS; $pagina++) {
            $lote = $this->client()->pesquisarFinanceiro($config, $idClienteBc, $inicioPagas, $dataTermino, 100, $pagina);
            if (!$lote['success']) {
                if ($pagina === 1) {
                    return ['success' => FALSE, 'message' => $lote['message'], 'itens' => [], 'aviso_pagas' => FALSE];
                }
                break; // parcial já coletado vale mais que nada
            }

            foreach ($lote['data']['Itens'] as $movimentacao) {
                if (!is_array($movimentacao)) continue;
                // Débito é despesa; sem DataQuitacao não está paga.
                if (!empty($movimentacao['Debito'])) continue;
                if (empty($movimentacao['DataQuitacao'])) continue;
                $itens[] = $this->normalizarParcelaPaga($movimentacao);
            }

            if (count($lote['data']['Itens']) < 100) break;
            if (count($itens) >= (int) $lote['data']['TotalItens']) break;
        }

        return ['success' => TRUE, 'message' => '', 'itens' => $itens, 'aviso_pagas' => TRUE];
    }

    /**
     * Pesquisa paginada por documento + refiltro exato. Roda DENTRO do bloco
     * de sessão suspensa de quem chama.
     *
     * @param  array  $config
     * @param  string $documento só dígitos
     * @return array success, message, candidatos
     */
    private function pesquisarPorDocumento(array $config, $documento)
    {
        $candidatos = [];
        $acumulado = 0;

        for ($pagina = 1; $pagina <= self::MAX_PAGINAS; $pagina++) {
            $lote = $this->client()->pesquisarContratos($config, $documento, 50, $pagina);
            if (!$lote['success']) {
                if ($pagina === 1) {
                    return ['success' => FALSE, 'message' => $lote['message'], 'candidatos' => []];
                }
                break;
            }

            $itens = $lote['data']['Itens'];
            $acumulado += count($itens);

            foreach ($itens as $item) {
                if (!is_array($item)) continue;

                $clienteBc = isset($item['Cliente']) && is_array($item['Cliente']) ? $item['Cliente'] : [];
                // O refiltro que a busca ampla da API torna obrigatório.
                if ($this->documentoDoCliente($clienteBc) !== $documento) continue;

                $candidatos[] = [
                    'id' => (int) $this->campo($item, ['Id'], 0),
                    'id_venda' => (int) $this->campo($item, ['IdVenda'], 0),
                    'inicio' => $this->dataIso($this->campo($item, ['Inicio'])),
                    'termino' => $this->dataIso($this->campo($item, ['Termino'])),
                    'valor' => (float) $this->campo($item, ['Valor'], 0),
                    'encerrado' => (bool) $this->campo($item, ['Encerrado'], FALSE),
                    'tipo' => (string) $this->campo($item, ['NomeTipoPagamento'], ''),
                    'observacao' => trim((string) $this->campo($item, ['Observacao'], '')),
                    'cliente_nome' => $this->nomeDoCliente($clienteBc),
                    'fatura_recente' => NULL,
                ];

                if (count($candidatos) >= self::MAX_CANDIDATOS) break 2;
            }

            if (count($itens) < 50) break;
            if ($acumulado >= (int) $lote['data']['TotalItens']) break;
        }

        return ['success' => TRUE, 'message' => '', 'candidatos' => $candidatos];
    }

    // ------------------------------------------------------------------
    // Normalização
    // ------------------------------------------------------------------

    /**
     * Fatura do VendaContrato/Obter → item do extrato (vocabulário do sistema).
     *
     * @param  array $f
     * @return array
     */
    private function normalizarFatura($f)
    {
        $f = is_array($f) ? $f : [];

        $vencimento = $this->dataIso($this->campo($f, ['DataVencimento']));
        $quitado = (bool) $this->campo($f, ['Quitado'], FALSE);

        $observacoes = array_filter([
            trim((string) $this->campo($f, ['ObservacaoNotaFiscal'], '')),
            trim((string) $this->campo($f, ['ObservacaoBoleto'], '')),
        ]);

        return $this->itemExtrato([
            'id_fatura' => (int) $this->campo($f, ['Id'], 0),
            'emissao' => $this->dataIso($this->campo($f, ['DataCompetencia'])),
            'vencimento' => $vencimento,
            'valor' => (float) $this->campo($f, ['Valor'], 0),
            'quitado' => $quitado,
            'data_pagamento' => $this->dataIso($this->campo($f, ['DataPagamento'])),
            'parcela' => is_numeric($this->campo($f, ['NumeroParcela'])) ? (int) $this->campo($f, ['NumeroParcela']) : NULL,
            'forma_pagamento' => trim((string) $this->campo($f, ['NomeFormaPagamento'], '')) ?: NULL,
            'nfse_emitida' => (bool) $this->campo($f, ['EmiteNotaFiscal'], FALSE),
            'link_nota_fiscal' => trim((string) $this->campo($f, ['LinkNotaFiscal'], '')) ?: NULL,
            'boleto_emitido' => (bool) $this->campo($f, ['EmiteBoleto'], FALSE),
            'link_boleto' => trim((string) $this->campo($f, ['LinkBoleto'], '')) ?: NULL,
            'observacao' => implode(' | ', $observacoes) ?: NULL,
        ]);
    }

    /**
     * Movimentação do Financeiro/Pesquisar (fallback) → item do extrato.
     * Nomes divergem do Obter: IdFatura, DataQuitacao, LinkBoletoBancario,
     * LinkNotaFiscalServico — e não há flags de emissão, então elas derivam da
     * presença do link.
     *
     * @param  array $m
     * @return array
     */
    private function normalizarParcelaPaga(array $m)
    {
        $linkBoleto = trim((string) $this->campo($m, ['LinkBoletoBancario'], '')) ?: NULL;
        $linkNota = trim((string) $this->campo($m, ['LinkNotaFiscalServico'], '')) ?: NULL;

        return $this->itemExtrato([
            'id_fatura' => (int) $this->campo($m, ['IdFatura'], 0),
            'emissao' => $this->dataIso($this->campo($m, ['DataCompetencia'])),
            'vencimento' => $this->dataIso($this->campo($m, ['DataVencimento'])),
            'valor' => (float) $this->campo($m, ['Valor'], 0),
            'quitado' => TRUE,
            'data_pagamento' => $this->dataIso($this->campo($m, ['DataQuitacao'])),
            'parcela' => is_numeric($this->campo($m, ['NumeroParcela'])) ? (int) $this->campo($m, ['NumeroParcela']) : NULL,
            'forma_pagamento' => trim((string) $this->campo($m, ['NomeFormaPagamento'], '')) ?: NULL,
            'nfse_emitida' => $linkNota !== NULL,
            'link_nota_fiscal' => $linkNota,
            'boleto_emitido' => $linkBoleto !== NULL,
            'link_boleto' => $linkBoleto,
            'observacao' => trim((string) $this->campo($m, ['Observacao'], '')) ?: NULL,
        ]);
    }

    /**
     * Completa o item com o status derivado — não existe enum de status na
     * API: tudo sai de Quitado + vencimento vs. hoje.
     *
     * @param  array $item
     * @return array
     */
    private function itemExtrato(array $item)
    {
        if ($item['quitado']) {
            $item['status'] = 'quitado';
            $item['status_rotulo'] = 'Quitado';
            $item['dias_vencido'] = 0;
            return $item;
        }

        $dias = 0;
        if ($item['vencimento'] !== NULL) {
            $vencimento = DateTime::createFromFormat('Y-m-d', $item['vencimento']);
            if ($vencimento !== FALSE) {
                $vencimento->setTime(0, 0, 0);
                $hoje = new DateTime('today');
                $dias = (int) $vencimento->diff($hoje)->format('%r%a');
            }
        }

        if ($dias > 0) {
            $item['status'] = 'vencido';
            $item['status_rotulo'] = 'Vencido';
            $item['dias_vencido'] = $dias;
        } else {
            $item['status'] = 'em_aberto';
            $item['status_rotulo'] = 'Em aberto';
            $item['dias_vencido'] = 0;
        }

        return $item;
    }

    /**
     * Junta abertas e pagas: dedup por id_fatura (> 0 — id zerado não pode
     * colidir), com a paga vencendo sobre a aberta, e ordena do vencimento
     * mais novo para o mais antigo.
     *
     * @param  array $abertas
     * @param  array $pagas
     * @return array
     */
    private function consolidarItens(array $abertas, array $pagas)
    {
        $porFatura = [];
        $semId = [];

        foreach (array_merge($abertas, $pagas) as $item) {
            if ($item['id_fatura'] > 0) {
                $porFatura[$item['id_fatura']] = $item; // a paga chega depois e vence
            } else {
                $semId[] = $item;
            }
        }

        $itens = array_merge(array_values($porFatura), $semId);
        $this->ordenarPorVencimento($itens);

        return $itens;
    }

    /**
     * @param  array $itens por referência
     * @return void
     */
    private function ordenarPorVencimento(array &$itens)
    {
        usort($itens, function ($a, $b) {
            // Sem vencimento vai para o fim; ISO ordena como string.
            return strcmp((string) $b['vencimento'], (string) $a['vencimento']);
        });
    }

    // ------------------------------------------------------------------
    // Apoio
    // ------------------------------------------------------------------

    /**
     * Contrato + documento do cliente + config da empresa — o preâmbulo comum
     * de buscarCandidatos e vincular.
     *
     * @param  int $idContract
     * @param  int $idCompany
     * @return array success, message, data, contrato?, documento?, config?
     */
    private function carregarContexto($idContract, $idCompany)
    {
        $contrato = $this->global_model->getWhere_off(
            'crm_contracts',
            ['id' => (int) $idContract, 'id_company' => (int) $idCompany],
            TRUE
        );
        if (empty($contrato)) {
            return ['success' => FALSE, 'message' => 'Contrato não encontrado.', 'data' => NULL];
        }

        $cliente = $this->global_model->getFieldsWhereSingle_off(
            'crm_customers',
            'id, document',
            ['id' => (int) $contrato->id_customer],
            TRUE
        );
        $documento = empty($cliente) ? '' : preg_replace('/\D/', '', (string) $cliente->document);
        if (!in_array(strlen($documento), [11, 14], TRUE)) {
            return [
                'success' => FALSE,
                'message' => 'O cliente deste contrato não tem CPF/CNPJ válido cadastrado — é o documento que localiza o contrato no Bom Controle.',
                'data' => NULL,
            ];
        }

        $config = $this->getConfig($idCompany);
        if (!$config['success']) {
            return ['success' => FALSE, 'message' => $config['message'], 'data' => NULL];
        }

        return [
            'success' => TRUE,
            'message' => '',
            'data' => NULL,
            'contrato' => $contrato,
            'documento' => $documento,
            'config' => $config['config'],
        ];
    }

    /**
     * @return Bom_controle
     */
    private function client()
    {
        $this->load->library('bom_controle');
        return $this->bom_controle;
    }

    /**
     * Documento do objeto Cliente da API, só dígitos. PF e PJ moram em
     * sub-objetos diferentes.
     *
     * @param  array $clienteBc
     * @return string
     */
    private function documentoDoCliente(array $clienteBc)
    {
        $pf = isset($clienteBc['PessoaFisica']) && is_array($clienteBc['PessoaFisica']) ? $clienteBc['PessoaFisica'] : [];
        $pj = isset($clienteBc['PessoaJuridica']) && is_array($clienteBc['PessoaJuridica']) ? $clienteBc['PessoaJuridica'] : [];

        $documento = $this->campo($pf, ['Documento']);
        if ($documento === NULL || $documento === '') {
            $documento = $this->campo($pj, ['Documento'], '');
        }

        return preg_replace('/\D/', '', (string) $documento);
    }

    /**
     * @param  array $clienteBc
     * @return string
     */
    private function nomeDoCliente(array $clienteBc)
    {
        $pf = isset($clienteBc['PessoaFisica']) && is_array($clienteBc['PessoaFisica']) ? $clienteBc['PessoaFisica'] : [];
        $pj = isset($clienteBc['PessoaJuridica']) && is_array($clienteBc['PessoaJuridica']) ? $clienteBc['PessoaJuridica'] : [];

        foreach ([
            $this->campo($pf, ['Nome']),
            $this->campo($pj, ['RazaoSocial']),
            $this->campo($pj, ['NomeFantasia']),
        ] as $nome) {
            $nome = trim((string) $nome);
            if ($nome !== '') return $nome;
        }

        return 'Não informado';
    }

    /**
     * Fatura com o vencimento mais recente — o valor dela conta a história
     * atual do contrato (o Valor da pesquisa é o inicial, sem reajustes).
     *
     * @param  array $faturas
     * @return array|null ['valor', 'vencimento']
     */
    private function faturaMaisRecente(array $faturas)
    {
        $melhor = NULL;
        $melhorData = '';

        foreach ($faturas as $fatura) {
            if (!is_array($fatura)) continue;

            $data = $this->dataIso($this->campo($fatura, ['DataVencimento']));
            if ($data === NULL) {
                $data = $this->dataIso($this->campo($fatura, ['DataCompetencia']));
            }
            if ($data === NULL) continue;

            if ($melhor === NULL || strcmp($data, $melhorData) > 0) {
                $melhor = ['valor' => (float) $this->campo($fatura, ['Valor'], 0), 'vencimento' => $data];
                $melhorData = $data;
            }
        }

        return $melhor;
    }

    /**
     * 'Y-m-d' a partir do que a API mandou, ou NULL. substr em vez de
     * strtotime cru: as datas vêm com hora e sem timezone, e o parse completo
     * poderia escorregar um dia conforme o fuso.
     *
     * @param  mixed $valor
     * @return string|null
     */
    private function dataIso($valor)
    {
        if (!is_string($valor) || strlen($valor) < 10) {
            return NULL;
        }

        $data = substr($valor, 0, 10);
        $objeto = DateTime::createFromFormat('Y-m-d', $data);
        if ($objeto === FALSE || $objeto->format('Y-m-d') !== $data) {
            return NULL;
        }

        // Descarta lixo tipo o '0001-01-01' que APIs .NET usam como "sem data".
        if ((int) $objeto->format('Y') < 1990) {
            return NULL;
        }

        return $data;
    }

    /**
     * Primeiro campo presente e não nulo — os dois endpoints usam nomes
     * diferentes para o mesmo dado.
     *
     * @param  array $linha
     * @param  array $chaves
     * @param  mixed $padrao
     * @return mixed
     */
    private function campo(array $linha, array $chaves, $padrao = NULL)
    {
        foreach ($chaves as $chave) {
            if (array_key_exists($chave, $linha) && $linha[$chave] !== NULL) {
                return $linha[$chave];
            }
        }
        return $padrao;
    }
}
