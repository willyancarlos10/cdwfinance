<?php

defined('BASEPATH') or exit('No direct script access allowed');

class MY_Controller extends CI_Controller
{
    /**
     * Tamanho máximo aceito no upload do editor WYSIWYG (10 MB).
     * Precisa acompanhar o imageMaxSize/fileMaxSize configurado em views/footer.php.
     */
    const EDITOR_UPLOAD_MAX_SIZE = 10485760;

    /**
     * Teto padrão por arquivo nos uploads de imagem (10 MB), aplicado no
     * SERVIDOR por uploadFileFtp()/uploadFilesFtp().
     *
     * O maxFilesize do Dropzone é só do navegador — um curl ou a página com o
     * JS editado passa direto. Este é o limite que vale de fato; manter os dois
     * alinhados para o usuário não ser barrado só depois do upload inteiro.
     *
     * Telas que aceitam mais passam o valor no 5º argumento (documentos: 50 MB,
     * documento de parceiro: 20 MB, arquivos de artigo: 15 MB).
     *
     * ATENÇÃO ao lote: com uploadMultiple o Dropzone manda parallelUploads
     * arquivos num POST só, então parallelUploads × limite precisa caber no
     * post_max_size (produção: 64M).
     */
    const UPLOAD_MAX_SIZE_PADRAO = 10485760;

    /**
     * Nível de buffer de saída no início do upload (ver startUploadOutputGuard).
     * @var int|null
     */
    protected $upload_output_level = NULL;

    /**
     * TRUE depois que sendUploadJson() já respondeu, para o shutdown handler
     * não escrever um segundo corpo.
     * @var bool
     */
    protected $upload_json_sent = FALSE;

    /**
     * Valor do diagnóstico de upload quando 'upload_diag' NÃO está definido no
     * config.php. Como config.php não é versionado, um ambiente novo (ou uma
     * cópia antiga em produção) precisa funcionar sem a chave.
     *
     * Para ligar/desligar sem deploy, defina no config.php:
     *   $config['upload_diag'] = TRUE;  // ou FALSE
     */
    const UPLOAD_DIAG_PADRAO = TRUE;

    /**
     * Cache do flag resolvido. Evita reler o config a cada linha e mantém o
     * valor disponível no shutdown handler, quando o objeto Config pode já não
     * estar utilizável.
     * @var bool|null
     */
    protected $upload_diag = NULL;

    /**
     * Etapas acumuladas no request. Só vão para o arquivo se algo falhar
     * (diagFlush); em caso de sucesso são descartadas (diagFinalizarSucesso).
     * Guardar em memória — em vez de gravar direto — é o que permite ter o
     * contexto completo da falha sem poluir o log com uploads que deram certo.
     * @var array
     */
    protected $diag_buffer = [];

    /**
     * TRUE depois que o buffer já foi gravado, para não duplicar quando o
     * shutdown handler roda em seguida.
     * @var bool
     */
    protected $diag_flushed = FALSE;

    /**
     * Identificador curto do request de upload. Repetido em todas as linhas de
     * log e devolvido ao navegador no header X-Upload-Log-Id, para casar a
     * tentativa que falhou na tela com a linha exata do log.
     * @var string|null
     */
    protected $upload_log_id = NULL;

    /**
     * microtime do início da guarda, para medir o tempo de cada etapa.
     * @var float
     */
    protected $upload_started_at = 0.0;

    public function __construct()
    {
        parent::__construct();

        // A sessão saiu do autoload para não ser criada em requisição stateless
        // (API Bearer, MCP, cron por URL, bot sem cookie) — cada uma dessas
        // gravava uma linha em crm_sessions e tomava/soltava um GET_LOCK à toa.
        // Aqui é o ponto que cobre todo o painel: os demais são Login e Ajuda.
        $this->load->library('session');

        if ($this->input->is_ajax_request()) {
            if ($this->session->userdata('logado') !== true) {
                // Sessão expirada durante o upload é uma das causas de "resposta
                // inválida": este ramo responde ANTES da guarda de saída, então
                // qualquer conteúdo já emitido sai colado no JSON. O log registra
                // de onde veio essa saída (arquivo:linha).
                $arquivoSaida = '';
                $linhaSaida = 0;
                $jaEnviou = headers_sent($arquivoSaida, $linhaSaida);
                $ehUpload = !empty($_FILES)
                    || stripos((string) (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ''), 'upload') !== FALSE;
                // Só registra quando a chamada é de upload: este ramo atende todo
                // AJAX do painel e encheria o log com sessões expiradas comuns.
                if ($ehUpload) {
                    $this->diagLog('sessao_expirada', [
                        'tela_origem' => $this->uploadScreenDiag(),
                        'requisicao' => $this->uploadRequestDiag(),
                        'php' => $this->uploadPhpDiag(),
                        'arquivos' => $this->uploadFilesDiag(),
                        'headers_ja_enviados' => $jaEnviou ? ($arquivoSaida . ':' . $linhaSaida) : 'nao',
                        'buffer_pendente' => ob_get_level() > 0 ? mb_substr((string) ob_get_contents(), 0, 500) : '',
                    ]);
                    // Upload interrompido por sessão expirada é falha: grava.
                    $this->diagFlush('sessão expirada durante o upload');
                }

                // Mantém HTTP 200 e a chave "redirect": dezenas de views do painel
                // testam data.redirect dentro do success: do jQuery — com 401 o
                // success não dispararia e a detecção de sessão expirada quebraria.
                if (!headers_sent()) {
                    header('Content-Type: application/json; charset=utf-8');
                    header('X-Upload-Log-Id: ' . $this->uploadLogId());
                }
                echo json_encode([
                    'redirect' => TRUE,
                    'success' => FALSE,
                    'message' => 'Sua sessão expirou. Faça login novamente.',
                    'login_url' => base_url('login?warning=Sua sessão expirou.'),
                    'data' => [],
                    'errors' => ['session' => 'expired'],
                ]);
                exit;
            }
        } else {
            $this->global_model->islogged();
            if ($this->db->table_exists('crm_general_settings')) {
                $this->load->model('general_settings_model');
                $this->general_settings_model->applyEmailConfig();
            }
            $idCompany = $this->getCurrentCompanyId();
            $loggedCompany = $this->session->userdata('company');
            $this->data['is_master_company'] = (!empty($loggedCompany->id) && (int) $loggedCompany->id === 1);

            // Contadores da Central de Notificações (sino do topo).
            //
            // Os contadores antigos (leads de formulário e currículos do banco
            // de talentos) saíram junto com os módulos do CMS. Hoje o que pende
            // são as faturas vencidas do tenant — cada rotina nova do financeiro
            // que precise notificar soma aqui e acrescenta o próprio item em
            // views/menu.php.
            $this->load->model('invoice_model');
            $this->data['pending_header'] = $this->invoice_model->countOverdue($idCompany);

            // Monitoramento: eventos ainda não vistos, sem contar os de domínio
            // silenciado e sem os informativos (título de home muda sozinho e
            // encheria o sino todo dia). Query direta com bind, e não pelo
            // Global_model, para o contador não herdar o filtro da sessão.
            $eventosMonitor = $this->db->query(
                'SELECT COUNT(*) AS `total`
                   FROM `crm_domains_monitor_events` `e`
                   LEFT JOIN `crm_domains_monitor` `m` ON `m`.`id` = `e`.`id_monitor`
                  WHERE `e`.`id_company` = ? AND `e`.`acknowledged` = 0
                    AND `e`.`severity` <> ? AND COALESCE(`m`.`muted`, 0) = 0',
                [$idCompany, 'info']
            )->row();

            $this->data['monitor_header'] = empty($eventosMonitor->total) ? 0 : (int) $eventosMonitor->total;
            $this->data['total_tasks'] = $this->data['pending_header'] + $this->data['monitor_header'];

            // Último acesso da empresa selecionada no filtro (exibido de forma discreta na sidebar).
            // Só é consultado/mostrado para a empresa master (id 1).
            $this->data['company_last_login'] = NULL;
            if ($this->data['is_master_company']) {
                $selectedCompany = $this->global_model->getFieldsWhereSingle_off('crm_companies', ['last_login'], ['id' => $idCompany], TRUE);
                if (!empty($selectedCompany) && !empty($selectedCompany->last_login)) {
                    $this->data['company_last_login'] = $selectedCompany->last_login;
                }
            }

            // Editor de texto rico carregado em views/footer.php.
            //
            // Era escolhido por empresa em crm_companies_sites.editor, campo que
            // só existia na aba Site do cadastro de empresas. Sem essa tela o
            // valor não tem como ser alterado, então o painel usa o padrão.
            $this->data['editor_choice'] = 'tinymce';
        }
    }

    protected function getCurrentCompanyId()
    {
        $filterCompany = $this->session->userdata('f_company');
        if (!empty($filterCompany['id_company'])) {
            return (int) $filterCompany['id_company'];
        }

        $company = $this->session->userdata('company');
        if (!empty($company->id)) {
            return (int) $company->id;
        }

        $user = $this->session->userdata('user');
        return (int) $user->id_company;
    }

    /**
     * Filtra categorias para o formulário de cadastro/edição de um registro:
     * mantém apenas as ativas (id_status = 1), preservando as já vinculadas
     * ao registro em edição, mesmo que estejam inativas (id_status = 2).
     *
     * Não afeta filtros de listagem nem o gerenciador de categorias.
     *
     * @param array $categories Objetos de categoria (precisam ter ->id e ->id_status)
     * @param int[] $keep_ids   IDs a manter mesmo inativos (categorias já selecionadas)
     * @return array
     */
    protected function filterFormCategories($categories, $keep_ids = [])
    {
        $categories = is_array($categories) ? $categories : [];
        $keep_ids = array_map('intval', is_array($keep_ids) ? $keep_ids : [$keep_ids]);

        return array_values(array_filter($categories, function ($category) use ($keep_ids) {
            return (int) $category->id_status === 1 || in_array((int) $category->id, $keep_ids, TRUE);
        }));
    }

    protected function renovar_sessao()
    {
        $crm_users = $this->global_model->getWhere_off('crm_users', ['id' => $this->session->userdata('user')->id], TRUE);
        $crm_companies = $this->global_model->getWhere_off('crm_companies', ['id' => $crm_users->id_company], TRUE);
        $permissions = $this->global_model->getWhere_off('crm_user_permissions', array('id' => $crm_users->id_permission, 'id_status' => 1, 'id_company' => $crm_users->id_company), TRUE);

        // Buscar grupo de empresa
        $group = $this->global_model->getWhere_off('crm_user_groups', ['id_status' => 1, 'id' => $crm_users->id_group], TRUE);
        if (!empty($group)) {
            $unt = json_decode($group->companies);
            if (empty($unt)) {
                # Todas as empresas
                $units = "ALL";
            } else {
                $units = "0,";
                foreach ($unt as $c) $units .= $c . ',';
                $units .= $crm_companies->id; # Empresa do usuário
                $units = "($units)";
            }
        } else $units = "(" . $crm_companies->id . ")";

        // Gravar endereço da foto do usuário e o primeiro nome
        $image = base_url() . 'theme/custom/images/semfoto.jpg';
        if (!empty($crm_users->image)) $image = base_url() . $crm_users->image;
        $image = base_url('images/image.php?src=' . $image . '&w=280&h=280&zc=1&cc=FFFFFF&q=70');

        $name = explode(' ', $crm_users->name);
        $name = $name[0];

        // Gravar data e nome da empresa no header
        $byname = explode(' ', $crm_companies->byname);
        $byname = $byname[0];
        header('Content-type: text/html; charset=utf-8');
        setlocale(LC_ALL, 'pt_BR.utf-8', 'pt_BR', 'Portuguese_Brazil');
        $byname = strftime('%d de %B de %Y', strtotime('today')) . " - " . $byname;

        $preferences = json_decode($crm_users->preferences);
        if (empty($preferences)) (object) [];

        // Gravar objetos na seção
        $this->session->set_userdata('logado', TRUE);
        $this->session->set_userdata('user_first_name', $name);
        $this->session->set_userdata('user_image', $image);
        $this->session->set_userdata('company', $crm_companies);
        $this->session->set_userdata('user', $crm_users);
        $this->session->set_userdata('user_preferences', $preferences);
        $this->session->set_userdata('permissions', json_decode($permissions->permissions));
        $this->session->set_userdata('relateds_units', $units);
    }

    protected function setOptionSelect2($id_company)
    {
        $result = $this->global_model->getFieldsWhereSingle_off('crm_companies', ['id', 'byname', 'name', 'cnpj'], ['id' => $id_company], TRUE);
        if (!empty($result)) {
            return '<option value="' . $result->id . '">' . $result->byname . ' </option>';
        } else return "";
    }

    public function json_getcompanies()
    {
        header('Content-Type: application/x-json; charset=utf-8');

        $search = trim((string) $this->input->get("search"));
        if ($search === '') {
            return;
        }

        // Escapa curingas e aspas do termo antes de montar o LIKE (evita SQL injection).
        $like = $this->db->escape_like_str(mb_strtoupper($search));
        $where = '(upper(name) LIKE "%' . $like . '%" ESCAPE \'!\''
            . ' OR upper(byname) LIKE "%' . $like . '%" ESCAPE \'!\''
            . ' OR upper(cnpj) LIKE "%' . $like . '%" ESCAPE \'!\')';

        // relateds_units é montado no servidor no formato "(1,2,3)"; valida antes de concatenar.
        $units = (string) $this->session->userdata('relateds_units');
        if ($units !== 'ALL' && preg_match('/^\([0-9]+(,[0-9]+)*\)$/', $units)) {
            $where .= ' AND id IN ' . $units;
        }

        $json = $this->global_model->getFieldsWhereOrderByLimit('crm_companies', "id, byname as text", $where, 'byname', 'asc', 10, FALSE);
        echo json_encode($json);
    }

    public function json_posttoggle_status()
    {
        header('Content-Type: application/json; charset=utf-8');

        // Lista branca de tabelas que aceitam alternar status por este endpoint
        // genérico. Toda tabela nova do financeiro precisa ser incluída aqui —
        // fora da lista, a requisição é recusada como "Dados inválidos".
        $allowedTables = [
            'crm_user_permissions',
            'crm_user_groups',
            'crm_servers',
            'crm_service_types',
            'crm_end_reasons',
        ];

        $table = $this->input->post('table');
        $id = (int) $this->input->post('id');
        $ativar = $this->input->post('ativar') === '1';

        if (!in_array($table, $allowedTables) || $id <= 0) {
            echo json_encode([
                'success' => FALSE,
                'return' => FALSE,
                'message' => 'Dados inválidos.',
                'data' => NULL,
                'errors' => ['status' => 'Dados inválidos.']
            ]);
            return;
        }

        $record = $this->global_model->getWhere_off($table, ['id' => $id], TRUE);
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

        // Escopo por empresa: impede alternar status de registros de outra empresa (IDOR).
        // A empresa master (id 1) administra todas; demais só a empresa selecionada.
        $loggedCompany = $this->session->userdata('company');
        $isMaster = (!empty($loggedCompany->id) && (int) $loggedCompany->id === 1);
        if (!$isMaster && isset($record->id_company) && (int) $record->id_company !== (int) $this->getCurrentCompanyId()) {
            $this->output->set_status_header(403);
            echo json_encode([
                'success' => FALSE,
                'return' => FALSE,
                'message' => 'Você não tem permissão para alterar este registro.',
                'data' => NULL,
                'errors' => ['id_company' => 'Registro pertence a outra empresa.']
            ]);
            return;
        }

        $idStatus = $ativar ? 1 : 2;
        $update = [
            'id_status' => $idStatus,
            'modified' => date("Y-m-d H:i:s"),
            'modified_by' => $this->session->userdata('user')->id
        ];
        $this->global_model->edit($table, $update, 'id', $id);

        $crmStatus = $this->global_model->getWhere_off('crm_status', ['id' => $idStatus], TRUE);
        $statusName = (!empty($crmStatus) && !empty($crmStatus->name)) ? $crmStatus->name : ($ativar ? 'Ativo' : 'Inativo');
        $statusColor = (!empty($crmStatus) && !empty($crmStatus->color)) ? $crmStatus->color : ($ativar ? 'success' : 'danger');
        $message = $ativar ? 'Registro ativado com sucesso.' : 'Registro inativado com sucesso.';

        echo json_encode([
            'success' => TRUE,
            'return' => TRUE,
            'message' => $message,
            'data' => [
                'id' => $id,
                'id_status' => $idStatus,
                'status_name' => $statusName,
                'status_color' => $statusColor
            ],
            'errors' => []
        ]);
    }

    protected function travarPortalIntegracao($portal, $id_company, $message = NULL)
    {
        if (empty($message)) $message = "Não foi possível conectar-se ao portal utilizando as credenciais fornecidas, verifique e tente novamente.";
        else $message = "Não foi possível conectar-se ao portal utilizando as credenciais fornecidas: " . $message;

        $crm_portals = $this->global_model->getWhere_off('crm_portals', ['alias' => $portal], TRUE);
        $crm_companies_portals = $this->global_model->getWhere_off('crm_companies_portals', ['id_portal' => $crm_portals->id, 'id_company' => $id_company], TRUE);
        $array = [
            'id_status' => 3,
            'error_message' => $message,
            'modified' => date("Y-m-d H:i:s"),
            'modified_by' => $this->session->userdata('user')->id
        ];
        $this->global_model->edit('crm_companies_portals', $array, 'id', $crm_companies_portals->id);

        // Disparar email para o Will
        $crm_companies = $this->global_model->getWhere_off('crm_companies', ['id' => $id_company], TRUE);
        $this->data['company'] = $crm_companies;
        $this->data['portal'] = $crm_portals;
        $this->data['messageError'] = $message;
        $this->data['user'] = $this->global_model->getWhere_off('crm_users', ['id' => $this->session->userdata('user')->id], TRUE);
        $this->data['title'] = "Integração com portal $crm_portals->name desativado automático";
        $body = $this->global_model->body_email("emails/portalmodify", $this->data);
        $this->global_model->send_email($this->data['title'], $body, "willyan@metisagenciadigital.com.br", NULL, NULL, NULL);

        return TRUE;
    }

    /**
     * Upload de arquivos na pasta local do servidor
     * @param $fieldName /nome do campo
     * @param string $dir /diretório
     * @param string[] $allowed /array simples com as extensões permitidas
     * @return false|string //null caso de erro ou json codificado em caso de sucesso
     */
    public function uploadFileLocal($fieldName, $dir = 'test', $allowed = array('jpg', 'jpeg', 'png', 'bmp'))
    {
        $path = $dir . '/' . date('Y') . '/' . date('m');
        $file = $this->image_model->x_do_upload_all($fieldName, $path, $allowed, TRUE);
        if (!empty($file)) return $file;
        else return NULL;
    }

    /**
     * Abre um buffer para capturar warnings/notices do PHP (FTP, GD, unlink)
     * gerados durante o upload. Sem isso eles vazam para o corpo da resposta e o
     * cliente (Dropzone, Froala, TinyMCE) recebe um JSON inválido.
     *
     * Também registra um shutdown handler: em erro fatal (ex.: memória estourada
     * pelo GD) o PHP daria flush no buffer e o corpo sairia como HTML.
     */
    protected function startUploadOutputGuard()
    {
        $this->upload_output_level = ob_get_level();
        $this->upload_json_sent = FALSE;
        $this->upload_started_at = microtime(TRUE);

        // Devolve o id ao navegador: quando o cliente não consegue interpretar a
        // resposta, ele reenvia esse id em editor/upload-diag e o log fecha o ciclo.
        if (!headers_sent()) {
            header('X-Upload-Log-Id: ' . $this->uploadLogId());
        }

        $this->diagLog('inicio', [
            'tela_origem' => $this->uploadScreenDiag(),
            'requisicao' => $this->uploadRequestDiag(),
            'sessao' => $this->uploadSessionDiag(),
            'php' => $this->uploadPhpDiag(),
            'arquivos' => $this->uploadFilesDiag(),
            'post_chaves' => array_keys($_POST),
            'get_chaves' => array_keys($_GET),
        ]);

        ob_start();

        // Closure em vez de [$this, 'metodo']: um método público em MY_Controller
        // viraria uma ação roteável em todos os controllers do painel.
        register_shutdown_function(function () {
            $this->uploadFatalShutdown();
        });
    }

    /**
     * Resolve (uma única vez) o modo do diagnóstico, a partir do config.php.
     *
     * config->item() devolve NULL quando a chave não existe, o que permite
     * distinguir "não configurado" (usa o padrão) de "$config['upload_diag'] =
     * FALSE" (desligado de propósito) — os dois seriam FALSE num teste ingênuo.
     */
    private function resolverDiagConfig()
    {
        if ($this->upload_diag !== NULL) {
            return;
        }

        $this->upload_diag = self::UPLOAD_DIAG_PADRAO;

        if (isset($this->config)) {
            $valor = $this->config->item('upload_diag');
            if ($valor !== NULL) {
                $this->upload_diag = (is_string($valor) && strtolower($valor) === 'sempre')
                    ? 'sempre'
                    : (bool) $valor;
            }
        }
    }

    /**
     * O diagnóstico está ligado? (TRUE = só falhas, 'sempre' = tudo)
     * @return bool
     */
    protected function uploadDiagAtivo()
    {
        $this->resolverDiagConfig();
        return $this->upload_diag !== FALSE;
    }

    /**
     * Modo verboso: grava também os requests que terminaram bem. Serve para
     * conferir que a instrumentação está funcionando; no dia a dia fica
     * desligado, senão todo upload bem-sucedido vira ruído no log.
     * @return bool
     */
    protected function diagLogarSempre()
    {
        $this->resolverDiagConfig();
        return $this->upload_diag === 'sempre';
    }

    /**
     * Grava no log tudo o que foi acumulado no request, precedido de uma linha
     * de cabeçalho com o motivo — é por ela que se acha a ocorrência no arquivo.
     *
     * @param string $motivo o que aconteceu (aparece na linha de cabeçalho)
     * @param string $rotulo FALHA (padrão) ou OK, no modo 'sempre'
     */
    protected function diagFlush($motivo, $rotulo = 'FALHA')
    {
        if ($this->diag_flushed || empty($this->diag_buffer)) {
            return;
        }
        $this->diag_flushed = TRUE;

        // log_message() pode emitir warning (pasta de logs sem escrita); como
        // aqui os buffers do upload já podem estar fechados, isso cairia direto
        // no corpo da resposta.
        ob_start();
        $prefixo = $this->diag_buffer[0]['prefixo'];
        log_message('error', sprintf(
            '[%s %s] %s: %s (%d etapas a seguir)',
            $prefixo,
            $this->uploadLogId(),
            $rotulo,
            $motivo,
            count($this->diag_buffer)
        ));
        foreach ($this->diag_buffer as $item) {
            log_message('error', '[' . $item['prefixo'] . ' ' . $this->uploadLogId() . '] ' . $item['linha']);
        }
        ob_end_clean();

        $this->diag_buffer = [];
    }

    /**
     * Encerra o diagnóstico de um request que deu certo: descarta o que foi
     * acumulado, sem gravar nada. É o que mantém o log limpo — só falha aparece.
     *
     * @param string $contexto rótulo usado apenas no modo 'sempre'
     */
    protected function diagFinalizarSucesso($contexto = 'request concluído sem erro')
    {
        if ($this->diagLogarSempre()) {
            $this->diagFlush($contexto, 'OK');
            return;
        }

        $this->diag_buffer = [];
    }

    /**
     * Id curto e único do request de upload (usado no log e no header).
     * @return string
     */
    protected function uploadLogId()
    {
        if ($this->upload_log_id === NULL) {
            try {
                $this->upload_log_id = strtoupper(bin2hex(random_bytes(4)));
            } catch (Throwable $e) {
                $this->upload_log_id = strtoupper(substr(md5(uniqid('', TRUE)), 0, 8));
            }
        }

        return $this->upload_log_id;
    }

    /**
     * Acumula uma etapa do diagnóstico. NÃO grava no log: a gravação só ocorre
     * em diagFlush(), chamado quando o request termina mal. Cada etapa vira um
     * JSON de uma só linha, prefixado por [<prefixo> <id>] na hora de gravar.
     *
     * A montagem acontece dentro de um buffer próprio: qualquer warning aqui
     * cairia no corpo da resposta e quebraria justamente o JSON que estamos
     * investigando.
     *
     * @param string $etapa   rótulo da etapa (inicio, validacao, ftp, resposta...)
     * @param array  $extra   dados específicos da etapa
     * @param string $prefixo UPLOAD-DIAG (upload) ou ARTIGO-DIAG (salvar artigo)
     */
    protected function diagLog($etapa, array $extra = [], $prefixo = 'UPLOAD-DIAG')
    {
        if (!$this->uploadDiagAtivo()) {
            return;
        }

        // Medido antes do ob_start() abaixo, senão o próprio buffer do log
        // apareceria como um nível a mais.
        $nivelBuffer = ob_get_level();

        ob_start();
        try {
            $contexto = array_merge([
                'etapa' => $etapa,
                'rota' => $this->uploadRouteName(),
                'ms' => $this->upload_started_at > 0
                    ? (int) round((microtime(TRUE) - $this->upload_started_at) * 1000)
                    : 0,
                'ob_level' => $nivelBuffer,
                'mem_mb' => round(memory_get_usage(TRUE) / 1048576, 1),
                'mem_pico_mb' => round(memory_get_peak_usage(TRUE) / 1048576, 1),
            ], $extra);

            $linha = json_encode(
                $contexto,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
            );
            if ($linha === FALSE) {
                $linha = str_replace("\n", ' ', print_r($contexto, TRUE));
            }

            $this->diag_buffer[] = ['prefixo' => $prefixo, 'linha' => $linha];
        } catch (Throwable $e) {
            $this->diag_buffer[] = [
                'prefixo' => $prefixo,
                'linha' => 'falha ao montar o diagnóstico da etapa "' . $etapa . '": ' . $e->getMessage(),
            ];
        }
        ob_end_clean();
    }

    /**
     * Nome da rota atual, tolerante a estados em que o router não está pronto
     * (ex.: durante o shutdown de um erro fatal).
     * @return string
     */
    private function uploadRouteName()
    {
        // isset() em vez de só try/catch: property inexistente gera Notice, que
        // não é capturável por catch e vazaria para o corpo da resposta.
        if (!isset($this->router)) {
            return 'desconhecida';
        }

        try {
            return $this->router->fetch_class() . '/' . $this->router->fetch_method();
        } catch (Throwable $e) {
            return 'desconhecida';
        }
    }

    /**
     * Dados da requisição HTTP. Ajudam a separar upload do editor (editor/upload)
     * de upload do formulário (artigos/upload_imagem) e a detectar corpo truncado
     * pelo post_max_size (content_length grande com $_FILES vazio).
     * @return array
     */
    private function uploadRequestDiag()
    {
        $servidor = function ($chave, $padrao = '') {
            return isset($_SERVER[$chave]) ? (string) $_SERVER[$chave] : $padrao;
        };

        return [
            'uri' => $servidor('REQUEST_URI'),
            'metodo' => $servidor('REQUEST_METHOD'),
            'host' => $servidor('HTTP_HOST'),
            'https' => $servidor('HTTPS', 'off'),
            'ip' => $servidor('HTTP_X_FORWARDED_FOR') !== ''
                ? $servidor('HTTP_X_FORWARDED_FOR')
                : $servidor('REMOTE_ADDR'),
            'ajax' => $servidor('HTTP_X_REQUESTED_WITH', '(ausente)'),
            'content_type' => $servidor('CONTENT_TYPE'),
            'content_length' => (int) $servidor('CONTENT_LENGTH', '0'),
            'origin' => $servidor('HTTP_ORIGIN'),
            'referer' => $servidor('HTTP_REFERER'),
            'accept' => mb_substr($servidor('HTTP_ACCEPT'), 0, 120),
            'accept_encoding' => $servidor('HTTP_ACCEPT_ENCODING'),
            'user_agent' => mb_substr($servidor('HTTP_USER_AGENT'), 0, 200),
        ];
    }

    /**
     * Diagnóstico do envio de um formulário do painel (não-AJAX), com foco no
     * que trunca POST em silêncio: post_max_size e max_input_vars.
     *
     * O formulário de artigo é o caso crítico — descrição HTML grande + muitas
     * categorias/empresas/stats/MVV. Estourado o limite, o PHP entrega um $_POST
     * incompleto e o salvamento "funciona" perdendo dados, sem erro nenhum.
     *
     * @return array
     */
    protected function formPostDiag()
    {
        $contagem = 0;
        $contar = function ($valor) use (&$contar, &$contagem) {
            if (!is_array($valor)) {
                $contagem++;
                return;
            }
            foreach ($valor as $item) {
                $contar($item);
            }
        };
        $contar($_POST);

        $limite = (int) ini_get('max_input_vars');

        return [
            'content_length' => (int) (isset($_SERVER['CONTENT_LENGTH']) ? $_SERVER['CONTENT_LENGTH'] : 0),
            'post_max_size' => ini_get('post_max_size'),
            'post_vazio' => empty($_POST) ? '1' : '0',
            'post_chaves' => array_keys($_POST),
            'campos_recebidos' => $contagem,
            'max_input_vars' => (string) $limite,
            // Igualar o limite é o sintoma clássico de truncamento silencioso.
            'possivel_truncamento' => ($limite > 0 && $contagem >= $limite) ? 'SIM' : 'nao',
        ];
    }

    /**
     * Tela de onde o upload partiu, deduzida do Referer. Como novo artigo e
     * edição compartilham a mesma view e os mesmos endpoints, sem isso o log
     * não distingue "falha só ao editar" de "falha em qualquer artigo".
     *
     * Feito no servidor de propósito: vale para os três clientes (Froala,
     * TinyMCE e Dropzone) sem precisar alterar nenhum deles.
     *
     * @return array
     */
    private function uploadScreenDiag()
    {
        $referer = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
        $caminho = (string) parse_url($referer, PHP_URL_PATH);

        $diag = [
            'tela' => 'desconhecida',
            'id_artigo' => 0,
            'referer_path' => $caminho,
        ];

        // Categorias antes de artigos: as rotas de categoria também começam com /artigos/.
        if (preg_match('#/artigos/categorias/editar/(\d+)#', $caminho, $m)) {
            $diag['tela'] = 'edicao_categoria';
            $diag['id_artigo'] = (int) $m[1];
        } elseif (strpos($caminho, '/artigos/categorias/novo') !== FALSE) {
            $diag['tela'] = 'nova_categoria';
        } elseif (preg_match('#/artigos/editar/(\d+)#', $caminho, $m)) {
            $diag['tela'] = 'edicao_artigo';
            $diag['id_artigo'] = (int) $m[1];
        } elseif (strpos($caminho, '/artigos/novo') !== FALSE) {
            $diag['tela'] = 'novo_artigo';
        } elseif ($caminho !== '') {
            $diag['tela'] = 'outra: ' . $caminho;
        }

        return $diag;
    }

    /**
     * Estado da sessão e da empresa ativa: identifica o usuário que reproduziu o
     * erro e qual hospedagem/editor estava em uso.
     * @return array
     */
    private function uploadSessionDiag()
    {
        $diag = [
            'logado' => '(indisponivel)',
            'id_usuario' => 0,
            'usuario' => '',
            'id_empresa' => 0,
            'empresa' => '',
            'editor' => '',
            'session_id' => '',
        ];

        if (!isset($this->session)) {
            return $diag;
        }

        try {
            $diag['logado'] = $this->session->userdata('logado') === TRUE ? '1' : '0';
            $diag['session_id'] = mb_substr((string) session_id(), 0, 12);

            $usuario = $this->session->userdata('user');
            if (!empty($usuario)) {
                $diag['id_usuario'] = isset($usuario->id) ? (int) $usuario->id : 0;
                $diag['usuario'] = isset($usuario->email) ? $usuario->email : (isset($usuario->name) ? $usuario->name : '');
            }

            $diag['id_empresa'] = (int) $this->getCurrentCompanyId();

            $empresa = $this->session->userdata('company');
            if (!empty($empresa->name)) {
                $diag['empresa'] = $empresa->name;
            }

            // O editor define qual cliente JS está tratando a resposta (Froala x TinyMCE).
            $diag['editor'] = !empty($this->data['editor_choice']) ? $this->data['editor_choice'] : 'tinymce';
        } catch (Throwable $e) {
            $diag['erro'] = $e->getMessage();
        }

        return $diag;
    }

    /**
     * Limites e configurações do PHP que costumam derrubar upload só em produção
     * (post_max_size menor que o arquivo, memory_limit, display_errors ligado
     * jogando warning no corpo, compressão de saída...).
     * @return array
     */
    private function uploadPhpDiag()
    {
        $tmp = ini_get('upload_tmp_dir');
        $tmp = $tmp !== '' && $tmp !== FALSE ? $tmp : sys_get_temp_dir();

        return [
            'versao' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'ambiente' => defined('ENVIRONMENT') ? ENVIRONMENT : '(indefinido)',
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'memory_limit' => ini_get('memory_limit'),
            'max_file_uploads' => ini_get('max_file_uploads'),
            // max_input_vars trunca $_POST SEM aviso quando o formulário tem
            // muitos campos — artigo com muitas categorias/empresas/stats/MVV
            // passa fácil do padrão (1000) e perde dados só na edição.
            'max_input_vars' => ini_get('max_input_vars'),
            'max_execution_time' => ini_get('max_execution_time'),
            'max_input_time' => ini_get('max_input_time'),
            'file_uploads' => ini_get('file_uploads'),
            'upload_tmp_dir' => $tmp,
            'upload_tmp_gravavel' => is_writable($tmp) ? '1' : '0',
            'display_errors' => (string) ini_get('display_errors'),
            'error_reporting' => (string) ini_get('error_reporting'),
            'output_buffering' => (string) ini_get('output_buffering'),
            'zlib_compression' => (string) ini_get('zlib.output_compression'),
            'images_gravavel' => is_writable(FCPATH . 'images') ? '1' : '0',
            'logs_gravavel' => is_writable(APPPATH . 'logs') ? '1' : '0',
        ];
    }

    /**
     * Retrato de $_FILES: nome, tamanho, código de erro traduzido, existência do
     * arquivo temporário, mime real e dimensões. Um $_FILES vazio com
     * content_length alto = corpo descartado pelo servidor antes do PHP.
     * @return array
     */
    private function uploadFilesDiag()
    {
        if (empty($_FILES)) {
            return ['(nenhum arquivo recebido em $_FILES)'];
        }

        $mapa = [];
        foreach ($_FILES as $campo => $dados) {
            if (!isset($dados['name'])) {
                continue;
            }

            $nomes = is_array($dados['name']) ? $dados['name'] : [$dados['name']];
            foreach ($nomes as $i => $nome) {
                $pegar = function ($chave) use ($dados, $i) {
                    if (!isset($dados[$chave])) {
                        return '';
                    }
                    return is_array($dados[$chave])
                        ? (isset($dados[$chave][$i]) ? $dados[$chave][$i] : '')
                        : $dados[$chave];
                };

                $tmp = (string) $pegar('tmp_name');
                $erro = (int) $pegar('error');

                $item = [
                    'campo' => $campo,
                    'indice' => $i,
                    'nome' => $nome,
                    'extensao' => mb_strtolower((string) pathinfo($nome, PATHINFO_EXTENSION)),
                    'tamanho' => (int) $pegar('size'),
                    'mime_informado' => (string) $pegar('type'),
                    'erro' => $erro,
                    'erro_texto' => $this->uploadErrorText($erro),
                    'tmp_existe' => ($tmp !== '' && is_uploaded_file($tmp)) ? '1' : '0',
                    'tmp_tamanho' => ($tmp !== '' && file_exists($tmp)) ? (int) filesize($tmp) : 0,
                ];

                if ($item['tmp_existe'] === '1') {
                    $info = @getimagesize($tmp);
                    $item['imagem'] = $info !== FALSE
                        ? ($info[0] . 'x' . $info[1] . ' imagetype=' . $info[2])
                        : 'conteudo nao reconhecido como imagem';

                    if (function_exists('finfo_open')) {
                        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
                        if ($finfo !== FALSE) {
                            $item['mime_real'] = (string) @finfo_file($finfo, $tmp);
                            @finfo_close($finfo);
                        }
                    }
                }

                $mapa[] = $item;
            }
        }

        return $mapa;
    }

    /**
     * Headers que o PHP vai enviar na resposta — revela Content-Type duplicado,
     * Content-Encoding inesperado ou header injetado por módulo do servidor.
     * O Set-Cookie é redigido: o log não pode guardar o id de sessão completo.
     * @return string[]
     */
    private function uploadResponseHeadersDiag()
    {
        $lista = [];
        foreach (headers_list() as $header) {
            if (stripos($header, 'Set-Cookie:') === 0) {
                $lista[] = 'Set-Cookie: (redigido)';
                continue;
            }
            $lista[] = $header;
        }

        return $lista;
    }

    /**
     * Traduz o código de erro de $_FILES para texto legível no log.
     * @param int $codigo
     * @return string
     */
    private function uploadErrorText($codigo)
    {
        $mapa = [
            UPLOAD_ERR_OK => 'OK',
            UPLOAD_ERR_INI_SIZE => 'excede upload_max_filesize do php.ini',
            UPLOAD_ERR_FORM_SIZE => 'excede MAX_FILE_SIZE do formulário',
            UPLOAD_ERR_PARTIAL => 'upload interrompido (arquivo parcial)',
            UPLOAD_ERR_NO_FILE => 'nenhum arquivo enviado',
            UPLOAD_ERR_NO_TMP_DIR => 'pasta temporária ausente',
            UPLOAD_ERR_CANT_WRITE => 'falha ao gravar no disco',
            UPLOAD_ERR_EXTENSION => 'upload bloqueado por uma extensão do PHP',
        ];

        return isset($mapa[$codigo]) ? $mapa[$codigo] : 'código desconhecido (' . $codigo . ')';
    }

    /**
     * Converte um erro fatal ocorrido durante o upload em JSON, em vez de deixar
     * o HTML do PHP virar o corpo da resposta.
     */
    protected function uploadFatalShutdown()
    {
        if ($this->upload_json_sent) {
            return;
        }

        $error = error_get_last();
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (empty($error) || !in_array($error['type'], $fatal, TRUE)) {
            // Encerrou sem responder e sem erro fatal (ex.: exit() em outro ponto,
            // timeout do PHP): o cliente recebeu um corpo vazio ou parcial.
            // Sempre é falha — o cliente ficou sem resposta interpretável.
            $this->diagLog('encerrado_sem_resposta', [
                'ultimo_erro' => empty($error) ? 'nenhum' : $error,
                'conexao' => connection_status(),
            ]);
            $this->diagFlush('encerrou sem enviar resposta ao cliente');
            return;
        }

        $ruido = $this->discardUploadOutput();

        $this->diagLog('erro_fatal', [
            'tipo' => $error['type'],
            'mensagem' => $error['message'],
            'arquivo' => $error['file'] . ':' . $error['line'],
            'ruido_bytes' => strlen($ruido),
            'ruido_previa' => mb_substr(trim(strip_tags($ruido)), 0, 500),
            'headers_ja_enviados' => headers_sent() ? 'sim' : 'nao',
            'arquivos' => $this->uploadFilesDiag(),
        ]);
        $this->diagFlush('erro fatal do PHP: ' . $error['message']);

        if (!headers_sent()) {
            // Função global do CI (Common.php): não depende do objeto Output,
            // que pode já não estar em estado utilizável durante o shutdown.
            set_status_header(500);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode([
            'success' => FALSE,
            'message' => 'Erro interno ao processar o arquivo enviado.',
            'data' => [],
            'errors' => ['file' => 'O servidor não conseguiu processar este arquivo. Tente uma imagem menor ou em outro formato.'],
        ]);
    }

    /**
     * Descarta a saída inesperada capturada pela guarda e registra no log.
     * @return string O ruído descartado (vazio quando não houve nenhum).
     */
    private function discardUploadOutput()
    {
        $noise = '';
        $base_level = $this->upload_output_level !== NULL ? (int) $this->upload_output_level : 0;

        while (ob_get_level() > $base_level) {
            $noise .= (string) ob_get_clean();
        }

        // Sem trim(): BOM e espaços em branco também precisam aparecer no log —
        // são exatamente o tipo de sujeira que quebra o JSON.parse do cliente.
        if ($noise !== '') {
            // Vai para o buffer como qualquer outra etapa: quem decide gravar é
            // sendUploadJson(), que trata ruído como motivo suficiente de falha.
            $this->diagLog('saida_inesperada', [
                'bytes' => strlen($noise),
                // hex do início revela BOM (EFBBBF) e espaços, que o trim esconde.
                'hex_inicio' => strtoupper(bin2hex(substr($noise, 0, 64))),
                'texto' => mb_substr(trim(strip_tags($noise)), 0, 1000),
            ]);
        }

        return $noise;
    }

    /**
     * Descarta a saída inesperada capturada, registra no log e devolve o JSON.
     * Encerra o request (o corpo já é a resposta final).
     *
     * @param array $payload
     * @param int   $statusCode
     */
    protected function sendUploadJson($payload, $statusCode = 200)
    {
        $ruido = $this->discardUploadOutput();
        $this->upload_json_sent = TRUE;

        $corpo = json_encode($payload);
        $jsonErro = json_last_error() !== JSON_ERROR_NONE ? json_last_error_msg() : '';

        if ($corpo === FALSE) {
            // Sem este fallback o corpo sairia vazio (json_encode falha em UTF-8
            // inválido, ex.: nome de arquivo em ISO-8859-1) e o cliente acusaria
            // "não foi possível interpretar a resposta do servidor".
            $corpo = json_encode([
                'success' => FALSE,
                'message' => 'Falha ao gerar a resposta do upload.',
                'data' => [],
                'errors' => ['file' => 'Erro interno ao serializar a resposta (' . $jsonErro . ').'],
            ]);
        }

        // headers_sent() com argumentos aponta o arquivo:linha da PRIMEIRA saída
        // do request — é o que identifica vazamento de conteúdo antes do JSON.
        $arquivoSaida = '';
        $linhaSaida = 0;
        $headersJaEnviados = headers_sent($arquivoSaida, $linhaSaida);

        $this->diagLog('resposta', [
            'http' => (int) $statusCode,
            'sucesso' => !empty($payload['success']) ? '1' : '0',
            'mensagem' => isset($payload['message']) ? $payload['message'] : '',
            'erros' => isset($payload['errors']) ? $payload['errors'] : [],
            'json_erro' => $jsonErro !== '' ? $jsonErro : 'nenhum',
            'corpo_bytes' => strlen($corpo),
            'corpo_previa' => mb_substr($corpo, 0, 800),
            'ruido_bytes' => strlen($ruido),
            'ruido_hex' => $ruido !== '' ? strtoupper(bin2hex(substr($ruido, 0, 32))) : '',
            'headers_ja_enviados' => $headersJaEnviados
                ? ($arquivoSaida . ':' . $linhaSaida)
                : 'nao',
            'headers_resposta' => $this->uploadResponseHeadersDiag(),
        ]);

        // Ponto de decisão: só grava o log quando o request terminou mal.
        if (empty($payload['success']) || (int) $statusCode !== 200) {
            $this->diagFlush('resposta de erro (HTTP ' . (int) $statusCode . '): '
                . (isset($payload['message']) ? $payload['message'] : 'sem mensagem'));
        } elseif ($ruido !== '') {
            // Upload funcionou, mas algo imprimiu no corpo. A guarda segurou
            // desta vez; no próximo request pode escapar e quebrar o JSON.
            $this->diagFlush('saída inesperada no corpo (' . strlen($ruido) . ' bytes) — o upload funcionou, mas isso quebra o JSON quando escapa da guarda');
        } else {
            $this->diagFinalizarSucesso();
        }

        if (!$headersJaEnviados) {
            if ((int) $statusCode !== 200) {
                set_status_header((int) $statusCode);
            }
            header('Content-Type: application/json; charset=utf-8');
            header('X-Upload-Log-Id: ' . $this->uploadLogId());
        }

        echo $corpo;
        exit;
    }

    /**
     * Upload de arquivo para a pasta images/ deste servidor.
     *
     * O envio por FTP/SFTP para a hospedagem do cliente foi removido junto com
     * o CMS (as credenciais viviam em crm_companies_sites). O nome do método
     * foi mantido porque várias telas o chamam.
     *
     * @param bool $resize TRUE (padrão) mantém o pós-processamento GD (PNG -> JPG).
     *                     O editor WYSIWYG passa FALSE: a conversão de PNGs grandes
     *                     estourava a memória e o fatal error virava resposta inválida.
     */
    public function uploadFileFtp($fieldName, $dir = 'test', $allowed = array('jpg', 'jpeg', 'png', 'bmp'), $resize = TRUE, $maxBytes = NULL)
    {
        if (!$this->validateUploadedFileSizes($fieldName, $maxBytes)) {
            return NULL;
        }

        $path = $dir . '/' . date('Y') . '/' . date('m');
        $file = $this->image_model->x_do_upload_all($fieldName, $path, $allowed, $resize);

        if (empty($file)) {
            // x_do_upload_all() devolve NULL sem dizer o motivo: o log abaixo
            // separa "extensão barrada" de "falha ao mover o arquivo temporário".
            $this->diagLog('gravacao_local_falhou', [
                'campo' => $fieldName,
                'pasta' => 'images/' . $path,
                'pasta_existe' => is_dir(FCPATH . 'images/' . $path) ? '1' : '0',
                'pasta_gravavel' => is_writable(FCPATH . 'images/' . $path) ? '1' : '0',
                'extensoes_aceitas' => $allowed,
                'resize_gd' => $resize ? '1' : '0',
                'arquivos' => $this->uploadFilesDiag(),
            ]);
            return NULL;
        }

        $this->diagLog('gravacao_local_ok', [
            'arquivo' => $file,
            'bytes' => file_exists($file) ? (int) filesize($file) : 0,
        ]);

        return $file;
    }

    /**
     * Upload de imagem do editor WYSIWYG (TinyMCE) para a hospedagem da empresa ativa.
     * O arquivo chega no campo "file"; o TinyMCE espera a URL final em "location".
     */
    public function editor_upload()
    {
        // Sem a guarda, qualquer warning do PHP (FTP, GD, mkdir) sai antes do JSON
        // e o editor mostra "O servidor retornou uma resposta inválida".
        $this->startUploadOutputGuard();

        if ($this->input->method(TRUE) !== 'POST') {
            $this->sendUploadJson([
                'success' => FALSE,
                'message' => 'Método de requisição inválido.',
                'data' => [],
                'errors' => ['method' => 'Utilize uma requisição POST.'],
            ], 405);
        }

        if (empty($_FILES['file']['name'])) {
            $this->sendUploadJson([
                'success' => FALSE,
                'message' => 'Selecione uma imagem para enviar.',
                'data' => [],
                'errors' => ['file' => 'Nenhuma imagem foi recebida. Se o arquivo é muito grande, ele pode ter sido descartado pelo servidor antes do envio.'],
            ], 422);
        }

        if (!empty($_FILES['file']['error'])) {
            $this->sendUploadJson([
                'success' => FALSE,
                'message' => 'Não foi possível receber a imagem enviada.',
                'data' => [],
                'errors' => ['file' => 'O upload do arquivo falhou antes do envio para a hospedagem.'],
            ], 422);
        }

        // Teto do servidor alinhado ao imageMaxSize do editor (footer.php).
        if (!empty($_FILES['file']['size']) && (int) $_FILES['file']['size'] > self::EDITOR_UPLOAD_MAX_SIZE) {
            $this->sendUploadJson([
                'success' => FALSE,
                'message' => 'A imagem é muito grande.',
                'data' => [],
                'errors' => ['file' => 'Envie uma imagem de até ' . (int) (self::EDITOR_UPLOAD_MAX_SIZE / 1048576) . ' MB.'],
            ], 422);
        }

        // SVG removido: pode conter <script> e gerar XSS armazenado no site público da empresa.
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!$this->validateUploadedFileExtensions('file', $allowed)) {
            $this->diagLog('extensao_ou_conteudo_invalido', [
                'campo' => 'file',
                'extensoes_aceitas' => $allowed,
                'arquivos' => $this->uploadFilesDiag(),
            ]);
            $this->sendUploadJson([
                'success' => FALSE,
                'message' => 'Formato de imagem não permitido.',
                'data' => [],
                'errors' => ['file' => 'Envie uma imagem JPG, PNG, GIF ou WebP.'],
            ], 422);
        }

        // $resize = FALSE: o editor não converte PNG em JPG. A conversão via GD não
        // checava retorno nem limite de memória e derrubava o upload de PNGs grandes.
        $file = $this->uploadFileFtp('file', 'media/editorial', $allowed, FALSE);
        if (empty($file)) {
            $erro = $this->image_model->last_error;
            $this->sendUploadJson([
                'success' => FALSE,
                'message' => 'Não foi possível gravar a imagem no servidor.',
                'data' => [],
                'errors' => ['file' => $erro !== '' ? $erro : 'Verifique as permissões da pasta images/ e tente novamente.'],
            ], 500);
        }

        // URL vazia = o editor recebe 200 sem "link" e acusa erro de resposta.
        $url = $this->getFtpFileUrl($file);
        $this->diagLog('url_publica', [
            'arquivo' => $file,
            'url' => $url,
            'url_vazia' => trim((string) $url) === '' ? '1' : '0',
        ]);

        $this->sendUploadJson([
            'location' => $url,
            'link' => $url,
            'success' => TRUE,
            'message' => 'Imagem enviada com sucesso.',
            'data' => [
                'file' => $file,
                'url' => $url,
            ],
            'errors' => [],
        ]);
    }

    /**
     * Upload múltiplo para a pasta images/ deste servidor.
     * Ver a nota sobre o FTP removido em uploadFileFtp().
     */
    public function uploadFilesFtp($fieldName, $dir = 'test', $allowed = array('jpg', 'jpeg', 'png', 'bmp'), $resize = FALSE, $maxBytes = NULL)
    {
        if (!$this->validateUploadedFileSizes($fieldName, $maxBytes)) {
            return [];
        }

        if (!$this->validateUploadedFileExtensions($fieldName, $allowed)) {
            $this->diagLog('extensao_ou_conteudo_invalido', [
                'campo' => $fieldName,
                'extensoes_aceitas' => $allowed,
                'arquivos' => $this->uploadFilesDiag(),
            ]);
            return [];
        }

        $path = $dir . '/' . date('Y') . '/' . date('m');
        $files = $this->image_model->x_do_upload_multiple_all($fieldName, $path, $allowed, $resize);
        if (empty($files)) {
            $this->diagLog('gravacao_local_falhou', [
                'campo' => $fieldName,
                'pasta' => 'images/' . $path,
                'pasta_existe' => is_dir(FCPATH . 'images/' . $path) ? '1' : '0',
                'pasta_gravavel' => is_writable(FCPATH . 'images/' . $path) ? '1' : '0',
                'extensoes_aceitas' => $allowed,
                'resize_gd' => $resize ? '1' : '0',
                'arquivos' => $this->uploadFilesDiag(),
            ]);
            return [];
        }

        $this->diagLog('gravacao_local_ok', ['arquivos_gravados' => $files]);

        return $files;
    }

    public function getFtpFileUrl($file)
    {
        return $this->image_model->get_url($file);
    }

    /**
     * Teto de tamanho por arquivo, aplicado no servidor.
     *
     * O maxFilesize do Dropzone só vale no navegador. Sem esta checagem, um
     * arquivo grande demais só falharia lá na frente — depois de gravar local e
     * redimensionar no GD — ou passaria direto em uma chamada feita fora da tela.
     *
     * O motivo vai para image_model->last_error, que é o canal que todos os
     * endpoints de upload já leem para montar a mensagem da tela.
     *
     * @param  string   $fieldName campo de $_FILES
     * @param  int|null $maxBytes  NULL usa UPLOAD_MAX_SIZE_PADRAO
     * @return bool     FALSE quando algum arquivo excede o limite
     */
    private function validateUploadedFileSizes($fieldName, $maxBytes = NULL)
    {
        if (empty($_FILES[$fieldName]['name'])) {
            return TRUE; // "nenhum arquivo" é tratado pelas checagens seguintes
        }

        $limite = ($maxBytes === NULL) ? self::UPLOAD_MAX_SIZE_PADRAO : (int) $maxBytes;
        if ($limite <= 0) {
            return TRUE;
        }

        $names = is_array($_FILES[$fieldName]['name']) ? $_FILES[$fieldName]['name'] : [$_FILES[$fieldName]['name']];
        $sizes = is_array($_FILES[$fieldName]['size']) ? $_FILES[$fieldName]['size'] : [$_FILES[$fieldName]['size']];

        foreach ($names as $i => $name) {
            $bytes = isset($sizes[$i]) ? (int) $sizes[$i] : 0;

            if ($bytes <= $limite) {
                continue;
            }

            $this->image_model->last_error = sprintf(
                'o arquivo "%s" tem %s MB e o limite é %s MB.',
                $name,
                number_format($bytes / 1048576, 2, ',', '.'),
                number_format($limite / 1048576, 0, ',', '.')
            );

            $this->diagLog('arquivo_muito_grande', [
                'campo' => $fieldName,
                'arquivo' => $name,
                'bytes' => $bytes,
                'limite_bytes' => $limite,
            ]);

            return FALSE;
        }

        return TRUE;
    }

    private function validateUploadedFileExtensions($fieldName, $allowed)
    {
        if (empty($_FILES[$fieldName]['name'])) {
            return FALSE;
        }

        // Extensões de imagem cujo conteúdo é validado de verdade (bloqueia SVG/HTML/polyglot renomeados).
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
        $allowedImageMimes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP, IMAGETYPE_BMP];

        $names = is_array($_FILES[$fieldName]['name']) ? $_FILES[$fieldName]['name'] : [$_FILES[$fieldName]['name']];
        $tmpNames = is_array($_FILES[$fieldName]['tmp_name']) ? $_FILES[$fieldName]['tmp_name'] : [$_FILES[$fieldName]['tmp_name']];

        foreach ($names as $i => $name) {
            $parts = explode('.', $name);
            $extension = mb_strtolower(end($parts));

            if (!in_array($extension, $allowed, TRUE)) {
                return FALSE;
            }

            // Para extensões de imagem, confere o conteúdo real do arquivo enviado.
            if (in_array($extension, $imageExtensions, TRUE)) {
                $tmp = isset($tmpNames[$i]) ? $tmpNames[$i] : NULL;
                if (empty($tmp) || !is_uploaded_file($tmp)) {
                    return FALSE;
                }
                $info = @getimagesize($tmp);
                if ($info === FALSE || !in_array($info[2], $allowedImageMimes, TRUE)) {
                    return FALSE;
                }
            }
        }

        return TRUE;
    }

    /**
     * Upload de arquivos na pasta local do servidor
     * @param $fieldName /nome do campo
     * @param string $dir /diretório
     * @param string[] $allowed /array simples com as extensões permitidas
     * @return false|string //null caso de erro ou json codificado em caso de sucesso
     * Upload multiplos arquivos amazon
     */

    public function uploadFileAWS($fieldName, $dir = 'test', $allowed = array('jpg', 'jpeg', 'png', 'bmp'))
    {
        $this->load->library('s3');
        $path = $dir . '/' . date('Y') . '/' . date('m');
        $file = $this->image_model->x_do_upload_all($fieldName, $path, $allowed, TRUE);
        if (!empty($file)) {
            $this->s3->putObjectFile($file, $this->config->item('s3_bucketname'), $file, $this->config->item('s3_access_key'), $this->config->item('s3_secret_key'));
            return $file;
        } else return NULL;
    }

    /**
     * @param $fieldName /nome do campo
     * @param string $dir /diretório
     * @param string[] $allowed /array simples com as extensões permitidas
     * @return false|string //null caso de erro ou json codificado em caso de sucesso
     * Upload multiplos arquivos amazon
     */

    public function uploadFilesAWS($fieldName, $dir = 'test', $allowed = array('jpg', 'jpeg', 'png', 'bmp'))
    {
        $this->load->library('s3');
        $path = $dir . '/' . date('Y') . '/' . date('m');
        $files = $_FILES[$fieldName];
        foreach ($files['name'] as $type) {
            $array = explode('.', $type);
            $extension = end($array);

            if (!in_array(mb_strtolower($extension), $allowed)) {
                return false;
            }
        }

        if ($_FILES[$fieldName])
            $files = $this->image_model->x_do_upload_multiple_all($fieldName, $path, $allowed, FALSE);

        if (!empty($files)) {
            foreach ($files as $file) {
                $this->s3->putObjectFile($file, $this->config->item('s3_bucketname'), $file, $this->config->item('s3_access_key'), $this->config->item('s3_secret_key'));
            }
            $params['file'] = json_encode($files);
        } else {
            $params['file'] = null;
        }

        return $params['file'];
    }

    public function json_getcep($address_zip)
    {
        header('Content-Type: application/x-json; charset=utf-8');
        $resposta = array();

        // Buscar o cep desejado na tabela de ceps cadastrados manualmente, caso não encontrar, buscar no viacep
        $cep_custom = $this->global_model->getWhere_off('crm_ceps', ['id_status' => 1, 'cep' => $address_zip], TRUE);
        if (!empty($cep_custom)) {
            $resposta['return'] = TRUE;
            $resposta['localidade'] = $cep_custom->address_city;
            $resposta['uf'] = $cep_custom->address_uf;

            $where = ['UF' => $cep_custom->address_uf, 'name' => $cep_custom->address_city];
            $crm_country_cities_v = $this->global_model->getWhere_off('crm_country_cities_v', $where, TRUE);
            if (!empty($crm_country_cities_v)) {
                $resposta['id_city'] = $crm_country_cities_v->id;
                $resposta['id_state'] = $crm_country_cities_v->id_state;
            } else {
                $resposta['id_city'] = 0;
                $resposta['id_state'] = 0;
            }
        } else {
            $this->load->library('viacep');
            if ($this->viacep->consultar(str_replace('.', '', $address_zip))) {
                $resposta['return'] = TRUE;
                $resposta['cep'] = $this->viacep->getCEP();
                $resposta['logradouro'] = $this->viacep->getLogradouro();
                $resposta['complemento'] = $this->viacep->getComplemento();
                $resposta['bairro'] = $this->viacep->getBairro();
                $resposta['localidade'] = $this->viacep->getLocalidade();
                $resposta['uf'] = $this->viacep->getUF();
                $resposta['ibge'] = $this->viacep->getIBGE();
                $resposta['gia'] = $this->viacep->getGIA();

                $where = ['UF' => $this->viacep->getUF(), 'name' => mb_strtoupper(trim($this->viacep->getLocalidade()))];
                $crm_country_cities_v = $this->global_model->getWhere_off('crm_country_cities_v', $where, TRUE);
                if (!empty($crm_country_cities_v)) {
                    $resposta['id_city'] = $crm_country_cities_v->id;
                    $resposta['id_state'] = $crm_country_cities_v->id_state;
                } else {
                    $resposta['id_city'] = 0;
                    $resposta['id_state'] = 0;
                }
            } else {
                $resposta['return'] = FALSE;
                $resposta['erro'] = $this->viacep->getUltimoErro();
            }
        }

        echo json_encode($resposta);
    }

    public function json_get_cities_by_id($id)
    {
        header('Content-Type: application/x-json; charset=utf-8');
        echo json_encode($this->global_model->getWhereOrderBy_off("crm_country_cities_v", ["id_state" => $id], 'name', 'asc', FALSE));
    }

    protected function sanitizeReturnUrl($return)
    {
        $return = trim((string) $return);
        if ($return === '' || strpos($return, '://') !== FALSE || strpos($return, '..') !== FALSE) {
            return '';
        }

        return ltrim($return, '/');
    }

    protected function getReturnRedirect($defaultPath)
    {
        $return = $this->sanitizeReturnUrl($this->input->post('return_url'));

        return $return !== '' ? base_url($return) : base_url($defaultPath);
    }
}
