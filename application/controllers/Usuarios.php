<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Usuarios extends MY_Controller
{
  public function __construct()
  {
    parent::__construct();
    $this->data['menu'] = 'usuarios';
  }

  private function setDefaultUserFilter()
  {
    $filters = $this->session->userdata('f_users');
    if (!is_array($filters)) {
      $filters = [];
    }
    if (!array_key_exists('keyword', $filters)) {
      $filters['keyword'] = '';
    }
    $this->session->set_userdata('f_users', $filters);
  }

  public function index()
  {
    $this->setDefaultUserFilter();
    $idCompany = $this->getCurrentCompanyId();

    $whereClausule = 'id_company = ' . $idCompany;
    if (!empty($this->session->userdata('f_users')['keyword'])) {
      $whereClausule .= ' and ' . $this->global_model->getInsensitiveLikeCondition('name', $this->session->userdata('f_users')['keyword']);
    }

    $this->data['results'] = $this->global_model->getWhereOrderBy_off('crm_users_v', $whereClausule, 'name', 'desc', FALSE);
    $this->data['crm_user_permissions'] = $this->global_model->getWhereOrderBy_off('crm_user_permissions', ['id_company' => $idCompany], 'name', 'asc', FALSE);
    $this->data['crm_user_groups'] = $this->global_model->getWhereOrderBy_off('crm_user_groups_v', "1=1", 'name', 'asc', FALSE);

    // TRAZER APENAS GRUPOS DE ACESSO DA EMPRESA DO USUARIO SELECIONADO
    $groups = [];
    foreach ($this->data['crm_user_groups'] as $g) {
      $companies = json_decode($g->companies);
      if (!empty($companies)) {
        foreach ($companies as $u) {
          if ($idCompany == $u) {
            $groups[] = $g;
            //break;
          }
        }
      } else {
        if ($this->session->userdata('user')->id_company == 1) $groups[] = $g;
      }
    }
    $this->data['crm_user_groups'] = $groups;

    $this->load->view('header', $this->data);
    $this->load->view('users/list', $this->data);
    $this->load->view('footer', $this->data);
  }

  public function post_usernew()
  {
    $this->form_validation->set_rules('user[name]', '', 'required');
    $this->form_validation->set_rules('user[cellphone]', 'Celular', 'min_length[14]');
    $this->form_validation->set_message('min_length', 'O {field} deve ter pelo menos {param} caracteres.');
    if ($this->form_validation->run() == TRUE) {
      $this->data['results'] = $this->global_model->getWhereOrderBy_off('crm_users_v', ['id_company' => $this->getCurrentCompanyId()], 'name', 'desc', FALSE);

      if ($this->input->post('passdf') != $this->input->post('passdf2')) {
        $this->session->set_flashdata('warning', 'As senhas deverão ser iguais.');
        redirect($this->input->post('url'));
      }

      $ret = password_strengh($this->input->post('passdf'));
      if (!$ret) {
        $this->session->set_flashdata('warning', 'A senha não cumpre os requisitos de segurança (8 caracteres com letras e números).');
        redirect($this->input->post('url'));
      }

      // Validar se e-mail já está cadastrado no CRM
      $users = $this->global_model->getWhere_off('crm_users', array('email' => $this->input->post('user')['email']), TRUE);
      if (!empty($users)) {
        $this->session->set_flashdata('warning', 'Já existe um usuário cadastrado com o e-mail ' . $this->input->post('user')['email'] . ', favor utilizar outr e-mail.');
        redirect($this->input->post('url'));
      }

      // Não permitir outra empresa selecionar grupo 1
      $id_group = $this->input->post('user')['id_group'];
      if ($id_group == 1) {
        if ($this->input->post('id_company') != 1) $id_group = 0;
      }

      $data = $this->input->post('user');
      $data['id_company'] = $this->getCurrentCompanyId();
      $data['name'] = mb_strtoupper(trim($this->input->post('user')['name']));
      $data['password'] = password_hash($this->input->post('passdf'), PASSWORD_DEFAULT);
      $data['id_group'] = $id_group;
      $data['created'] = date("Y-m-d H:i:s");
      $data['created_by'] = $this->session->userdata('user')->id;

      $this->global_model->add('crm_users', $data);
      $this->session->set_flashdata('success', "Usuário criado com sucesso.");
      redirect($this->input->post('url'));
    } else {
      redirect($this->input->post('url'));
    }
  }

  public function json_postinativar($id = 0)
  {
    header('Content-Type: application/x-json; charset=utf-8');
    $crm_users = $this->global_model->getWhere_off('crm_users', ["id" => $id], TRUE);
    if (empty($crm_users)) echo json_encode(['return' => FALSE, 'message' => 'Usuário não encontrado']);
    $array = [
      'id_status' => 2,
      'modified' => date("Y-m-d H:i:s"),
      'modified_by' => $this->session->userdata('user')->id
    ];
    $this->global_model->edit("crm_users", $array, "id", $id);
    $this->session->set_flashdata('success', 'Registro salvo com sucesso.');
    echo json_encode(['return' => TRUE, 'url' => base_url('empresas/info?id=' . $crm_users->id_company)]);
  }

  public function json_postreativar($id = 0)
  {
    header('Content-Type: application/x-json; charset=utf-8');
    $crm_users = $this->global_model->getWhere_off('crm_users', ["id" => $id], TRUE);
    if (empty($crm_users)) echo json_encode(['return' => FALSE, 'message' => 'Usuário não encontrado']);
    $array = [
      'id_status' => 1,
      'modified' => date("Y-m-d H:i:s"),
      'modified_by' => $this->session->userdata('user')->id
    ];
    $this->global_model->edit("crm_users", $array, "id", $id);
    $this->session->set_flashdata('success', 'Registro salvo com sucesso.');
    echo json_encode(['return' => TRUE, 'url' => base_url('empresas/info?id=' . $crm_users->id_company)]);
  }

  public function json_postchangestatus()
  {
    header('Content-Type: application/json; charset=utf-8');

    $id = (int) $this->input->post('id');
    $ativar = (int) $this->input->post('ativar');
    $crm_users = $this->global_model->getWhere_off('crm_users', ['id' => $id], TRUE);

    if (empty($crm_users)) {
      echo json_encode([
        'success' => FALSE,
        'return' => FALSE,
        'message' => 'Usuário não encontrado',
        'data' => NULL,
        'errors' => ['id' => 'Usuário não encontrado']
      ]);
      return;
    }

    $array = [
      'id_status' => ($ativar === 1) ? 1 : 2,
      'modified' => date("Y-m-d H:i:s"),
      'modified_by' => $this->session->userdata('user')->id
    ];

    $this->global_model->edit('crm_users', $array, 'id', $id);

    $crm_status = $this->global_model->getWhere_off('crm_status', ['id' => $array['id_status']], TRUE);
    $status_name = (!empty($crm_status) && !empty($crm_status->name)) ? $crm_status->name : (($array['id_status'] == 1) ? 'Ativo' : 'Inativo');
    $status_color = (!empty($crm_status) && !empty($crm_status->color)) ? $crm_status->color : (($array['id_status'] == 1) ? 'success' : 'secondary');
    $message = ($array['id_status'] == 1) ? 'Usuário ativado com sucesso.' : 'Usuário desativado com sucesso.';

    echo json_encode([
      'success' => TRUE,
      'return' => TRUE,
      'message' => $message,
      'data' => [
        'id' => $id,
        'id_status' => $array['id_status'],
        'status_name' => $status_name,
        'status_color' => $status_color
      ],
      'errors' => []
    ]);
  }

  public function editar()
  {
    $this->form_validation->set_rules('user[name]', '', 'required');
    if ($this->form_validation->run() == FALSE) {
      $this->data['menu'] = 'usuarios';

      $id = 0;
      $source = '';
      if (!empty($_GET['id'])) {
        $id = $_GET['id'];
        $source = $_GET['source'];
      }

      $this->data['result'] = $this->global_model->getWhere('crm_users_v', ['id' => $id], TRUE);
      if (empty($this->data['result'])) {
        $this->session->set_flashdata('warning', 'Registro não encontrado.');
        redirect(base_url());
      }

      $this->data['crm_companies'] = $this->global_model->getWhere_off('crm_companies', ['id' => $this->data['result']->id_company], TRUE);
      $this->data['crm_user_permissions'] = $this->global_model->getWhereOrderBy_off('crm_user_permissions', ['id_company' => $this->data['result']->id_company], 'name', 'asc', FALSE);
      $this->data['crm_user_groups'] = $this->global_model->getWhereOrderBy_off('crm_user_groups_v', "1=1", 'name', 'asc', FALSE);

      // TRAZER APENAS GRUPOS DE ACESSO DA EMPRESA DO USUARIO SELECIONADO
      $groups = [];
      foreach ($this->data['crm_user_groups'] as $g) {
        $companies = json_decode($g->companies);
        if (!empty($companies)) {
          foreach ($companies as $u) {
            if ($this->data['result']->id_company == $u) {
              $groups[] = $g;
              //break;
            }
          }
        } else {
          if ($this->session->userdata('user')->id_company == 1) $groups[] = $g;
        }
      }
      $this->data['crm_user_groups'] = $groups;

      $this->data['url'] = base_url('usuarios');
      if ($source == 'company') $this->data['url'] = base_url('empresas/info?id=' . $this->data['result']->id_company);

      $this->load->view('header', $this->data);
      $this->load->view('users/edit', $this->data);
      $this->load->view('footer', $this->data);
    } else {
      $this->data['result'] = $this->global_model->getWhere('crm_users_v', ['id' => $this->input->post('id')], TRUE);

      if (!empty($this->input->post('passdf'))) {
        if ($this->input->post('passdf') != $this->input->post('passdf2')) {
          $this->session->set_flashdata('warning', 'As senhas deverão ser iguais.');
          redirect($this->input->post('url'));
        }
        $ret = password_strengh($this->input->post('passdf'));
        if (!$ret) {
          $this->session->set_flashdata('warning', 'A senha não cumpre os requisitos de segurança (8 caracteres com letras e números).');
          redirect($this->input->post('url'));
        }
      }

      // Validar se e-mail já está cadastrado no CRM
      if ($this->input->post('user')['email'] != $this->data['result']->email) {
        $users = $this->global_model->getWhere_off('crm_users', ['email' => $this->input->post('user')['email']], TRUE);
        if (!empty($users)) {
          $this->session->set_flashdata('warning', 'Já existe um usuário cadastrado com o e-mail ' . $this->input->post('user')['email'] . ', favor utilizar outr e-mail.');
          redirect($this->input->post('url'));
        }
      }

      // Não permitir outra empresa selecionar grupo 1
      $id_group = $this->input->post('user')['id_group'];
      if ($id_group == 1) {
        if ($this->data['result']->id_company != 1) $id_group = 0;
      }

      $data = $this->input->post('user');
      $data['name'] = mb_strtoupper(trim($this->input->post('user')['name']));
      $data['id_group'] = $id_group;
      $data['modified'] = date("Y-m-d H:i:s");
      $data['modified_by'] = $this->session->userdata('user')->id;

      // Nova senha
      if (!empty($this->input->post('passdf'))) $data['password'] = password_hash($this->input->post('passdf'), PASSWORD_DEFAULT);

      $this->global_model->edit('crm_users', $data, 'id', $this->input->post('id'));
      $this->session->set_flashdata('success', 'Processamento realizado com sucesso.');
      redirect($this->input->post('url'));
    }
  }

  public function filtrar()
  {
    $this->setDefaultUserFilter();
    if (empty($this->input->post('f_users'))) redirect(base_url('usuarios'));

    $array = array_merge($this->session->userdata('f_users'), $this->input->post('f_users'));
    $this->session->set_userdata('f_users', $array);
    redirect(base_url('usuarios'));
  }
}
