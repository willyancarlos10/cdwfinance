<?php

defined('BASEPATH') or exit('No direct script access allowed');

class General_settings_model extends CI_Model
{
  private static $cache = [];

  public function getGroup($group)
  {
    if (isset(self::$cache[$group])) {
      return self::$cache[$group];
    }

    $rows = $this->global_model->getWhereOrderBy_off(
      'crm_general_settings',
      ['setting_group' => $group],
      'setting_key',
      'asc',
      FALSE
    );

    $settings = [];
    foreach ($rows as $row) {
      $settings[$row->setting_key] = $row->setting_value;
    }

    self::$cache[$group] = $settings;
    return $settings;
  }

  public function getGroupValue($group, $key, $default = null)
  {
    $settings = $this->getGroup($group);
    return array_key_exists($key, $settings) ? $settings[$key] : $default;
  }

  /**
   * Metadados (quando/por quem) de um parâmetro. O getGroup devolve só os
   * valores, e a tela da API Ninjas precisa dizer QUANDO a chave foi gravada:
   * a chave nunca volta para o navegador, então a data é a única confirmação
   * visível de que o salvamento funcionou.
   *
   * @param  string $group
   * @param  string $key
   * @return object|null
   */
  public function getSettingMeta($group, $key)
  {
    $row = $this->global_model->getWhere_off(
      'crm_general_settings',
      ['setting_group' => $group, 'setting_key' => $key],
      TRUE
    );

    return !empty($row) ? $row : NULL;
  }

  public function saveGroup($group, array $values, $idUser)
  {
    $idUser = (int) $idUser;
    $now = date('Y-m-d H:i:s');

    foreach ($values as $key => $value) {
      $existing = $this->global_model->getWhere_off(
        'crm_general_settings',
        ['setting_group' => $group, 'setting_key' => $key],
        TRUE
      );

      $payload = [
        'setting_value' => $value,
        'modified' => $now,
        'modified_by' => $idUser,
      ];

      if (!empty($existing)) {
        $this->global_model->edit(
          'crm_general_settings',
          $payload,
          'id',
          (int) $existing->id
        );
      } else {
        $payload['setting_group'] = $group;
        $payload['setting_key'] = $key;
        $this->global_model->add('crm_general_settings', $payload);
      }
    }

    unset(self::$cache[$group]);
    return TRUE;
  }

  public function applyEmailConfig()
  {
    if (!$this->db->table_exists('crm_general_settings')) {
      return;
    }

    $settings = $this->getGroup('email');

    $map = [
      'mail_active' => 'mail_active',
      'mail_service_type' => 'mail_service_type',
      'mail_smtp_host' => 'mail_smtp_host',
      'mail_smtp_port' => 'mail_smtp_port',
      'mail_sender' => 'mail_sender',
      'mail_smtp_pass' => 'mail_smtp_pass',
      'brevo_api_key' => 'brevo_api_key',
    ];

    foreach ($map as $key => $configKey) {
      if (array_key_exists($key, $settings) && $settings[$key] !== null && $settings[$key] !== '') {
        $this->config->set_item($configKey, $settings[$key]);
      }
    }

    $this->config->set_item('mail_active', $this->isMailActive());
  }

  public function isMailActive()
  {
    $settings = $this->getGroup('email');
    $mailActive = $settings['mail_active'] ?? '0';

    return $mailActive === '1' || $mailActive === 1 || $mailActive === TRUE;
  }

  public function getSmtpCredentials()
  {
    $settings = $this->getGroup('email');
    $serviceType = $settings['mail_service_type'] ?? 'smtp_local';

    return [
      'mail_sender' => trim($settings['mail_sender'] ?? ''),
      'smtp_host' => trim($settings['mail_smtp_host'] ?? ''),
      'smtp_port' => (int) ($settings['mail_smtp_port'] ?? 587),
      'smtp_pass' => $serviceType === 'brevo'
        ? trim($settings['brevo_api_key'] ?? '')
        : trim($settings['mail_smtp_pass'] ?? ''),
      'service_type' => $serviceType,
    ];
  }
}
