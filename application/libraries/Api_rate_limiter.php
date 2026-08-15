<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Api_rate_limiter
{
  const REQUESTS_PER_MINUTE = 120;

  protected $CI;

  public function __construct()
  {
    $this->CI =& get_instance();
  }

  public function consume($apiKeyId)
  {
    $window = date('Y-m-d H:i:00');
    $apiKeyId = (int) $apiKeyId;

    $this->CI->db->query(
      'INSERT IGNORE INTO crm_api_rate_limits (id_api_key, window_started, request_count) VALUES (?, ?, 1)',
      [$apiKeyId, $window]
    );

    if ($this->CI->db->affected_rows() === 1) {
      return TRUE;
    }

    $this->CI->db->query(
      'UPDATE crm_api_rate_limits SET request_count = request_count + 1 WHERE id_api_key = ? AND window_started = ? AND request_count < ?',
      [$apiKeyId, $window, self::REQUESTS_PER_MINUTE]
    );

    return $this->CI->db->affected_rows() === 1;
  }

  public function retryAfter()
  {
    return max(1, 60 - (int) date('s'));
  }
}
