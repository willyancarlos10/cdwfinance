<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Recebe os avisos de liquidação dos PSPs (etapa C).
 *
 * Controller **público**: estende CI_Controller direto, e NÃO o MY_Controller.
 * Quem chama é o banco, sem cookie e sem sessão — herdar o painel traria
 * verificação de login, carga de menu e a própria sessão, que grava linha em
 * `crm_sessions` e toma `GET_LOCK` a cada requisição. É a mesma razão pela qual
 * a sessão não está no autoload deste projeto.
 *
 * A URL é `webhook/psp/<slug>/<token>`:
 *  - o **slug** escolhe o provedor (a allowlist do `Psp_model` valida);
 *  - o **token** identifica a conta (`crm_psp_accounts.webhook_token`, UNIQUE
 *    global). Não é `crm_companies.token`, que é semipúblico — vai no link de
 *    cadastro de cliente.
 *
 * REGRA CENTRAL: **o corpo recebido nunca é acreditado.** Ele diz apenas QUAL
 * cobrança olhar; valor, data e situação vêm da reconsulta ao provedor. A
 * assinatura do webhook do Inter não foi confirmada, então a defesa real é
 * essa — não a URL secreta, que é só a primeira barreira.
 *
 * Responde **200 rápido** e sempre que a mensagem for compreendida. Devolver
 * erro faz o PSP reenviar, e reenvio de algo que já foi processado só gera
 * carga: a idempotência da baixa (`paid_at`) já cobre a repetição.
 */
class Webhook extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('global_model');
    }

    /**
     * POST webhook/psp/<slug>/<token>
     *
     * @param string $slug
     * @param string $token
     */
    public function psp($slug = '', $token = '')
    {
        $slug = trim((string) $slug);
        $token = trim((string) $token);

        // O corpo é lido ANTES de qualquer validação: é a única prova do que
        // chegou, e precisa ser registrada mesmo quando a mensagem é recusada.
        $corpo = (string) file_get_contents('php://input');

        $this->load->model('psp_model');

        $conta = $this->contaDoToken($slug, $token);
        if (empty($conta)) {
            // 404 sem detalhe: dizer "token inválido" ou "provedor inexistente"
            // ajudaria quem estivesse varrendo a URL.
            log_message('error', sprintf(
                '[PSP] webhook recusado: slug=%s token_len=%d ip=%s',
                $slug,
                strlen($token),
                $this->input->ip_address()
            ));

            $this->responder(404, 'not found');
            return;
        }

        $idCompany = (int) $conta->id_company;

        $provider = $this->psp_model->provider($slug);
        if ($provider === NULL) {
            $this->responder(404, 'not found');
            return;
        }

        $lido = $provider->interpretarWebhook($this->cabecalhos(), $corpo);

        if (empty($lido['success'])) {
            // Guarda mesmo assim: corpo que não foi entendido é justamente o
            // que se quer inspecionar depois.
            $this->registrarEvento($idCompany, $slug, '', '', $corpo, NULL);
            $this->psp_model->logarErro($idCompany, $slug, 'webhook', (string) $lido['message']);

            // 200: reenviar não vai fazer o corpo passar a ser compreensível.
            $this->responder(200, 'ignored');
            return;
        }

        $eventos = isset($lido['data']['eventos']) && is_array($lido['data']['eventos'])
            ? $lido['data']['eventos']
            : [];

        $idUser = (int) $this->config->item('id_user_process_auto');

        foreach ($eventos as $evento) {
            $chargeId = trim((string) ($evento['charge_id'] ?? ''));
            if ($chargeId === '') continue;

            $fatura = $this->global_model->getWhere_off('crm_invoices', [
                'psp_charge_id' => $chargeId,
                'id_company' => $idCompany,
            ], TRUE);

            $idEvento = $this->registrarEvento(
                $idCompany,
                $slug,
                $chargeId,
                (string) ($evento['event_type'] ?? ''),
                $corpo,
                empty($fatura) ? NULL : (int) $fatura->id
            );

            // Cobrança que não é nossa (ou de outro tenant) fica só registrada:
            // é o caso de uma conta compartilhada, e não um erro a repetir.
            if (empty($fatura)) continue;

            // A VERDADE vem daqui, não do corpo.
            $r = $this->psp_model->conciliarCobranca((int) $fatura->id, $idCompany, $idUser);

            if (!empty($r['success'])) {
                $this->marcarProcessado($idEvento);
            }
        }

        $this->responder(200, 'ok');
    }

    /**
     * Resolve a conta pelo par slug + token.
     *
     * @param  string $slug
     * @param  string $token
     * @return object|null
     */
    private function contaDoToken($slug, $token)
    {
        if ($slug === '' || $token === '' || !isset($this->psp_model->providers()[$slug])) {
            return NULL;
        }

        // Token curto demais não chega ao banco: é varredura, não integração.
        if (strlen($token) < 32) return NULL;

        $conta = $this->global_model->getWhere_off('crm_psp_accounts', [
            'psp' => $slug,
            'webhook_token' => $token,
        ], TRUE);

        if (empty($conta) || (int) $conta->active !== 1) return NULL;

        return $conta;
    }

    /**
     * Guarda o recebido cru.
     *
     * Com webhook possivelmente não assinado, o corpo é a única evidência do
     * que aconteceu — e `processed` distingue "chegou" de "foi aplicado".
     *
     * @return int id do evento, ou 0
     */
    private function registrarEvento($idCompany, $psp, $chargeId, $tipo, $corpo, $idInvoice)
    {
        $id = $this->global_model->add('crm_psp_webhook_events', [
            'id_company' => (int) $idCompany,
            'psp' => mb_substr((string) $psp, 0, 20),
            'charge_id' => mb_substr((string) $chargeId, 0, 100),
            'event_type' => mb_substr((string) $tipo, 0, 40),
            'payload' => mb_substr((string) $corpo, 0, 60000),
            'id_invoice' => $idInvoice,
            'received' => date('Y-m-d H:i:s'),
        ]);

        return empty($id) ? 0 : (int) $id;
    }

    /**
     * @param int $idEvento
     */
    private function marcarProcessado($idEvento)
    {
        if ((int) $idEvento <= 0) return;

        $this->global_model->edit('crm_psp_webhook_events', [
            'processed' => date('Y-m-d H:i:s'),
        ], 'id', (int) $idEvento);
    }

    /**
     * Cabeçalhos da requisição, para a library procurar assinatura se houver.
     *
     * @return array
     */
    private function cabecalhos()
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            return is_array($headers) ? $headers : [];
        }

        $headers = [];
        foreach ($_SERVER as $chave => $valor) {
            if (strpos($chave, 'HTTP_') === 0) {
                $nome = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($chave, 5)))));
                $headers[$nome] = $valor;
            }
        }

        return $headers;
    }

    /**
     * Resposta curta, em texto: o PSP não lê HTML, e uma view do painel aqui
     * carregaria sessão e menu numa requisição que não tem usuário.
     *
     * @param int    $status
     * @param string $texto
     */
    private function responder($status, $texto)
    {
        $this->output
            ->set_status_header((int) $status)
            ->set_content_type('text/plain', 'utf-8')
            ->set_output((string) $texto);
    }
}
