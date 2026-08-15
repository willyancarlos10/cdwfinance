<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Login extends CI_Controller
{

    public function __construct()
    {
        parent::__construct();

        // A sessão saiu do autoload (ver application/config/autoload.php).
        // Aqui ela é obrigatória: login, logout, flashdata das telas de
        // cadastro/recuperação de senha.
        $this->load->library('session');
    }

    public function sair()
    {
        $this->session->unset_userdata('logado');
        redirect(base_url() . 'login?success=Logout feito com sucesso.');
    }

    public function index()
    {
        $this->data['menu'] = NULL;
        $this->load->view('login', $this->data);
    }

    public function json_getsefaz($cnpj = null)
    {
        header('Content-Type: application/json; charset=utf-8');

        $cnpj = sonumero((string) $cnpj);
        if (strlen($cnpj) !== 14) {
            echo json_encode([
                'return' => FALSE,
                'success' => FALSE,
                'message' => 'Informe um CNPJ válido com 14 dígitos.',
            ]);
            return;
        }

        if (!valida_cnpj($cnpj)) {
            echo json_encode([
                'return' => FALSE,
                'success' => FALSE,
                'message' => 'CNPJ inválido. Verifique os números informados.',
            ]);
            return;
        }

        $return = $this->receitaws_model->getSefaz($cnpj);
        if (!empty($return['return'])) {
            echo json_encode([
                'return' => TRUE,
                'success' => TRUE,
                'message' => $return['object'],
            ]);
            return;
        }

        echo json_encode([
            'return' => FALSE,
            'success' => FALSE,
            'message' => !empty($return['message']) ? $return['message'] : 'Não foi possível consultar o CNPJ informado.',
        ]);
    }

    public function json_getcep($address_zip = null)
    {
        header('Content-Type: application/x-json; charset=utf-8');
        if (empty($address_zip)) {
            $resposta['return'] = FALSE;
            $resposta['erro'] = "";
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
            } else {
                $resposta['return'] = FALSE;
                $resposta['erro'] = $this->viacep->getUltimoErro();
            }
        }
        echo json_encode($resposta);
    }

    public function callb_validador_cnpj($string)
    {
        $strlen = strlen(sonumero($string));
        if ($strlen !== 14 || !valida_cnpj(sonumero($string))) {
            $this->form_validation->set_message('callb_validador_cnpj', 'CNPJ não é válido, verifique.');
            return FALSE;
        }

        # Validar CNPJ já está cadastrado
        $crm_companies = $this->global_model->getWhere_off('crm_companies', ['cnpj' => sonumero($string)], TRUE);
        if (!empty($crm_companies)) {
            $this->form_validation->set_message('callb_validador_cnpj', 'CNPJ já cadastrado, verifique.');
            return FALSE;
        }

        return TRUE;
    }

    public function callb_validador_email($string)
    {
        if ($string != $this->input->post('email2')) {
            $this->form_validation->set_message('callb_validador_email', 'E-mails não correspondem, verifique.');
            return FALSE;
        }

        $user = $this->global_model->getWhere_off('crm_users', ["email" => $string]);
        if (!empty($user)) {
            $this->form_validation->set_message('callb_validador_email', 'E-mail já está em uso, verifique.');
            return FALSE;
        }
        return TRUE;
    }

    public function callb_validador_cidade($string)
    {
        $where = [
            "UF" => mb_strtoupper(trim($this->input->post('company')['address_uf'])),
            "name" => mb_strtoupper(trim(remover_acentos($string)))
        ];
        $city = $this->global_model->getWhere_off("crm_country_cities_v", $where, TRUE);
        if (empty($city)) {
            $this->form_validation->set_message('callb_validador_cidade', 'Favor verificar se os campos de estado e cidade estão corretamente preenchidos.');
            return FALSE;
        }
        return TRUE;
    }

    public function callb_validador_senha($string)
    {
        if (!password_strengh($string)) {
            $this->form_validation->set_message('callb_validador_senha', 'A senha deve possuir ao menos 8 caracteres, incluindo letras e números.');
            return FALSE;
        }

        return TRUE;
    }

    public function cadastro()
    {
        $this->form_validation->set_rules('company[cnpj]', 'CNPJ', 'trim|required|callback_callb_validador_cnpj');
        $this->form_validation->set_rules('company[name]', 'Razão Social', 'trim|required');
        $this->form_validation->set_rules('company[byname]', 'Nome Fantasia', 'trim|required');
        $this->form_validation->set_rules('company[owner]', 'Nome do contato', 'trim|required');
        $this->form_validation->set_rules('company[owner_cellphone]', 'Telefone celular para contato', 'trim|required|min_length[14]');
        $this->form_validation->set_rules('company[phone]', 'Telefone fixo', 'trim|min_length[14]');
        $this->form_validation->set_rules('company[address_zip]', 'CEP', 'trim|required|min_length[9]');
        $this->form_validation->set_rules('company[address]', 'Endereço', 'trim|required');
        $this->form_validation->set_rules('company[address_number]', 'Número', 'trim|required');
        $this->form_validation->set_rules('company[address_district]', 'Bairro', 'trim|required');
        $this->form_validation->set_rules('company[address_uf]', 'Estado', 'trim|required|exact_length[2]');
        $this->form_validation->set_rules('company[address_city]', 'Cidade', 'trim|required|callback_callb_validador_cidade');
        $this->form_validation->set_rules('email', 'E-mail', 'trim|required|valid_email|callback_callb_validador_email');
        $this->form_validation->set_rules('email2', 'Confirmação de e-mail', 'trim|required|valid_email');
        $this->form_validation->set_rules('passw1', 'Senha', 'trim|required|callback_callb_validador_senha');
        $this->form_validation->set_rules('passw2', 'Confirmação de senha', 'trim|required|matches[passw1]');
        $this->form_validation->set_message('required', 'O {field} deve ser preenchido.');
        $this->form_validation->set_message('min_length', 'O {field} deve ter pelo menos {param} caracteres.');
        $this->form_validation->set_message('exact_length', 'O {field} deve ter exatamente {param} caracteres.');
        $this->form_validation->set_message('matches', 'As senhas não correspondem.');
        if ($this->form_validation->run() == FALSE) {
            $this->db->cache_on();
            $this->data['cities'] = $this->global_model->getWhereOrderBy_off('crm_country_cities', ['id_state' => 18], 'name', 'asc', FALSE);
            $this->db->cache_off();

            // CNPJ enviado por parâmetro 
            if (!empty($_GET['cnpj'])) {
                $this->data['cnpj'] = $_GET['cnpj'];
            } else $this->data['cnpj'] = NULL;

            $this->load->view('register', $this->data);
        } else {
            # Buscar cidade
            $where = [
                "UF" => mb_strtoupper(trim($this->input->post('company')['address_uf'])),
                "name" => mb_strtoupper(trim(remover_acentos($this->input->post('company')['address_city'])))
            ];
            $crm_country_cities_v = $this->global_model->getWhere_off("crm_country_cities_v", $where, TRUE);

            # Iniciar transacao no banco
            $this->db->trans_begin();

            # Inserir empresa
            $data = $this->input->post('company');
            $data['cnpj'] = sonumero($data['cnpj']);
            $data['id_state'] = $crm_country_cities_v->id_state;
            $data['id_city'] = $crm_country_cities_v->id;
            $data['id_status'] = 4; # NAO ATIVADO #
            $data['alias'] = substr(url_title(strtolower(convert_accented_characters($data['name'], '-', TRUE))), 0, 115);
            $data['email'] = $this->input->post('email');
            $data['token'] = substr(sha1(time()), 0, -28);
            $data['created'] = date("Y-m-d H:i:s");
            $data['created_by'] = $this->config->item('id_user_process_auto');

            # Remover variáveis não usadas
            unset($data['address_uf']);
            unset($data['address_city']);

            $this->global_model->add('crm_companies', $data);
            $insertedId = $this->db->insert_id();

            # Permissões
            $permissions = array();
            $permission_items = $this->global_model->getWhere_off('crm_user_permissions_items', array('id_status' => 1, 'allow_company' => 1), FALSE);
            foreach ($permission_items as $c) $permissions[] = $c->id;

            $data = [
                'id_company' => $insertedId,
                'name' => 'Permissão padrão',
                'permissions' => json_encode($permissions),
                'id_status' => 1,
                'created' => date("Y-m-d H:i:s"),
                'created_by' => $this->config->item('id_user_process_auto'),
            ];
            $this->global_model->add('crm_user_permissions', $data);
            $insert_id_permission = $this->db->insert_id();

            # Usuário
            $data = [
                'id_company' => $insertedId,
                'id_permission' => $insert_id_permission,
                'id_status' => 1,
                'name' => mb_strtoupper($this->input->post('company')['owner']),
                'email' => mb_strtolower($this->input->post('email')),
                'cellphone' => $this->input->post('company')['owner_cellphone'],
                'password' => password_hash($this->input->post('passw1'), PASSWORD_DEFAULT),
                'created' => date("Y-m-d H:i:s"),
                'created_by' => $this->config->item('id_user_process_auto'),
            ];
            $this->global_model->add('crm_users', $data);

            if ($this->db->trans_status() === FALSE) {
                $this->db->trans_rollback();
                redirect(base_url('login?error=Houve um problema ao cadastrar sua empresa.'));
            } else $this->db->trans_commit();

            # retorno default
            redirect(base_url('login?success=Agradecemos seu cadastro, estamos ansiosos para ajudar você a fechar muitos negócios! seu cadastro passará por uma análise que, em breve será sendo efetivado e entraremos em contato!'));
        }
    }

    public function entrar()
    {
        $login = trim($this->input->post('myemail'));
        $senha = (string) $this->input->post('mypass');

        // Rate limit / anti-brute-force: bloqueia após muitas tentativas do mesmo IP.
        if ($this->loginBloqueado()) {
            redirect(base_url() . 'login?error=Muitas tentativas de login. Aguarde alguns minutos e tente novamente.');
            return;
        }

        if ($login === '' || $senha === '') {
            $this->registrarTentativaFalha($login);
            redirect(base_url() . 'login?error=E-mail ou senha inválidos.');
            return;
        }

        // Busca o usuário pelo e-mail e valida a senha com hash seguro (bcrypt),
        // migrando de forma transparente os hashes SHA1 legados no primeiro acesso.
        $user = $this->global_model->getWhere_off('crm_users', ['email' => $login], TRUE);
        if (empty($user) || !$this->verificarSenha($senha, $user)) {
            $this->registrarTentativaFalha($login);
            redirect(base_url() . 'login?error=E-mail ou senha inválidos.');
            return;
        }

        if ($user->id_status != 1) {
            redirect(base_url() . 'login?error=Usuário inativo.');
            return;
        }

        $this->limparTentativas();
        $this->efetivarLogin($user->id);
    }

    /**
     * Valida a senha informada contra o hash armazenado.
     * Suporta hash moderno (password_hash) e migra hashes SHA1 legados para bcrypt.
     */
    private function verificarSenha($senha, $user)
    {
        $hash = (string) $user->password;

        // Hash moderno (bcrypt/argon)
        if (password_verify($senha, $hash)) {
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                $this->global_model->edit('crm_users', ['password' => password_hash($senha, PASSWORD_DEFAULT)], 'id', $user->id);
            }
            return TRUE;
        }

        // Legado: SHA1 sem salt (40 chars hex) → valida e migra para bcrypt.
        if (strlen($hash) === 40 && ctype_xdigit($hash) && hash_equals($hash, sha1($senha))) {
            $this->global_model->edit('crm_users', ['password' => password_hash($senha, PASSWORD_DEFAULT)], 'id', $user->id);
            return TRUE;
        }

        return FALSE;
    }

    /**
     * Máximo de tentativas de login falhas por IP dentro da janela.
     */
    const LOGIN_MAX_TENTATIVAS = 8;
    const LOGIN_JANELA_MINUTOS = 15;

    private function loginBloqueado()
    {
        $ip = $this->input->ip_address();
        $row = $this->db->query(
            'SELECT COUNT(*) AS total FROM crm_login_attempts WHERE ip = ? AND created >= (NOW() - INTERVAL ? MINUTE)',
            [$ip, self::LOGIN_JANELA_MINUTOS]
        )->row();

        return !empty($row) && (int) $row->total >= self::LOGIN_MAX_TENTATIVAS;
    }

    private function registrarTentativaFalha($email)
    {
        $this->db->query(
            'INSERT INTO crm_login_attempts (ip, email, created) VALUES (?, ?, NOW())',
            [$this->input->ip_address(), mb_substr((string) $email, 0, 255)]
        );

        // Limpeza oportunista de registros antigos para não crescer indefinidamente.
        $this->db->query('DELETE FROM crm_login_attempts WHERE created < (NOW() - INTERVAL 1 DAY)');
    }

    private function limparTentativas()
    {
        $this->db->query('DELETE FROM crm_login_attempts WHERE ip = ?', [$this->input->ip_address()]);
    }

    private function efetivarLogin($id)
    {
        // Verificar se a empresa está ativa
        $crm_users = $this->global_model->getWhere_off('crm_users', ['id' => $id], TRUE);
        $crm_companies = $this->global_model->getWhere_off('crm_companies', ['id' => $crm_users->id_company], TRUE);
        $permissions = $this->global_model->getWhere_off('crm_user_permissions', array('id' => $crm_users->id_permission, 'id_status' => 1, 'id_company' => $crm_users->id_company), TRUE);

        if ($crm_companies->id_status == 1) {
            # OK #
        } elseif ($crm_companies->id_status == 2) {
            redirect(base_url() . 'login?error=Prezado, seu cadastro está inativo entre em contato conosco para mais informações.');
        } elseif ($crm_companies->id_status == 3) {
            redirect(base_url() . 'login?error=Prezado, seu cadastro foi suspenso entre em contato conosco para mais informações.');
        } elseif ($crm_companies->id_status == 4) {
            redirect(base_url() . 'login?success=PrezadoSeu cadastro está em análise, você será notificado por e-mail assim que a validação estiver concluída.');
        } else {
            exit;
        }

        // Verificar se a permissão de acesso do usuário está ATIVA
        if (empty($permissions)) redirect(base_url() . 'login?success=Usuário sem permissão de acesso definido.');
        $arrayPermissions = json_decode($permissions->permissions);

        $showChangeCompany = FALSE;

        // Buscar grupo de empresa
        $group = $this->global_model->getWhere_off('crm_user_groups', ['id_status' => 1, 'id' => $crm_users->id_group], TRUE);
        if (!empty($group)) {
            $unt = json_decode($group->companies);
            if (empty($unt)) {
                // Todas as empresas
                if ($crm_users->id_company == 1) {
                    $units = "ALL";
                    $showChangeCompany = TRUE;
                } else $units = "(" . $crm_companies->id . ")";
            } else {
                $units = "0,";
                foreach ($unt as $c) $units .= $c . ',';
                $units .= $crm_companies->id; // empresa do usuário
                $units = "($units)";
                $showChangeCompany = TRUE;
            }
        } else $units = "(" . $crm_companies->id . ")";

        if ($crm_users->id_company == 1) $showChangeCompany = TRUE;

        // Apaga qualquer sessão anterior para que não haja duplicidade de conexões
        if ($crm_companies->id != 1) $this->global_model->uniqueSession($crm_users->id);

        // Sucesso
        $data = array(
            'forgot_token' => "",
            'last_login' => date("Y-m-d H:i:s"),
            'ip_login' => $_SERVER['REMOTE_ADDR'],
        );
        $this->global_model->edit('crm_users', $data, 'id', $crm_users->id);

        // Renova também o último acesso da empresa (qualquer usuário dela que loga atualiza o carimbo).
        $this->global_model->edit('crm_companies', ['last_login' => date("Y-m-d H:i:s")], 'id', $crm_users->id_company);

        // Gerar um histórico do login do usuário
        $array = [
            'id_user' => $crm_users->id,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'],
            'server_addr' => $_SERVER['REMOTE_ADDR'],
            'remote_host' => (!empty($_SERVER['REMOTE_HOST'])) ? $_SERVER['REMOTE_HOST'] : "",
            'referer' => $_SERVER['HTTP_REFERER'],
            'created' => date("Y-m-d H:i:s"),
        ];
        $this->global_model->add('crm_users_logins', $array);

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
        if (empty($preferences)) {
            $active = [
                'order_preference' => 1,
                'list_preference' => 0,
            ];
            $this->global_model->edit('crm_users', ['preferences' => json_encode($active)], 'id', $crm_users->id);
            $preferences = (object) $active;
        }

        // Gravar objetos na sessão
        $this->session->set_userdata('logado', TRUE);
        $this->session->set_userdata('preference_sidebar', 'opened');
        $this->session->set_userdata('user_first_name', $name);
        $this->session->set_userdata('user_image', $image);
        $this->session->set_userdata('company', $crm_companies);
        $this->session->set_userdata('user', $crm_users);
        $this->session->set_userdata('user_preferences', $preferences);
        $this->session->set_userdata('permissions', $arrayPermissions);
        $this->session->set_userdata('relateds_units', $units);
        $this->session->set_userdata('showChangeCompany', $showChangeCompany);

        // Filtro padrão da empresa selecionada
        $array = [
            'id_company' => $this->session->userdata('company')->id,
            'select2_companies' => '<option value="' . $crm_companies->id . '">' . $crm_companies->byname . ' </option>',
        ];
        $this->session->set_userdata('f_company', $array);

        // Verificar se há última url na sessão
        if (!empty($this->session->userdata('last_url'))) {
            $last_url = $this->session->userdata('last_url');
            $this->session->set_userdata('last_url', NULL);
            redirect($last_url);
        } else redirect(base_url() . 'painel');
    }

    public function esqueceu()
    {
        $this->form_validation->set_rules('email', '', 'required');
        if ($this->form_validation->run() == FALSE) {
            $this->load->view('forgot');
        } else {
            $login = trim($this->input->post('email'));
            if (!empty($login)) {
                # Verificar se o usuário existe
                $crm_users = $this->global_model->getWhere_off('crm_users', ['id_status' => 1, 'email' => $login], TRUE);
                if (!empty($crm_users)) {
                    # Verificar se a empresa está ativa
                    $crm_companies = $this->global_model->getWhere_off('crm_companies', array('id_status' => 1, 'id' => $crm_users->id_company), TRUE);
                    if (!empty($crm_companies)) {
                        # Gerar um token válido
                        if (empty($crm_users->forgot_token)) {
                            $this->data['forgot_token'] = uniqid();
                            $this->global_model->edit('crm_users', ['forgot_token' => $this->data['forgot_token']], 'id', $crm_users->id);
                        } else {
                            $this->data['forgot_token'] = $crm_users->forgot_token;
                        }

                        # Enviar e-mail com instruções
                        $this->data['title'] = "Redefinição de senha";
                        $this->data['user'] = $crm_users;
                        $body = $this->global_model->body_email("emails/password/forgot", $this->data);
                        $this->global_model->send_email($this->data['title'], $body, $crm_users->email, NULL, NULL, NULL);
                        redirect(base_url() . 'login?success=Enviamos um e-mail com as instruções para redefinição de sua senha, fique de olho.');
                    } else redirect(base_url() . 'login/esqueceu?error=E-mail inválido ou empresa inativa.');
                } else redirect(base_url() . 'login/esqueceu?error=E-mail inválido ou empresa inativa.');
            } else redirect(base_url() . 'login/esqueceu?error=E-mail inválido ou empresa inativa.');
        }
    }

    public function redefinir($forgot_token = NULL)
    {
        if (empty($forgot_token)) redirect(base_url());

        # Verificar se o usuário existe
        $crm_users = $this->global_model->getWhere_off('crm_users', ['id_status' => 1, 'forgot_token' => $forgot_token], TRUE);
        if (!empty($crm_users)) {
            $crm_companies = $this->global_model->getWhere_off('crm_companies', ['id_status' => 1, 'id' => $crm_users->id_company], TRUE);
            if (empty($crm_companies)) redirect(base_url() . 'login?error=Empresa não encontrada.');
        } else redirect(base_url() . 'login?error=Token de acesso expirado.');

        $this->form_validation->set_rules('passw1', '', 'required');
        if ($this->form_validation->run() == FALSE) {
            $this->data['forgot_token'] = $forgot_token;
            $this->data['user'] = $crm_users;
            $this->load->view('reset', $this->data);
        } else {
            $senha = $this->input->post('passw1');
            $senha2 = $this->input->post('passw2');
            $forgot_token = $this->input->post('forgot_token');
            if ($senha === $senha2) {
                $ret = password_strengh($this->input->post('passw1'));
                if (!$ret) {
                    redirect(base_url() . 'login/redefinir/' . $forgot_token . '?error=A senha não cumpre os requisitos de segurança (8 caracteres com letras e números).');
                } else {
                    # Verificar se o usuário existe
                    $data = array('id_status' => 1, 'forgot_token' => $forgot_token);
                    $crm_users = $this->global_model->getWhere_off('crm_users', $data, TRUE);
                    if (!empty($crm_users)) {
                        # Verificar se a franquia está ativa
                        $crm_companies = $this->global_model->getWhere_off('crm_companies', array('id' => $crm_users->id_company), TRUE);
                        if (!empty($crm_companies)) {
                            $data = array(
                                'forgot_token' => "",
                                'password' => password_hash($senha, PASSWORD_DEFAULT)
                            );
                            $this->global_model->edit('crm_users', $data, 'id', $crm_users->id);
                            redirect(base_url() . 'login?success=Senha modificada com sucesso.');
                        } else redirect(base_url() . 'login?error=Empresa não encontrada.');
                    } else redirect(base_url() . 'login?error=Token de acesso expirado.');
                }
            } else redirect(base_url() . 'login/redefinir/' . $forgot_token . '?error=As senhas não se coicidem.');
        }
    }
}
