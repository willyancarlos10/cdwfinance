<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Tipos de serviço (GESTÃO) — catálogo global, sem id_company.
 *
 * Os tipos serão relacionados aos contratos de qualquer tenant quando o
 * módulo de Contratos existir; por isso o cadastro é único e administrado
 * apenas pela empresa master, como os demais itens da seção GESTÃO.
 *
 * URLs com hífen resolvem sem rota: translate_uri_dashes = TRUE em
 * config/routes.php ('tipos-servicos' -> Tipos_servicos).
 */
class Tipos_servicos extends MY_Controller
{

  public function __construct()
  {
    parent::__construct();

    if ($this->session->userdata('company')->id != 1) {
      $this->session->set_flashdata('warning', 'Sem permissão de acesso.');
      redirect(base_url());
    }
    $this->data['menu'] = 'tipos-servicos';
  }

  public function index()
  {
    $this->form_validation->set_rules('name', '', 'required|trim|max_length[150]');
    if ($this->form_validation->run() == FALSE) {
      $this->data['crm_service_types'] = $this->global_model->getWhereOrderBy_off('crm_service_types_v', "1=1", 'name', 'asc', FALSE);
      $this->load->view('header', $this->data);
      $this->load->view('service_types/list', $this->data);
      $this->load->view('footer', $this->data);
    } else {
      $name = mb_strtoupper(trim($this->input->post('name')));

      // UNIQUE(name) no banco; a checagem aqui só troca o erro seco do MySQL
      // por uma mensagem amigável na tela.
      $existente = $this->global_model->getWhere_off('crm_service_types', ['name' => $name], TRUE);
      if (!empty($existente)) {
        $this->session->set_flashdata('warning', 'Já existe um tipo de serviço com este nome.');
        redirect(base_url('tipos-servicos'));
      }

      $array = [
        'name' => $name,
        'id_status' => 1,
        'created' => date("Y-m-d H:i:s"),
        'created_by' => $this->session->userdata('user')->id,
        'modified' => date("Y-m-d H:i:s"),
        'modified_by' => $this->session->userdata('user')->id,
      ];
      $this->global_model->add('crm_service_types', $array);
      $this->session->set_flashdata('success', 'Processamento realizado com sucesso.');
      redirect(base_url('tipos-servicos'));
    }
  }

  public function editar()
  {
    $this->form_validation->set_rules('name', '', 'required|trim|max_length[150]');
    if ($this->form_validation->run() == FALSE) {
      $id = 0;
      if (!empty($_GET['id'])) $id = (int) $_GET['id'];

      $this->data['result'] = $this->global_model->getWhere_off('crm_service_types_v', ['id' => $id], TRUE);
      if (empty($this->data['result'])) {
        $this->session->set_flashdata('warning', 'Registro não encontrado.');
        redirect(base_url('tipos-servicos'));
      }

      $this->load->view('header', $this->data);
      $this->load->view('service_types/edit', $this->data);
      $this->load->view('footer', $this->data);
    } else {
      $id = (int) $this->input->post('id');
      $name = mb_strtoupper(trim($this->input->post('name')));

      $existente = $this->global_model->getWhere_off('crm_service_types', ['name' => $name], TRUE);
      if (!empty($existente) && (int) $existente->id !== $id) {
        $this->session->set_flashdata('warning', 'Já existe um tipo de serviço com este nome.');
        redirect(base_url('tipos-servicos/editar?id=' . $id));
      }

      $array = [
        'name' => $name,
        'modified' => date("Y-m-d H:i:s"),
        'modified_by' => $this->session->userdata('user')->id,
      ];
      $this->global_model->edit('crm_service_types', $array, 'id', $id);
      $this->session->set_flashdata('success', 'Processamento realizado com sucesso.');
      redirect(base_url('tipos-servicos'));
    }
  }

  public function post_excluir()
  {
    $id = (int) $this->input->post('id');
    $tipo = $this->global_model->getWhere_off('crm_service_types', ['id' => $id], TRUE);

    if (empty($tipo)) {
      $this->session->set_flashdata('warning', 'Registro não encontrado.');
      redirect(base_url('tipos-servicos'));
    }

    // Tipo vinculado a contrato não pode sair: além de quebrar a FK do N:N
    // (crm_contracts_services), sumiria dos contratos que o exibem.
    $emUso = $this->global_model->countWhere_off('crm_contracts_services', ['id_service_type' => $id]);
    if ($emUso > 0) {
      $this->session->set_flashdata('error', 'Este tipo de serviço está vinculado a ' . $emUso . ' contrato(s) e não pode ser excluído. Inative-o se não deve mais ser usado.');
      redirect(base_url('tipos-servicos'));
    }

    $this->global_model->delete('crm_service_types', 'id', $id);

    $this->session->set_flashdata('success', 'Tipo de serviço excluído com sucesso.');
    redirect(base_url('tipos-servicos'));
  }
}
