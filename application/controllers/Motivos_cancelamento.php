<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Motivos de cancelamento (GESTÃO) — catálogo global, sem id_company.
 *
 * Alimenta o select do modal de encerramento (Contratos::endReasons) e o card
 * de cancelamentos do Dashboard. Como em Tipos de serviços, o cadastro é único
 * e administrado só pela empresa master.
 *
 * URLs com hífen resolvem sem rota: translate_uri_dashes = TRUE em
 * config/routes.php ('motivos-cancelamento' -> Motivos_cancelamento).
 */
class Motivos_cancelamento extends MY_Controller
{
  /** Teto do slug, igual ao da coluna. */
  const TAMANHO_SLUG = 30;

  public function __construct()
  {
    parent::__construct();

    if ($this->session->userdata('company')->id != 1) {
      $this->session->set_flashdata('warning', 'Sem permissão de acesso.');
      redirect(base_url());
    }
    $this->data['menu'] = 'motivos-cancelamento';
  }

  /**
   * Paleta aceita para a fatia do gráfico.
   *
   * É allowlist, e não campo livre, por segurança: a cor entra no Dashboard
   * como `background-color` inline e dentro do array `colors` do ApexCharts —
   * texto arbitrário vindo daqui viraria injeção de CSS/JS na tela inicial. Os
   * hex são os da paleta do próprio tema (theme/css/light.css), para o card não
   * destoar do resto do painel.
   *
   * @return array hex => rótulo
   */
  private function paleta()
  {
    return [
      '#3f80ea' => 'Azul',
      '#1f9bcf' => 'Ciano',
      '#20c997' => 'Turquesa',
      '#4bbf73' => 'Verde',
      '#e5a54b' => 'Âmbar',
      '#fd7e14' => 'Laranja',
      '#d9534f' => 'Vermelho',
      '#e83e8c' => 'Rosa',
      '#6f42c1' => 'Roxo',
      '#6c757d' => 'Cinza',
    ];
  }

  /**
   * Gera o slug a partir do nome.
   *
   * O `iconv(...//TRANSLIT)` NÃO serve aqui: neste build do PHP ele devolve
   * lixo ("Inadimplência" vira "inadimpl_encia" e "Não renovação / fim" vira
   * "o_fim"), o que produziria slugs ilegíveis — e slug é imutável, então o
   * estrago seria permanente. Daí o mapa explícito.
   *
   * @param  string $nome
   * @return string
   */
  private function gerarSlug($nome)
  {
    $de   = ['á', 'à', 'ã', 'â', 'ä', 'é', 'è', 'ê', 'ë', 'í', 'ì', 'î', 'ï', 'ó', 'ò', 'õ', 'ô', 'ö', 'ú', 'ù', 'û', 'ü', 'ç', 'ñ'];
    $para = ['a', 'a', 'a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'c', 'n'];

    $slug = str_replace($de, $para, mb_strtolower(trim((string) $nome), 'UTF-8'));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);

    return substr(trim($slug, '_'), 0, self::TAMANHO_SLUG);
  }

  /**
   * Garante slug único; o nome já é único, mas dois nomes diferentes podem
   * reduzir ao mesmo slug ("Fim do prazo" e "Fim do prazo!").
   *
   * @param  string $base
   * @return string|bool FALSE quando não sobrou nada utilizável
   */
  private function slugDisponivel($base)
  {
    if ($base === '') return FALSE;

    $slug = $base;
    $sufixo = 2;
    while (!empty($this->global_model->getWhere_off('crm_end_reasons', ['slug' => $slug], TRUE))) {
      $sufixo_txt = '_' . $sufixo;
      $slug = substr($base, 0, self::TAMANHO_SLUG - strlen($sufixo_txt)) . $sufixo_txt;
      $sufixo++;

      // Trava de segurança: sem ela, uma condição inesperada viraria laço infinito.
      if ($sufixo > 99) return FALSE;
    }

    return $slug;
  }

  /**
   * Campos comuns a criar e editar, já validados.
   *
   * @return array
   */
  private function camposDoPost()
  {
    $cor = (string) $this->input->post('color');
    $paleta = $this->paleta();

    return [
      'name' => mb_substr(trim((string) $this->input->post('name')), 0, 150),
      'color' => isset($paleta[$cor]) ? $cor : '#6c757d',
      'sort_order' => (int) $this->input->post('sort_order'),
    ];
  }

  public function index()
  {
    $this->form_validation->set_rules('name', '', 'required|trim|max_length[150]');
    if ($this->form_validation->run() == FALSE) {
      $this->data['crm_end_reasons'] = $this->global_model->getWhereOrderBy_off('crm_end_reasons_v', "1=1", 'sort_order', 'asc', FALSE);
      $this->data['paleta'] = $this->paleta();
      $this->load->view('header', $this->data);
      $this->load->view('end_reasons/list', $this->data);
      $this->load->view('footer', $this->data);
    } else {
      $campos = $this->camposDoPost();

      // UNIQUE(name) no banco; a checagem aqui só troca o erro seco do MySQL
      // por uma mensagem amigável na tela.
      $existente = $this->global_model->getWhere_off('crm_end_reasons', ['name' => $campos['name']], TRUE);
      if (!empty($existente)) {
        $this->session->set_flashdata('warning', 'Já existe um motivo com este nome.');
        redirect(base_url('motivos-cancelamento'));
      }

      $slug = $this->slugDisponivel($this->gerarSlug($campos['name']));
      if ($slug === FALSE) {
        $this->session->set_flashdata('error', 'Não foi possível gerar um identificador para este nome. Use letras e números.');
        redirect(base_url('motivos-cancelamento'));
      }

      $this->global_model->add('crm_end_reasons', array_merge($campos, [
        'slug' => $slug,
        'id_status' => 1,
        'created' => date('Y-m-d H:i:s'),
        'created_by' => $this->session->userdata('user')->id,
        'modified' => date('Y-m-d H:i:s'),
        'modified_by' => $this->session->userdata('user')->id,
      ]));

      $this->session->set_flashdata('success', 'Processamento realizado com sucesso.');
      redirect(base_url('motivos-cancelamento'));
    }
  }

  public function editar()
  {
    $this->form_validation->set_rules('name', '', 'required|trim|max_length[150]');
    if ($this->form_validation->run() == FALSE) {
      $id = 0;
      if (!empty($_GET['id'])) $id = (int) $_GET['id'];

      $this->data['result'] = $this->global_model->getWhere_off('crm_end_reasons_v', ['id' => $id], TRUE);
      if (empty($this->data['result'])) {
        $this->session->set_flashdata('warning', 'Registro não encontrado.');
        redirect(base_url('motivos-cancelamento'));
      }

      $this->data['paleta'] = $this->paleta();
      $this->load->view('header', $this->data);
      $this->load->view('end_reasons/edit', $this->data);
      $this->load->view('footer', $this->data);
    } else {
      $id = (int) $this->input->post('id');
      $campos = $this->camposDoPost();

      $existente = $this->global_model->getWhere_off('crm_end_reasons', ['name' => $campos['name']], TRUE);
      if (!empty($existente) && (int) $existente->id !== $id) {
        $this->session->set_flashdata('warning', 'Já existe um motivo com este nome.');
        redirect(base_url('motivos-cancelamento/editar?id=' . $id));
      }

      // O `slug` NÃO entra no update: é o valor gravado em
      // crm_contracts.ended_reason, e trocá-lo órfãnaria os contratos já
      // encerrados com este motivo. Renomear o rótulo é livre — o histórico
      // reetiqueta junto, que é o comportamento desejado.
      $this->global_model->edit('crm_end_reasons', array_merge($campos, [
        'modified' => date('Y-m-d H:i:s'),
        'modified_by' => $this->session->userdata('user')->id,
      ]), 'id', $id);

      $this->session->set_flashdata('success', 'Processamento realizado com sucesso.');
      redirect(base_url('motivos-cancelamento'));
    }
  }

  public function post_excluir()
  {
    $id = (int) $this->input->post('id');
    $motivo = $this->global_model->getWhere_off('crm_end_reasons', ['id' => $id], TRUE);

    if (empty($motivo)) {
      $this->session->set_flashdata('warning', 'Registro não encontrado.');
      redirect(base_url('motivos-cancelamento'));
    }

    // Não há FK protegendo o vínculo (o contrato guarda o SLUG, de propósito —
    // ver a migration 032), então a guarda é aqui: apagar o motivo faria o
    // contrato encerrado perder o rótulo e o card do Dashboard passar a
    // exibir o slug cru.
    $emUso = $this->global_model->countWhere_off('crm_contracts', ['ended_reason' => $motivo->slug]);
    if ($emUso > 0) {
      $this->session->set_flashdata('error', 'Este motivo está em ' . $emUso . ' contrato(s) encerrado(s) e não pode ser excluído. Inative-o se não deve mais ser usado.');
      redirect(base_url('motivos-cancelamento'));
    }

    $this->global_model->delete('crm_end_reasons', 'id', $id);

    $this->session->set_flashdata('success', 'Motivo de cancelamento excluído com sucesso.');
    redirect(base_url('motivos-cancelamento'));
  }
}
