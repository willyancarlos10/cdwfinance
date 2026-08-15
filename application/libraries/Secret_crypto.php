<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Cifra simétrica reversível para segredos que precisam ser recuperados em
 * texto (credenciais de servidor de hospedagem: token WHM, login key do
 * DirectAdmin, senha ou chave privada SSH do CloudPanel).
 *
 * AES-256-GCM: o modo GCM já autentica o texto cifrado, então não é preciso um
 * HMAC separado — adulterar qualquer byte faz o decrypt falhar em vez de
 * devolver lixo.
 *
 * A chave vem de $config['secret_crypto_key'] (32 bytes em hexadecimal, 64
 * caracteres) e é independente da 'encryption_key' da sessão. Gerar uma por
 * ambiente com:
 *
 *   php -r "echo bin2hex(random_bytes(32));"
 *
 * FALHA FECHADA: sem chave válida, encrypt() e decrypt() devolvem FALSE e
 * registram o motivo no log. É proposital — o alternativa seria cair em uma
 * chave padrão embutida no código, que daria a impressão de estar cifrando
 * enquanto qualquer um com o fonte reverteria o valor.
 *
 * Trocar a chave torna ilegíveis os segredos cifrados com a anterior; nesse
 * caso as credenciais precisam ser recadastradas.
 */
class Secret_crypto
{
    /** Algoritmo. A tag de autenticação do GCM tem 16 bytes. */
    const CIPHER = 'aes-256-gcm';

    /** Tamanho do IV recomendado para GCM (96 bits). */
    const IV_LENGTH = 12;

    /** Tamanho da tag de autenticação, em bytes. */
    const TAG_LENGTH = 16;

    /**
     * Chave binária de 32 bytes já resolvida, ou FALSE quando a configuração é
     * inválida. NULL = ainda não resolvida.
     * @var string|bool|null
     */
    private $key = NULL;

    /**
     * Resolve (uma vez) a chave-mestra a partir da config.
     * @return string|bool 32 bytes binários, ou FALSE
     */
    private function getKey()
    {
        if ($this->key !== NULL) {
            return $this->key;
        }

        $hex = (string) config_item('secret_crypto_key');

        if ($hex === '') {
            log_message('error', 'Secret_crypto: $config["secret_crypto_key"] ausente. Gere uma com: php -r "echo bin2hex(random_bytes(32));"');
            return $this->key = FALSE;
        }

        if (!preg_match('/^[0-9a-fA-F]+$/', $hex) || strlen($hex) !== 64) {
            log_message('error', 'Secret_crypto: secret_crypto_key inválida — são esperados 64 caracteres hexadecimais (32 bytes).');
            return $this->key = FALSE;
        }

        return $this->key = hex2bin($hex);
    }

    /**
     * Cifra um valor.
     *
     * @param  string $plain
     * @return string|bool "iv_hex:tag_hex:ciphertext_hex", ou FALSE em falha
     */
    public function encrypt($plain)
    {
        $key = $this->getKey();
        if ($key === FALSE) {
            return FALSE;
        }

        $plain = (string) $plain;
        if ($plain === '') {
            return '';
        }

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $cipherText = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);

        if ($cipherText === FALSE) {
            log_message('error', 'Secret_crypto: falha em openssl_encrypt.');
            return FALSE;
        }

        return bin2hex($iv) . ':' . bin2hex($tag) . ':' . bin2hex($cipherText);
    }

    /**
     * Decifra um valor gerado por encrypt().
     *
     * @param  string $payload
     * @return string|bool texto original, '' quando a entrada é vazia,
     *                     ou FALSE quando o dado é inválido/adulterado
     */
    public function decrypt($payload)
    {
        $key = $this->getKey();
        if ($key === FALSE) {
            return FALSE;
        }

        $payload = (string) $payload;
        if ($payload === '') {
            return '';
        }

        $partes = explode(':', $payload);
        if (count($partes) !== 3) {
            log_message('error', 'Secret_crypto: payload cifrado malformado.');
            return FALSE;
        }

        list($ivHex, $tagHex, $dataHex) = $partes;
        if (!ctype_xdigit($ivHex) || !ctype_xdigit($tagHex) || !ctype_xdigit($dataHex)) {
            log_message('error', 'Secret_crypto: payload cifrado com conteúdo não-hexadecimal.');
            return FALSE;
        }

        $iv = hex2bin($ivHex);
        $tag = hex2bin($tagHex);
        $data = hex2bin($dataHex);

        if (strlen($iv) !== self::IV_LENGTH || strlen($tag) !== self::TAG_LENGTH) {
            log_message('error', 'Secret_crypto: IV ou tag com tamanho inválido.');
            return FALSE;
        }

        $plain = openssl_decrypt($data, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($plain === FALSE) {
            // Em GCM isto significa dado adulterado OU chave diferente da usada
            // para cifrar (o caso comum é a secret_crypto_key ter sido trocada).
            log_message('error', 'Secret_crypto: autenticação falhou — dado adulterado ou chave incorreta.');
            return FALSE;
        }

        return $plain;
    }

    /**
     * TRUE quando há chave utilizável. As telas usam isto para recusar o
     * cadastro de credencial antes de gravar algo que não poderá ser lido.
     * @return bool
     */
    public function isReady()
    {
        return $this->getKey() !== FALSE;
    }
}
