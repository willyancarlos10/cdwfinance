<?php
class Image_Model extends MY_Model
{
	/**
	 * Motivo legível da última falha de upload, para o controller exibir uma
	 * mensagem específica na tela. Vazio = sem falha.
	 *
	 * O envio por FTP/SFTP para a hospedagem do cliente foi removido junto com
	 * o CMS: as credenciais ficavam em crm_companies_sites, tabela que não
	 * existe mais. O upload agora grava apenas em images/ neste servidor.
	 *
	 * @var string
	 */
	public $last_error = '';

	function  __construct()
	{
		parent::__construct();
		$this->load->library('image_lib');
		require_once(APPPATH . '/libraries/wideimage/WideImage.php');
	}

	/**
	 * URL pública do arquivo gravado por x_do_upload_all() /
	 * x_do_upload_multiple_all(), que devolvem o caminho relativo à raiz
	 * (ex.: images/media/editorial/2026/08/arquivo.jpg).
	 *
	 * @param string $file
	 * @return string URL absoluta, ou '' quando não há arquivo
	 */
	public function get_url($file)
	{
		$file = ltrim((string) $file, '/');
		return $file === '' ? '' : base_url($file);
	}

	// Upload unitário de arquivo
	function x_do_upload_all($field, $folder, $allowed = array("jpg", "jpeg", "gif", "png", "zip", "rar", "xls", "xlsx", "doc", "docx", "pdf"), $resize = false)
	{
		### Verifica se tem a pasta, senão cria
		$path = 'images/' . $folder . '/';
		if (!$this->prepare_upload_dir($path)) {
			return NULL;
		}

		if (!empty($_FILES)) {
			$type = explode('.', $_FILES["$field"]["name"]);
			$type = end($type);
			$type = mb_strtolower($type);
			$url = "images/$folder/" . uniqid(rand()) . '.' . $type;

			if (in_array($type, $allowed)) {
				if (is_uploaded_file($_FILES["$field"]["tmp_name"])) {
					if (move_uploaded_file($_FILES["$field"]["tmp_name"], $url)) {
						if ($resize) {
							$url = $this->png_to_jpg($url, $type);

							// $image = WideImage::load($url);
							// $image = $image->resize(1280, 900);
							// $image->saveToFile($url, 92);
						}

						return $url;
					}
				}
			}
		}
		return NULL;
	}

	/**
	 * Garante que a pasta de destino exista e tenha o index.html de proteção.
	 * O segundo is_dir() cobre a corrida entre uploads simultâneos (colar várias
	 * imagens no editor dispara XHRs em paralelo e o mkdir do segundo falharia).
	 *
	 * @param string $path
	 * @return bool
	 */
	private function prepare_upload_dir($path)
	{
		if (!is_dir($path) && !@mkdir($path, 0755, TRUE) && !is_dir($path)) {
			log_message('error', '[Upload] não foi possível criar a pasta de destino: ' . $path);
			return FALSE;
		}

		$original = 'images/index.html';
		$copia = $path . 'index.html';
		if (file_exists($original) && !file_exists($copia)) {
			@copy($original, $copia);
		}

		return TRUE;
	}

	/**
	 * Converte PNG em JPG com fundo branco. Em qualquer falha (arquivo corrompido,
	 * memória insuficiente) devolve o arquivo original intacto em vez de apagá-lo
	 * e retornar um .jpg inexistente.
	 *
	 * @param string $url
	 * @param string $type extensão já normalizada em minúsculas
	 * @param int    $quality 0 = pior/menor arquivo, 100 = melhor/maior arquivo
	 * @return string caminho final do arquivo
	 */
	private function png_to_jpg($url, $type, $quality = 90)
	{
		if ($type !== 'png') {
			return $url;
		}

		$image = @imagecreatefrompng($url);
		if ($image === FALSE) {
			log_message('error', '[Upload] PNG ilegível, mantido sem conversão: ' . $url);
			return $url;
		}

		$bg = @imagecreatetruecolor(imagesx($image), imagesy($image));
		if ($bg === FALSE) {
			imagedestroy($image);
			log_message('error', '[Upload] não foi possível converter o PNG, mantido sem conversão: ' . $url);
			return $url;
		}

		imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
		imagealphablending($bg, TRUE);
		imagecopy($bg, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
		imagedestroy($image);

		$jpg = substr($url, 0, -4) . '.jpg';
		$converted = @imagejpeg($bg, $jpg, $quality);
		imagedestroy($bg);

		if (!$converted) {
			log_message('error', '[Upload] falha ao gravar o JPG convertido, mantido o PNG: ' . $url);
			@unlink($jpg);
			return $url;
		}

		@unlink($url);
		return $jpg;
	}

	// Upload múltiplo de arquivo
	function x_do_upload_multiple_all($field, $folder, $allowed = array("jpg", "jpeg", "gif", "png", "zip", "rar", "xls", "xlsx", "doc", "docx", "pdf"), $resize = FALSE)
	{
		### Verifica se tem a pasta, senão cria
		$path = 'images/' . $folder . '/';
		if (!$this->prepare_upload_dir($path)) {
			return array();
		}

		$files = $_FILES["$field"];
		$files_uploaded = array();

		if (!empty($files)) {
			foreach ($files['name'] as $key => $image) {

				$type = explode('.', $files['name'][$key]);
				$type = end($type);
				$type = mb_strtolower($type);
				$url = "images/$folder/" . uniqid(rand()) . '.' . $type;

				if (in_array($type, $allowed)) {
					if (is_uploaded_file($files['tmp_name'][$key])) {
						if (move_uploaded_file($files["tmp_name"][$key], $url)) {
							if ($resize) {
								$url = $this->png_to_jpg($url, $type, 80);

								// $image = WideImage::load($url);
								// $image = $image->resize(1280, 900);
								// $image->saveToFile($url, 92);
							}
							$files_uploaded[] = $url;
						}
					}
				}
			}
		}
		return $files_uploaded;
	}

	public function wide($url, $w, $h, $type)
	{
		$explode = explode(".", $url);
		if (mb_strtolower(end($explode)) != 'png') {
			$image = WideImage::load($url);
			if ($type == 'P')
				$background = $image->allocateColor(0, 0, 0);
			if ($type == 'B')
				$background = $image->allocateColor(255, 255, 255);
			$mini = $image->resize($w, $h, 'inside')->resizeCanvas("$w", "$h", 'center', 'center', $background)->crop('center', 'center', $w, $h);
			if ($mini->saveToFile($url, 92) == TRUE) {
				return TRUE;
			};
		}
		return TRUE;
	}

}
