<?php
class Global_model extends CI_Model
{

  function __construct()
  {
    parent::__construct();
  }

  function uniqueSession($id)
  {
    $count = strlen($id);
    $sess = $this->global_model->getWhere('crm_sessions', "data like concat('%','id_user|s:" . $count . ":\"" . $id . "\";','%')", FALSE);
    foreach ($sess as $x) {
      $replace = str_replace("logado", "unlogged", $x->data);
      $this->edit('sessions', array('data' => $replace), 'id', $x->id);
    }
  }

  function body_email($template, $data)
  {
    $body = $this->load->view('emails/header', $data, TRUE);
    $body = $body . $this->load->view($template, $data, TRUE);
    $body = $body . $this->load->view('emails/footer', $data, TRUE);
    return $body;
  }

  function send_email($title, $body, $to, $cc, $cco, $reply_to, $attach = null)
  {
    $array['title'] = $title;
    $array['body'] = $body;
    $array['to'] = $to;
    $array['cc'] = $cc;
    $array['cco'] = $cco;
    $array['reply_to'] = $reply_to;
    $array['attach'] = $attach;

    $data['parameters'] = json_encode($array);
    $data['service'] = 'EnviarEmail';
    $data['created'] = date("Y-m-d H:i:s");

    if ($this->global_model->add('crm_cron', $data)) {
      return TRUE;
    } else return FALSE;
  }

  function islogged()
  {
    if ($this->session->userdata('logado') !== true) {
      if (current_url() == base_url()) {
        $this->session->set_userdata(array('last_url' => current_url()));
        redirect(base_url() . 'login');
      }

      $this->session->set_userdata(array('last_url' => current_url()));
      redirect(base_url() . 'login?warning=Sua sessão expirou.');
    }
  }

  function checkPermission($alias)
  {
    $active = $this->session->userdata('permissions');
    if (!empty($active)) {
      if (in_array($alias, $active)) return true;
      else return false;
    } else return false;
  }

  ###
  ### Funções específicas para o HEADER
  ###
  function getFinancePending()
  {
    return $this->session->userdata('invoices_pending');
  }

  private function getAbrangencyUser($table)
  {
    if ($this->session->userdata('relateds_units') != 'ALL') {
      if ($table == 'companies' || $table == 'companies_v') {
        // $clausulaWhere = "id in " . $this->session->userdata('relateds_units');
        // $this->db->where($clausulaWhere);
      }
    }
  }

  public function validateDate($date, $format = 'Y-m-d H:i:s')
  {
    $d = DateTime::createFromFormat($format, trim($date));
    return $d && $d->format($format) == $date;
  }

  public function getInsensitiveLikeCondition($field, $value)
  {
    if (!preg_match('/^[a-zA-Z0-9_.]+$/', $field)) {
      throw new InvalidArgumentException('Campo inválido para busca.');
    }

    $value = mb_strtolower(trim((string) $value), 'UTF-8');
    $value = '%' . $this->db->escape_like_str($value) . '%';

    return 'LOWER(' . $field . ') LIKE ' . $this->db->escape($value) . " ESCAPE '!'";
  }

  public function likeInsensitive($field, $value, $or = FALSE)
  {
    $condition = $this->getInsensitiveLikeCondition($field, $value);

    if ($or) {
      $this->db->or_where($condition, NULL, FALSE);
      return;
    }

    $this->db->where($condition, NULL, FALSE);
  }

  private function getFilter($table, $filter, $where_in)
  {
    foreach ($this->session->userdata($filter) as $field => $value) {
      if (!empty($this->session->userdata($filter)[$field])) {
        if ($field == 'keyword' || $field == 'keyword_search' || $field == 'keyword_ids' || $field == 'select2_companies') {
          if ($field == 'keyword') {
            $key = trim($this->session->userdata($filter)['keyword']);
            $this->db->group_start();
            $this->likeInsensitive('id', $key);
            foreach ($this->session->userdata($filter)['keyword_search'] as $c) {
              $this->likeInsensitive($c, $key, TRUE);
            }

            // `keyword_ids` é opcional: uma lista de ids que a tela já resolveu
            // por conta própria, para a palavra-chave casar também com dado de
            // OUTRA tabela (na listagem de clientes, o domínio dos contratos)
            // sem que o Global_model precise conhecer esse relacionamento.
            //
            // Entra DENTRO do grupo, como mais um OR: a mesma condição posta
            // fora viraria AND com os LIKE de nome/documento e a busca por um
            // domínio — que não casa nenhum campo do cliente — devolveria
            // sempre vazio. A chave carrega SÓ inteiros (forçados aqui), nunca
            // SQL vindo do controller.
            $ids = $this->session->userdata($filter);
            $ids = (isset($ids['keyword_ids']) && is_array($ids['keyword_ids'])) ? array_map('intval', $ids['keyword_ids']) : [];
            if (!empty($ids)) $this->db->or_where_in('id', $ids);

            $this->db->group_end();
          }
        } elseif ($field == 'modified' || $field == 'created') {
          $explode = explode('-', $this->session->userdata($filter)[$field]);
          if ($this->validateDate(trim($explode[0]), 'd/m/Y') && $this->validateDate(trim($explode[1]), 'd/m/Y')) :
            $data1 = preg_replace("/[^0-9]/", "", explode("/", trim($explode[0])));
            $data1 = $data1[2] . "-" . $data1[1] . "-" . $data1[0] . " 00:00:00";
            $data2 = preg_replace("/[^0-9]/", "", explode("/", trim($explode[1])));
            $data2 = $data2[2] . "-" . $data2[1] . "-" . $data2[0] . " 23:59:59";
            $where = $field . " between CAST('" . $data1 . "' AS DATETIME) and CAST('" . $data2 . "' AS DATETIME)";
            $this->db->where($where);
          endif;
        } else {
          if (is_array($value)) {
            $this->db->group_start();
            $this->db->where_in($field, $value);
            $this->db->group_end();
          } else $this->db->where([$field => $value]);
        }
      }
    }

    if ($where_in) $this->insertWhereInClausule($table);
  }

  private function insertWhereInClausule($table)
  {
  }

  function getList($table, $filter, $order_field, $order_by, $perpage, $start, $where_in = true)
  {
    $this->db->from($table);
    $this->getFilter($table, $filter, $where_in);
    $this->getAbrangencyUser($table);
    $this->db->limit($perpage, $start);
    $this->db->order_by($order_field, $order_by);
    $query = $this->db->get();
    $result = $query->result();
    return $result;
  }

  function getListW($where, $table, $filter, $order_field, $order_by, $perpage, $start, $where_in = true)
  {
    $this->db->from($table);
    $this->db->where($where);
    $this->getFilter($table, $filter, $where_in);
    $this->getAbrangencyUser($table);
    $this->db->limit($perpage, $start);
    $this->db->order_by($order_field, $order_by);
    $query = $this->db->get();
    $result = $query->result();
    return $result;
  }

  function getCount($table, $filter, $where_in = true)
  {
    $this->getFilter($table, $filter, $where_in);
    $this->getAbrangencyUser($table);
    return $this->db->count_all_results($table);
  }

  function getCountW($where, $table, $filter, $where_in = true)
  {
    $this->db->where($where);
    $this->getFilter($table, $filter, $where_in);
    $this->getAbrangencyUser($table);
    return $this->db->count_all_results($table);
  }

  function getSum($table, $field, $filter, $where_in = true)
  {
    $this->getFilter($table, $filter, $where_in);
    $this->getAbrangencyUser($table);
    $this->db->select_sum($field);
    $query = $this->db->get($table);
    return $query->row();
  }

  function getSumW($where, $table, $field, $filter, $where_in = true)
  {
    $this->db->where($where);
    $this->getFilter($table, $filter, $where_in);
    $this->getAbrangencyUser($table);
    $this->db->select_sum($field);
    $query = $this->db->get($table);
    return $query->row();
  }

  function getSumGroupByOrderBy($table, $filter, $field, $groupby, $order, $by, $where_in = true)
  {
    $this->getFilter($table, $filter, $where_in);
    $this->getAbrangencyUser($table);
    $this->db->select($field);
    $this->db->group_by($groupby);
    $this->db->order_by($order, $by);
    $query = $this->db->get($table);
    $result = $query->result();
    return $result;
  }

  ###
  ### Funções DEFAULT
  ###

  function getWhere($table, $array, $one = false)
  {
    $this->db->where($array);
    $this->insertWhereInClausule($table);
    $this->getAbrangencyUser($table);
    $query = $this->db->get($table);
    $result =  !$one  ? $query->result() : $query->row();
    return $result;
  }

  function getWhere_off($table, $array, $one = false)
  {
    $this->db->where($array);
    $query = $this->db->get($table);
    $result =  !$one  ? $query->result() : $query->row();
    return $result;
  }

  function countWhere($table, $array)
  {
    $this->db->where($array);
    $this->insertWhereInClausule($table);
    $this->getAbrangencyUser($table);
    return $this->db->count_all_results($table);
  }

  function countWhere_off($table, $array)
  {
    $this->db->where($array);
    return $this->db->count_all_results($table);
  }

  function delete($table, $fieldID, $ID)
  {
    $this->db->where($fieldID, $ID);
    $this->db->delete($table);
    if ($this->db->affected_rows() == '1') {
      return TRUE;
    }
    return FALSE;
  }

  function getFieldsWhereSingle_off($table, $fields, $array, $one = false)
  {
    $this->db->select($fields);
    $this->db->where($array);
    $query = $this->db->get($table);
    $result =  !$one  ? $query->result() : $query->row();
    return $result;
  }

  function getFieldsWhere_off($table, $fields, $array, $order, $by, $one = false)
  {
    $this->db->select($fields);
    $this->db->where($array);
    $this->db->order_by($order, $by);
    $query = $this->db->get($table);
    $result =  !$one  ? $query->result() : $query->row();
    return $result;
  }

  function getFieldsWhere($table, $fields, $array, $order, $by, $one = false)
  {
    $this->db->select($fields);
    $this->db->where($array);
    $this->insertWhereInClausule($table);
    $this->getAbrangencyUser($table);
    $this->db->order_by($order, $by);
    $query = $this->db->get($table);
    $result =  !$one  ? $query->result() : $query->row();
    return $result;
  }

  function getWhereOrderBy($table, $array, $field, $value, $one = false)
  {
    $this->db->where($array);
    $this->insertWhereInClausule($table);
    $this->getAbrangencyUser($table);
    $this->db->order_by($field, $value);
    $query = $this->db->get($table);
    $result =  !$one  ? $query->result() : $query->row();
    return $result;
  }

  function getWhereOrderBy_off($table, $array, $field, $value, $one = false)
  {
    $this->db->where($array);
    $this->db->order_by($field, $value);
    $query = $this->db->get($table);
    $result =  !$one  ? $query->result() : $query->row();
    return $result;
  }

  function getWhereLimit($table, $array, $limit, $one = false)
  {
    $this->db->where($array);
    $this->insertWhereInClausule($table);
    $this->getAbrangencyUser($table);
    $this->db->limit($limit);
    $query = $this->db->get($table);
    $result =  !$one  ? $query->result() : $query->row();
    return $result;
  }

  function getWhereLimit_off($table, $array, $limit, $one = false)
  {
    $this->db->where($array);
    $this->db->limit($limit);
    $query = $this->db->get($table);
    $result =  !$one  ? $query->result() : $query->row();
    return $result;
  }

  function getWhereOrderByLimit($table, $array, $field, $value, $limit, $one = false)
  {
    $this->db->where($array);
    $this->insertWhereInClausule($table);
    $this->getAbrangencyUser($table);
    $this->db->order_by($field, $value);
    $this->db->limit($limit);
    $query = $this->db->get($table);
    $result =  !$one  ? $query->result() : $query->row();
    return $result;
  }

  function getWhereOrderByLimit_off($table, $array, $field, $value, $limit, $one = false)
  {
    $this->db->where($array);
    $this->db->order_by($field, $value);
    $this->db->limit($limit);
    $query = $this->db->get($table);
    $result =  !$one  ? $query->result() : $query->row();
    return $result;
  }

  function getFieldsWhereOrderByLimit_off($table, $fields, $array, $field, $value, $limit, $one = false)
  {
    $this->db->select($fields);
    $this->db->from($table);
    $this->db->where($array);
    $this->db->limit($limit);
    $this->db->order_by($field, $value);
    $query = $this->db->get();
    $result =  !$one  ? $query->result() : $query->row();
    return $result;
  }

  function getFieldsWhereOrderByLimit($table, $fields, $array, $field, $value, $limit, $one = false)
  {
    $this->db->select($fields);
    $this->db->where($array);
    $this->insertWhereInClausule($table);
    $this->getAbrangencyUser($table);
    $this->db->order_by($field, $value);
    $this->db->limit($limit);
    $query = $this->db->get($table);
    $result =  !$one  ? $query->result() : $query->row();
    return $result;
  }

  function getWhereDistinct($table, $array, $distinct, $one = false)
  {
    $this->db->select($distinct);
    $this->db->distinct();
    $this->db->where($array);
    $this->insertWhereInClausule($table);
    $this->getAbrangencyUser($table);
    $query = $this->db->get($table);
    $result =  !$one  ? $query->result() : $query->row();
    return $result;
  }

  function getWhereDistinct_off($table, $array, $distinct, $one = false)
  {
    $this->db->select($distinct);
    $this->db->distinct();
    $this->db->where($array);
    $query = $this->db->get($table);
    $result =  !$one  ? $query->result() : $query->row();
    return $result;
  }

  function getWhereDistinctOrderBy_off($table, $array, $distinct, $order, $by, $one = false)
  {
    $this->db->select($distinct);
    $this->db->distinct();
    $this->db->where($array);
    $this->db->order_by($order, $by);
    $query = $this->db->get($table);
    $result =  !$one  ? $query->result() : $query->row();
    return $result;
  }

  function getSumWhereGroupByOrderByLimit_off($table, $where, $field, $groupby, $order, $by, $limit, $one = false)
  {
    $this->db->select($field);
    $this->db->where($where);
    $this->db->group_by($groupby);
    $this->db->order_by($order, $by);
    $this->db->limit($limit);
    $query = $this->db->get($table);
    $result =  !$one  ? $query->result() : $query->row();
    return $result;
  }

  function getSumWhere($table, $where, $field, $one = false)
  {
    $this->db->where($where);
    $this->insertWhereInClausule($table);
    $this->getAbrangencyUser($table);
    $this->db->select_sum($field);
    $query = $this->db->get($table);
    $result = !$one  ? $query->result() : $query->row();
    return $result;
  }

  function getSumWhere_off($table, $where, $field, $one = false)
  {
    $this->db->where($where);
    $this->db->select_sum($field);
    $query = $this->db->get($table);
    $result = !$one  ? $query->result() : $query->row();
    return $result;
  }

  function getSumWhereLimit_off($table, $where, $field, $limit, $one = false)
  {
    $this->db->where($where);
    $this->db->select_sum($field);
    $this->db->limit($limit);
    $query = $this->db->get($table);
    $result = !$one  ? $query->result() : $query->row();
    return $result;
  }

  function getCountWhere($table, $where, $one = false)
  {
    $this->db->where($where);
    $this->insertWhereInClausule($table);
    $this->getAbrangencyUser($table);
    return $this->db->count_all_results($table);
  }

  function getCountWhere_off($table, $where, $one = false)
  {
    $this->db->where($where);
    return $this->db->count_all_results($table);
  }

  function edit($table, $array, $fieldID, $ID)
  {
    $this->db->where($fieldID, $ID);
    $this->db->update($table, $array);
    if ($this->db->affected_rows() >= 0) {
      return TRUE;
    }
    return FALSE;
  }

  function editwhere($table, $array, $where)
  {
    $this->db->where($where);
    $this->db->update($table, $array);
    if ($this->db->affected_rows() >= 0) {
      return TRUE;
    }
    return FALSE;
  }

  function add($table, $data)
  {
    $this->db->insert($table, $data);
    $insertId = (int) $this->db->insert_id();

    if ($insertId > 0) {
      return $insertId;
    }

    if ($this->db->affected_rows() >= 1) {
      return TRUE;
    }

    return FALSE;
  }
}
