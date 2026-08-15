<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Permissoes extends MY_Controller
{
	public function __construct()
	{
		parent::__construct();
		$this->global_model->islogged();
		$this->data['menu'] = 'permissoes';
	}

	private function setDefaultPermissionFilter()
	{
		$filters = $this->session->userdata('f_permissoes');
		if (!is_array($filters)) {
			$filters = [];
		}
		if (!array_key_exists('keyword', $filters)) {
			$filters['keyword'] = '';
		}
		$this->session->set_userdata('f_permissoes', $filters);
	}

	public function index()
	{
		$this->setDefaultPermissionFilter();
		$idCompany = $this->getCurrentCompanyId();

		$whereClausule = "id_company = " . $idCompany;
		if (!empty($this->session->userdata('f_permissoes')['keyword'])) {
			$whereClausule .= ' and ' . $this->global_model->getInsensitiveLikeCondition('name', $this->session->userdata('f_permissoes')['keyword']);
		}
		$this->data['results'] = $this->global_model->getWhereOrderBy_off("crm_user_permissions_v", $whereClausule, 'name', 'asc', FALSE);
		$this->data['crm_permissions_items'] = $this->global_model->getWhereOrderBy_off('crm_user_permissions_items', "1=1", "name", "asc", FALSE);

		$this->load->view('header', $this->data);
		$this->load->view('permissions/list', $this->data);
		$this->load->view('footer', $this->data);
	}

	public function filtrar()
	{
		$this->setDefaultPermissionFilter();
		if (empty($this->input->post('f_permissoes'))) redirect(base_url('permissoes'));

		$array = array_merge($this->session->userdata('f_permissoes'), $this->input->post('f_permissoes'));
		$this->session->set_userdata('f_permissoes', $array);
		redirect(base_url('permissoes'));
	}

	public function novo()
	{
		$this->form_validation->set_rules('name', '', 'required');
		if ($this->form_validation->run() == TRUE) {
			$data = [
				'name' => $this->input->post('name'),
				'id_company' => $this->getCurrentCompanyId(),
				'permissions' => json_encode($this->input->post('permissions')),
				'id_status' => 1,
				'modified' => date("Y-m-d H:i:s"),
				'modified_by' => $this->session->userdata('user')->id,
				'created' => date("Y-m-d H:i:s"),
				'created_by' => $this->session->userdata('user')->id
			];
			// Adiciona na tabela
			$this->global_model->add('crm_user_permissions', $data);
			$this->session->set_flashdata('success', 'Registro inserido com sucesso.');
			redirect(base_url('permissoes'));
		}
	}

	public function editar($id = null)
	{
		if (empty($id)) redirect(base_url('permissoes'));
		$this->data['result'] = $this->global_model->getWhere('crm_user_permissions_v', ['id' => $id], TRUE);
		if (empty($this->data['result'])) redirect(base_url('permissoes'));

		$this->form_validation->set_rules('name', '', 'required');
		if ($this->form_validation->run() == FALSE) {
			$this->data['crm_permissions_items'] = $this->global_model->getWhereOrderBy_off('crm_user_permissions_items', "1=1", "name", "ASC", FALSE);
			$this->data['users'] = $this->global_model->getWhereOrderBy_off('crm_users_v', array("id_permission" => $id, 'id_status' => 1), "name", "asc", FALSE);

			### buscar o histórico de modificações 
			$this->db->select('a.description, b.name, a.created, c.byname as company_byname');
			$this->db->from('crm_user_permissions_logs a');
			$this->db->join('crm_users b', 'b.id = a.created_by');
			$this->db->join('crm_companies c', 'c.id = b.id_company');
			$this->db->where('a.id_user_permission', $id);
			$this->db->order_by('a.id desc');
			$this->db->limit('50');
			$this->data['history'] = $this->db->get()->result();

			$this->load->view('header', $this->data);
			$this->load->view('permissions/edit', $this->data);
			$this->load->view('footer', $this->data);
		} else {
			$data = [
				'name' => $this->input->post('name'),
				'permissions' => json_encode($this->input->post('permissions')),
				'modified' => date("Y-m-d H:i:s"),
				'modified_by' => $this->session->userdata('user')->id,
			];

			if ($this->global_model->edit('crm_user_permissions', $data, 'id', $this->input->post('id')) == TRUE) {
				### Gerar histórico das alterações ###
				$message = null;
				if ($this->data['result']->name != $this->input->post('name')) {
					$message .= "Nome de <strong>" . $this->data['result']->name . "</strong> para <strong>" . $this->input->post('name') . "</strong><br/>";
				}
				if ($this->data['result']->permissions != json_encode($this->input->post('permissions'))) {
					// comparar os arrays
					$arrayActual = (!empty(json_decode($this->data['result']->permissions))) ? json_decode($this->data['result']->permissions) : [];
					$arrayNew = (!empty($this->input->post('permissions'))) ? $this->input->post('permissions') : [];
					$diffNew = array_diff($arrayNew, $arrayActual);
					$diffRem = array_diff($arrayActual, $arrayNew);

					if ((!empty($diffNew)) || (!empty($diffNew))) $message .= "Permissões modificadas: <br/>";

					if (!empty($diffNew)) {
						foreach ($diffNew as $row) {
							$permissionName = $this->global_model->getFieldsWhereSingle_off('crm_user_permissions_items', 'name', ['id' => $row], TRUE)->name;
							$message .= '<i class="mdi mdi-pencil-plus text-success"></i> ' . $row . ' - ' . $permissionName . '<br/>';
						}
					}
					if (!empty($diffRem)) {
						foreach ($diffRem as $row) {
							$permissionName = $this->global_model->getFieldsWhereSingle_off('crm_user_permissions_items', 'name', ['id' => $row], TRUE);
							if (!empty($permissionName)) $message .= '<i class="mdi mdi-pencil-minus text-danger"></i> ' . $row . ' - ' . $permissionName->name . '<br/>';
						}
					}
				}

				if (!empty($message)) {
					# Gravar histórico
					$data = [
						'id_user_permission' => $this->data['result']->id,
						'description' => $message,
						'created' => date("Y-m-d H:i:s"),
						'created_by' => $this->session->userdata('user')->id,
					];
					$this->global_model->add('crm_user_permissions_logs', $data);
				}

				$this->session->set_flashdata('success', 'Registro modificado com sucesso.');
			} else {
				$this->session->set_flashdata('error', 'Houve um erro ao modificar o registro.');
			}
			redirect(base_url('permissoes'));
		}
	}

	public function copiar($id)
	{
		if (empty($id)) redirect(base_url('permissoes'));
		$this->data['result'] = $this->global_model->getWhere_off('crm_user_permissions', array('id' => $id), TRUE);
		if (empty($this->data['result'])) redirect(base_url('permissoes'));

		if ($id == 1) {
			$this->session->set_flashdata('error', 'Houve um erro ao copiar o registro.');
			redirect(base_url('permissoes'));
		}

		$data = array(
			'id_company' => $this->data['result']->id_company,
			'name' => $this->data['result']->name . ' (cópia)',
			'permissions' => $this->data['result']->permissions,
			'id_status' => 1,
			'created' => date("Y-m-d H:i:s"),
			'created_by' => $this->session->userdata('user')->id,
			'modified' => date("Y-m-d H:i:s"),
			'modified_by' => $this->session->userdata('user')->id,
		);

		if ($this->global_model->add('crm_user_permissions', $data) == TRUE) {
			$this->session->set_flashdata('success', 'Registro copiado com sucesso.');
		} else {
			$this->session->set_flashdata('error', 'Houve um erro ao copiar o registro.');
		}
		redirect(base_url('permissoes'));
	}

	public function post_excluir($id_permission)
	{
		$this->output->set_content_type('application/json', 'utf-8');
		# Gravar histórico
		$result = $this->global_model->getWhere_off('crm_user_permissions', array('id' => $id_permission), TRUE);
		$message = '';

		if ($result->permissions != []) {
			// comparar os arrays
			$arrayActual = (!empty(json_decode($result->permissions))) ? json_decode($result->permissions) : [];
			$arrayNew = [];
			$diffNew = array_diff($arrayNew, $arrayActual);
			$diffRem = array_diff($arrayActual, $arrayNew);

			if ((!empty($diffNew)) || (!empty($diffRem))) $message .= "Permissões modificadas: <br/>";
			if (!empty($diffNew)) {
				foreach ($diffNew as $new) {
					$permissionName = $this->global_model->getFieldsWhereSingle_off('crm_user_permissions_items', 'name', ['id' => $new], TRUE)->name;
					$message .= '<i class="mdi mdi-pencil-plus text-success"></i> ' . $new . ' - ' . $permissionName . '<br/>';
				}
			}
			if (!empty($diffRem)) {
				foreach ($diffRem as $rem) {
					$permissionName = $this->global_model->getFieldsWhereSingle_off('crm_user_permissions_items', 'name', ['id' => $rem], TRUE);
					if (!empty($permissionName)) $message .= '<i class="mdi mdi-pencil-minus text-danger"></i> ' . $rem . ' - ' . $permissionName->name . '<br/>';
				}
			}
		}

		if (!empty($message)) {
			# Gravar histórico
			$data = [
				'id_user_permission' => $result->id,
				'description' => $message,
				'created' => date("Y-m-d H:i:s"),
				'created_by' => $this->session->userdata('user')->id,
			];
			$this->global_model->add('crm_user_permissions_logs', $data);
		}

		$data = array(
			'id_status' => 2,
			'permissions' => '[]',
			'modified' => date("Y-m-d H:i:s"),
			'modified_by' => $this->session->userdata('user')->id,
		);

		if ($this->global_model->edit('crm_user_permissions', $data, 'id', $id_permission)) {
			$this->output->set_output(json_encode(array('return' => TRUE, 'message' => 'Registro excluído com sucesso.')));
			$this->session->set_flashdata('success', 'Registro excluído com sucesso.');
		} else {
			$this->output->set_output(json_encode(array('return' => FALSE, 'message' => 'Houve um erro ao excluir o registro.')));
			$this->session->set_flashdata('error', 'Houve um erro ao excluir o registro.');
		}
	}
}
