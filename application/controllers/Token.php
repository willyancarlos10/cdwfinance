<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Token extends MY_Controller
{
  public function __construct()
  {
    parent::__construct();
  }

  public function tokenByRedirectOld($token = null)
  {
    if (empty($token)) {
      $this->session->set_flashdata('error', 'Não foi possível ativar a integração na conta do OLX.');
      redirect(base_url('portais'));
    }

    $crm_portals = $this->global_model->getWhere_off("crm_portals", ['alias' => 'olx'], TRUE);
    $crm_companies_portals = $this->global_model->getWhere_off("crm_companies_portals", ['id_portal' => $crm_portals->id, 'id_company' => $this->session->userdata('f_company')['id_company']], TRUE);
    if (empty($crm_companies_portals)) {
      $array = [
        'id_company' => $this->session->userdata('f_company')['id_company'],
        'id_portal' => $crm_portals->id,
        'id_status' => 1,
        'parameters' => json_encode(['access_token' => $token]),
        'created' => date("Y-m-d H:i:s"),
        'created_by' => $this->session->userdata('user')->id,
      ];
      $this->global_model->add('crm_companies_portals', $array);
    } else {
      $array = [
        'id_status' => 1,
        'parameters' => json_encode(['access_token' => $token]),
        'modified' => date("Y-m-d H:i:s"),
        'modified_by' => $this->session->userdata('user')->id,
      ];
      $this->global_model->edit('crm_companies_portals', $array, 'id', $crm_companies_portals->id);
    }

    $this->session->set_flashdata('success', 'Configuração realizada com sucesso em breve seu estoque será sincronizado.');
    redirect(base_url('portais'));
  }

  public function index()
  {
    // Recebe retorno OLX
    if (!empty($_GET['code'])) {
      // Com o código de acesso, iremos obter o access_token da empresa que dá acesso à API
      $fields = [
        'code' => $_GET['code'],
        'client_id' => $this->config->item('olx_client_id'),
        'client_secret' => $this->config->item('olx_client_secret'),
        // 'redirect_uri' => $this->config->item('olx_redirect_uri'),
        'redirect_uri' => 'https://novo.gestorcar.com.br/token',
        'grant_type' => 'authorization_code'
      ];

      $ch = curl_init("https://auth.olx.com.br/oauth/token");
      curl_setopt($ch, CURLOPT_POST, 1);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      // Sem timeout a request fica pendurada segurando o lock de sessão até o
      // teto do PHP, e morre no meio de um comando do MySQL.
      curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
      curl_setopt($ch, CURLOPT_TIMEOUT, 20);
      $response = curl_exec($ch);
      $response = json_decode($response);
      curl_close($ch);

      if (!empty($response->access_token)) {
        $portal = $this->global_model->getWhere_off("crm_portals", ['alias' => 'olx'], TRUE);

        $crm_companies_portals = $this->global_model->getWhere_off("crm_companies_portals", ['id_portal' => $portal->id, 'id_company' => $this->session->userdata('f_company')['id_company']], TRUE);
        if (empty($crm_companies_portals)) {
          $array = [
            'id_company' => $this->session->userdata('f_company')['id_company'],
            'id_portal' => $portal->id,
            'id_status' => 1,
            'parameters' => json_encode(['access_token' => $response->access_token]),
            'created' => date("Y-m-d H:i:s"),
            'created_by' => $this->session->userdata('user')->id,
          ];
          $this->global_model->add('crm_companies_portals', $array);
        } else {
          $array = [
            'id_status' => 1,
            'parameters' => json_encode(['access_token' => $response->access_token]),
            'modified' => date("Y-m-d H:i:s"),
            'modified_by' => $this->session->userdata('user')->id,
          ];
          $this->global_model->edit('crm_companies_portals', $array, 'id', $crm_companies_portals->id);
        }

        // Disparar email para o Will
        $crm_companies = $this->global_model->getWhere_off('crm_companies', ['id' => $this->session->userdata('f_company')['id_company']], TRUE);
        $this->data['company'] = $crm_companies;
        $this->data['portal'] = $portal;
        $this->data['user'] = $this->global_model->getWhere_off('crm_users', ['id' => $this->session->userdata('user')->id], TRUE);
        $this->data['title'] = "Integração com portal $portal->name ativado";
        $body = $this->global_model->body_email("emails/portalmodify", $this->data);
        $this->global_model->send_email($this->data['title'], $body, "willyan@metisagenciadigital.com.br", NULL, NULL, NULL);

        $this->session->set_flashdata('success', 'Configuração realizada com sucesso em breve seu estoque será sincronizado.');
        redirect(base_url('portais'));
      } else {
        $this->session->set_flashdata('error', 'Não foi possível ativar a integração na conta do OLX. Código do erro: ' . $response->error . ' entre em contato com seu operador OLX.');
        redirect(base_url('portais'));
      }
    } else {
      $this->session->set_flashdata('error', 'Não foi possível ativar a integração na conta do OLX.');
      redirect(base_url('portais'));
    }
  }

  public function ml()
  {
    // Recebe retorno Mercado Livre
    if (!empty($_GET)) {
      if (!empty($_GET['code']) && !empty($_GET['state'])) {

        $response = $this->mercadolivre_model->getToken($_GET['code']);
        if (!empty($response->access_token)) {
          $portal = $this->global_model->getWhere_off("crm_portals", ['alias' => 'mlv'], TRUE);

          $parameters = json_encode(['access_token' => $response->access_token, 'refresh_token' => $response->refresh_token, 'expires_in' => $response->expires_in, 'user_id' => $response->user_id]);

          $crm_companies_portals = $this->global_model->getWhere_off("crm_companies_portals", ['id_portal' => $portal->id, 'id_company' => $_GET['state']], TRUE);
          if (empty($crm_companies_portals)) {
            $array = [
              'id_company' => $_GET['state'],
              'id_portal' => $portal->id,
              'id_status' => 1,
              'parameters' => $parameters,
              'created' => date("Y-m-d H:i:s"),
              'created_by' => $this->session->userdata('user')->id,
            ];
            $this->global_model->add('crm_companies_portals', $array);
          } else {
            $array = [
              'id_status' => 1,
              'parameters' => $parameters,
              'modified' => date("Y-m-d H:i:s"),
              'modified_by' => $this->session->userdata('user')->id,
            ];
            $this->global_model->edit('crm_companies_portals', $array, 'id', $crm_companies_portals->id);
          }

          // Disparar email para o Will
          $crm_companies = $this->global_model->getWhere_off('crm_companies', ['id' => $_GET['state']], TRUE);
          $this->data['company'] = $crm_companies;
          $this->data['portal'] = $portal;
          $this->data['user'] = $this->global_model->getWhere_off('crm_users', ['id' => $this->session->userdata('user')->id], TRUE);
          $this->data['title'] = "Integração com portal $portal->name ativado";
          $body = $this->global_model->body_email("emails/portalmodify", $this->data);
          $this->global_model->send_email($this->data['title'], $body, "willyan@metisagenciadigital.com.br", NULL, NULL, NULL);

          $this->session->set_flashdata('success', 'Configuração realizada com sucesso em breve seu estoque será sincronizado.');
          redirect(base_url('portais'));
        } else {
          $this->session->set_flashdata('error', 'Não foi possível ativar a integração na conta do Mercado Livre: ' . $response->error_description . ' entre em contato com seu operador.');
          redirect(base_url('portais'));
        }
      } else {
        $this->session->set_flashdata('error', 'Não foi possível ativar a integração na conta do Mercado Livre.');
        redirect(base_url('portais'));
      }
    } else {
      $this->session->set_flashdata('error', 'Não foi possível ativar a integração na conta do Mercado Livre.');
      redirect(base_url('portais'));
    }
  }
}
