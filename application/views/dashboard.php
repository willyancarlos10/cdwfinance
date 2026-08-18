<?php
// Dashboard do painel.
//
// A versão anterior era do CMS: indicadores do Google Analytics 4 do site da
// empresa e blocos de artigos (mais vistos, recentes, visualizações por dia).
// Saiu junto com os módulos. Os indicadores do financeiro moraram por um tempo
// na listagem de clientes; agora vivem aqui, alimentados por $this->data no
// Painel::index() e sempre no escopo da empresa selecionada no filtro do topo.

// Maior valor do gráfico: é ele que define os 100% das barras, para que a
// maior fatia sempre encoste na borda e as demais fiquem proporcionais a ela.
$maior_servico = 0;
foreach ($ind_por_servico as $s) {
    if ((int) $s->total > $maior_servico) $maior_servico = (int) $s->total;
}

// nice_date() devolve o mês em inglês (é o date() do PHP) e não há helper de
// mês em PT-BR no projeto — o rótulo do card sai desta tabela.
$meses_ptbr = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
$mes_atual = $meses_ptbr[(int) date('n')] . '/' . date('Y');

// --- Movimento de contratos, mês a mês --------------------------------------
// Duas barras verticais por mês (entradas e saídas), os meses em sequência.
//
// A régua é ÚNICA para o gráfico inteiro: o maior valor entre TODOS os meses e
// as DUAS séries vira 100%. Escalar cada mês pelo próprio máximo deixaria todo
// mês com uma barra encostando no topo, e um mês de R$ 200 pareceria igual a um
// de R$ 20.000 — o gráfico existe justamente para comparar os meses entre si.
$mov_maior = 0.0;
$mov_total_entrada = 0.0;
$mov_total_saida = 0.0;
$mov_qtd_entrada = 0;
$mov_qtd_saida = 0;
foreach ($ind_movimento_meses as $m) {
    if ((float) $m['entrada_valor'] > $mov_maior) $mov_maior = (float) $m['entrada_valor'];
    if ((float) $m['saida_valor'] > $mov_maior) $mov_maior = (float) $m['saida_valor'];
    $mov_total_entrada += (float) $m['entrada_valor'];
    $mov_total_saida += (float) $m['saida_valor'];
    $mov_qtd_entrada += (int) $m['entrada_qtd'];
    $mov_qtd_saida += (int) $m['saida_qtd'];
}
$mov_tem_dados = ($mov_qtd_entrada + $mov_qtd_saida) > 0;
$mov_saldo_periodo = $mov_total_entrada - $mov_total_saida;

// Piso de 2% SÓ para valor > 0: um mês pequeno ao lado de um mês grande
// arredondaria para zero e sumiria, dizendo "não houve movimento" onde houve.
// Mas zero tem de ficar zero — uma barrinha de 2% num mês parado seria um
// movimento inventado.
$mov_altura = function ($valor) use ($mov_maior) {
    if ($mov_maior <= 0 || (float) $valor <= 0) return 0;
    return max(2, round(((float) $valor / $mov_maior) * 100));
};

// Largura de cada mês, idêntica na linha das barras e na dos rótulos.
//
// NÃO usar `flex-fill` aqui: ele é `flex: 1 1 auto`, e a base `auto` faz cada
// coluna herdar a largura do próprio conteúdo — como os rótulos têm larguras
// diferentes ("set 2025" contra "out", "12.000" contra "700"), as colunas saem
// desiguais e as duas linhas desalinham progressivamente. O efeito é a barra de
// um mês aparecer sob o rótulo do mês anterior.
$mov_col = 100 / max(1, count($ind_movimento_meses));

// Rótulo curto do mês, derivado da tabela PT-BR que já existe acima.
$mov_abrev = function ($n) use ($meses_ptbr) {
    return mb_substr($meses_ptbr[(int) $n], 0, 3, 'UTF-8');
};

// --- Card de domínios -------------------------------------------------------
// Os três indicadores são a mesma estrutura repetida, então ficam numa tabela
// só: a ordem é a da urgência, e a cor é o que o usuário lê antes do número.
//
// `faixa` é a chave do `whois_bucket` correspondente — é o que o botão VER
// DOMÍNIOS manda ao endpoint. As chaves do array são as do contador (que veio
// da nomenclatura do card); o bucket é o nome do banco, e os dois precisam
// conviver sem que um vire tradução implícita do outro.
$blocos_dominios = [
    'criticos' => [
        'titulo' => 'Domínios críticos',
        'descricao' => 'Vencimento em atraso',
        'cor' => 'danger',
        'icone' => 'mdi-alert-octagon',
        'faixa' => 'vencido',
    ],
    'alerta' => [
        'titulo' => 'Domínios em alerta',
        'descricao' => 'Vencem nos próximos 15 dias',
        'cor' => 'warning',
        'icone' => 'mdi-clock-alert-outline',
        'faixa' => 'vence_30',
    ],
    'livres' => [
        'titulo' => 'Domínios disponíveis',
        'descricao' => 'Não registrados',
        'cor' => 'info',
        'icone' => 'mdi-web-off',
        'faixa' => 'livre',
    ],
    // Este não é faixa de WHOIS: pergunta outra coisa (o domínio está em algum
    // contrato?) e por isso SOBREPÕE os outros três — um domínio vencido pode
    // também estar sem contrato. Daí o cinza: não é nível de urgência do
    // registro, é lacuna de cadastro. Também é o motivo de os percentuais
    // poderem somar mais de 100%: cada um é sobre o total, não fatia de pizza.
    'sem_contrato' => [
        'titulo' => 'Domínios sem contrato',
        'descricao' => 'Hospedados fora de contrato',
        'cor' => 'secondary',
        'icone' => 'mdi-file-remove-outline',
        'faixa' => 'sem_contrato',
        // Sem contrato não há o que marcar como gerenciado: o flag mora no
        // domínio do contrato. Zero aqui é consequência, não base vazia.
        'nota_cdw' => 'Todo domínio gerenciado está em um contrato — por definição, zero neste escopo.',
    ],
];

/**
 * Percentual da faixa sobre o total do conjunto exibido. Uma casa decimal
 * porque, com a base grande, arredondar para inteiro faria toda faixa pequena
 * virar "0%" — justamente as que o card existe para mostrar.
 *
 * Compartilhada pelos cards de domínios e de espaço: os dois têm faixas sobre
 * um total, e duas escritas da mesma conta ficariam livres para divergir.
 */
$percentual_faixa = function ($parte, $total) {
    if ((int) $total <= 0) return 0.0;
    return round(($parte / $total) * 100, 1);
};

// Os dois conjuntos já vêm calculados do controller; o switch alterna entre
// eles no navegador. Este é o que a página mostra ao abrir.
$dom_inicial = $ind_dominios['todos'];

// Os dois conjuntos já formatados, para o switch só trocar texto e largura —
// nada de regra de formatação duplicada no JavaScript.
$dominios_js = [];
foreach (['todos', 'cdw'] as $escopo) {
    $conjunto = $ind_dominios[$escopo];

    $faixas = [];
    foreach (array_keys($blocos_dominios) as $chave) {
        $pct = $percentual_faixa($conjunto[$chave], $conjunto['total']);
        $faixas[$chave] = [
            'valor' => numero($conjunto[$chave]),
            'pct' => number_format($pct, 1, ',', '.') . '%',
            // Piso de 2% com valor diferente de zero, mesma razão do gráfico de
            // serviços: uma faixa de 0,4% arredondaria para uma barra invisível
            // e o card diria "não há nada aqui" justamente onde há.
            'largura' => ($conjunto[$chave] > 0) ? max(2, $pct) : 0,
            'quantidade' => (int) $conjunto[$chave],
        ];
    }

    $dominios_js[$escopo] = [
        'total' => numero($conjunto['total']),
        'quantidade' => (int) $conjunto['total'],
        'faixas' => $faixas,
    ];
}

// --- Card de espaço em disco ------------------------------------------------
// Mesma estrutura do card de domínios: faixas em ordem de urgência, a cor antes
// do número. `faixa` é o que o botão VER CONTRATOS manda ao endpoint, e a chave
// do array é a do contador — os dois nomes convivem sem virar tradução implícita
// um do outro, como no card de domínios.
//
// Aqui as faixas são fatias de verdade: um contrato está em uma só, então os
// percentuais nunca somam mais de 100%.
$blocos_espaco = [
    'criticos' => [
        'titulo' => 'Crítico',
        'descricao' => 'Uso em 200% ou mais do contratado',
        'cor' => 'danger',
        'icone' => 'mdi-database-alert-outline',
        'faixa' => 'critico',
    ],
    'urgentes' => [
        'titulo' => 'Urgente',
        'descricao' => 'Uso acima de 100% e abaixo de 200%',
        'cor' => 'warning',
        'icone' => 'mdi-database-arrow-up-outline',
        'faixa' => 'urgente',
    ],
    'proativos' => [
        'titulo' => 'Pró-ativo',
        'descricao' => 'Uso entre 90% e 100% do contratado',
        'cor' => 'info',
        'icone' => 'mdi-database-clock-outline',
        'faixa' => 'proativo',
    ],
];

// O denominador é `avaliados` (contratos vigentes COM espaço contratado), e não
// o total: contrato sem espaço definido não tem como estar a 90% de nada, e no
// denominador só encolheria as três faixas.
$espaco_base = (int) $ind_espaco['avaliados'];

$espaco_faixas = [];
foreach ($blocos_espaco as $chave => $bloco) {
    $pct = $percentual_faixa($ind_espaco[$chave], $espaco_base);
    $espaco_faixas[$chave] = [
        'valor' => numero($ind_espaco[$chave]),
        'pct' => number_format($pct, 1, ',', '.') . '%',
        // Mesmo piso de 2% do card de domínios: uma faixa de 0,4% viraria uma
        // barra invisível bem onde há algo para ver.
        'largura' => ($ind_espaco[$chave] > 0) ? max(2, $pct) : 0,
        'quantidade' => (int) $ind_espaco[$chave],
    ];
}
?>
<div class="row mb-2 mb-xl-3">
    <div class="col-auto d-none d-sm-block">
        <h3>Dashboard</h3>
        <!-- <small class="text-muted"><?php echo $this->session->userdata('company')->name; ?></small> -->
    </div>
</div>

<div class="row">
    <div class="col-12 col-sm-6 col-xxl-3 d-flex">
        <div class="card flex-fill">
            <div class="card-body py-3">
                <div class="row align-items-center">
                    <div class="col">
                        <span class="text-muted">Clientes</span>
                        <h2 class="mb-0"><?php echo numero($ind_clientes); ?></h2>
                        <small class="text-muted">Cadastros na base</small>
                    </div>
                    <div class="col-auto">
                        <i class="mdi mdi-account-multiple text-primary fs-2"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xxl-3 d-flex">
        <div class="card flex-fill">
            <div class="card-body py-3">
                <div class="row align-items-center">
                    <div class="col">
                        <span class="text-muted">Clientes vigentes</span>
                        <h2 class="mb-0 text-success"><?php echo numero($ind_clientes_vigentes); ?></h2>
                        <small class="text-muted">Com contrato ativo</small>
                    </div>
                    <div class="col-auto">
                        <i class="mdi mdi-account-check text-success fs-2"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xxl-3 d-flex">
        <div class="card flex-fill">
            <div class="card-body py-3">
                <div class="row align-items-center">
                    <div class="col">
                        <span class="text-muted">Contratos</span>
                        <h2 class="mb-0"><?php echo numero($ind_contratos); ?></h2>
                        <small class="text-muted">
                            <?php echo numero($ind_contratos_vigentes); ?> vigente(s) &middot; <?php echo numero($ind_contratos_suspensos); ?> suspenso(s)</small>
                    </div>
                    <div class="col-auto">
                        <i class="mdi mdi-file-document-outline text-primary fs-2"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xxl-3 d-flex">
        <div class="card flex-fill">
            <div class="card-body py-3">
                <div class="row align-items-center">
                    <div class="col">
                        <span class="text-muted">Novos contratos</span>
                        <h2 class="mb-0 text-info"><?php echo numero($ind_contratos_novos_mes); ?></h2>
                        <small class="text-muted">Criados em <?php echo $mes_atual; ?></small>
                    </div>
                    <div class="col-auto">
                        <i class="mdi mdi-file-plus-outline text-info fs-2"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12 d-flex">
        <div class="card flex-fill">
            <div class="card-body py-3">
                <div class="row align-items-start mb-3">
                    <div class="col">
                        <h5 class="card-title mb-1">Movimento de contratos</h5>
                        <p class="text-muted mb-0 lh-1">
                            <small>Valor que entrou e que saiu em cada um dos últimos <?php echo count($ind_movimento_meses); ?> meses.
                                Contrato inteiro apenas: reajuste de valor não aparece aqui, e suspensão não conta como
                                saída — só o encerramento.</small>
                        </p>
                    </div>
                    <div class="col-auto">
                        <small class="d-block text-muted">
                            <i class="mdi mdi-square text-success"></i> Entradas &middot; contratos novos
                        </small>
                        <small class="d-block text-muted">
                            <i class="mdi mdi-square text-warning"></i> Saídas &middot; contratos encerrados
                        </small>
                    </div>
                </div>

                <?php if ($mov_tem_dados) { ?>
                    <!-- Barras verticais em flexbox: não há biblioteca de gráficos no
                         projeto, e a altura inline em % é o mesmo idioma do width inline
                         das barras horizontais. Rola no eixo x quando a tela é estreita,
                         para 12 meses não virarem 12 tracinhos ilegíveis. -->
                    <div class="overflow-auto">
                        <div style="min-width: 1150px;">
                            <div class="d-flex align-items-end border-bottom" style="height: 220px;">
                                <?php foreach ($ind_movimento_meses as $indiceMes => $m) {
                                    $rotuloMes = $meses_ptbr[$m['mes']] . '/' . $m['ano'];
                                    $tituloMes = $rotuloMes . "\n"
                                        . 'Entradas: R$ ' . reais($m['entrada_valor']) . ' (' . numero($m['entrada_qtd']) . ' contrato(s))' . "\n"
                                        . 'Saídas: R$ ' . reais($m['saida_valor']) . ' (' . numero($m['saida_qtd']) . ' contrato(s))';

                                    $series = [
                                        ['cor' => 'success', 'nome' => 'Entradas', 'valor' => (float) $m['entrada_valor'], 'qtd' => (int) $m['entrada_qtd']],
                                        ['cor' => 'warning', 'nome' => 'Saídas', 'valor' => (float) $m['saida_valor'], 'qtd' => (int) $m['saida_qtd']],
                                    ];
                                ?>
                                    <?php // Separador entre meses: sem ele, um mês em que só uma das séries
                                    // tem valor exibe a barra fora do centro da coluna (a verde à
                                    // esquerda, a laranja à direita) e ela parece ser do mês vizinho.
                                    ?>
                                    <div class="d-flex flex-column h-100 px-1 <?php echo $indiceMes > 0 ? 'border-start' : ''; ?>" style="flex: 0 0 <?php echo $mov_col; ?>%; min-width: 0;" title="<?php echo htmlspecialchars($tituloMes, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php // Os rótulos ficam sobre o MÊS, empilhados, e não sobre cada
                                        // barra: com centavos, "R$ 128.450,75" é mais largo que a barra e
                                        // os dois de um mesmo mês se sobreporiam. A cor é o que liga cada
                                        // linha à sua barra. Faixa de altura FIXA — sem essa reserva o
                                        // rótulo empurraria a barra e a de 100% estouraria o card. ?>
                                        <div class="text-center" style="height: 32px;">
                                            <?php foreach ($series as $s) { ?>
                                                <?php // Mês parado não ganha rótulo: "R$ 0,00 (0)" doze vezes é ruído.
                                                if ($s['valor'] > 0) { ?>
                                                    <small class="d-block lh-sm fw-bold text-nowrap text-<?php echo $s['cor']; ?>" style="font-size: .6rem;"><?php echo reais($s['valor']); ?> (<?php echo numero($s['qtd']); ?>)</small>
                                                <?php } ?>
                                            <?php } ?>
                                        </div>
                                        <div class="d-flex align-items-end justify-content-center" style="height: calc(100% - 32px);">
                                            <?php foreach ($series as $i => $s) { ?>
                                                <div class="bg-<?php echo $s['cor']; ?> rounded-top <?php echo $i === 0 ? 'me-1' : ''; ?>" role="img" style="width: 40%; height: <?php echo (int) $mov_altura($s['valor']); ?>%;" aria-label="<?php echo $s['nome']; ?> em <?php echo $rotuloMes; ?>: R$ <?php echo reais($s['valor']); ?> em <?php echo numero($s['qtd']); ?> contrato(s)"></div>
                                            <?php } ?>
                                        </div>
                                    </div>
                                <?php } ?>
                            </div>
                            <div class="d-flex">
                                <?php foreach ($ind_movimento_meses as $i => $m) { ?>
                                    <div class="text-center px-1 pt-1 <?php echo $i > 0 ? 'border-start' : ''; ?>" style="flex: 0 0 <?php echo $mov_col; ?>%; min-width: 0;">
                                        <small class="d-block text-muted"><?php echo $mov_abrev($m['mes']); ?></small>
                                        <?php // O ano aparece na primeira coluna e sempre que vira — sem repetir em todas.
                                        if ($i === 0 || $m['mes'] === 1) { ?>
                                            <small class="d-block text-muted"><?php echo $m['ano']; ?></small>
                                        <?php } ?>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>
                    </div>

                    <div class="row align-items-center g-2 mt-3 pt-2 border-top">
                        <div class="col">
                            <small class="d-block text-muted">
                                No período: <strong class="text-success">R$ <?php echo reais($mov_total_entrada); ?></strong>
                                em <?php echo numero($mov_qtd_entrada); ?> entrada(s)
                                &middot; <strong class="text-warning">R$ <?php echo reais($mov_total_saida); ?></strong>
                                em <?php echo numero($mov_qtd_saida); ?> saída(s)
                            </small>
                        </div>
                        <div class="col-auto text-end">
                            <small class="d-block text-muted">Saldo do período</small>
                            <h5 class="mb-0 text-<?php echo $mov_saldo_periodo < 0 ? 'danger' : 'success'; ?>">
                                <?php echo $mov_saldo_periodo < 0 ? '&minus;' : '+'; ?> R$ <?php echo reais(abs($mov_saldo_periodo)); ?>
                            </h5>
                        </div>
                    </div>
                    <small class="text-muted d-block mt-2">
                        Sobre cada mês: o valor em reais e, entre parênteses, a quantidade de contratos — em
                        verde as entradas, em laranja as saídas. Todas as barras dividem a mesma escala, então os meses são
                        comparáveis entre si. O valor é o do ciclo cheio do contrato, sem rateio: um contrato anual
                        de R$ 1.200,00 soma R$ 1.200,00 no mês em que entrou.
                    </small>
                <?php } else { ?>
                    <div class="alert alert-secondary mb-0" role="alert">
                        <div class="alert-message">Nenhum contrato entrou ou saiu nos últimos <?php echo count($ind_movimento_meses); ?> meses.</div>
                    </div>
                <?php } ?>
            </div>
        </div>
    </div>
</div>
<?php
// Card de cancelamentos. Fica logo abaixo do movimento porque explica a barra
// laranja dele: lá aparece QUANTO saiu por mês, aqui POR QUÊ e a que custo.
//
// A régua das barras de valor é o MAIOR motivo, e não o total — como no gráfico
// de tipos de serviço, o que interessa é comparar os motivos entre si.
$canc_maior_valor = 0.0;
foreach ($ind_cancelamentos['motivos'] as $m) {
    if ($m['valor'] > $canc_maior_valor) $canc_maior_valor = $m['valor'];
}

// O que vai para o ApexCharts são as QUANTIDADES CRUAS: ele calcula a fatia e o
// percentual. Montar a pizza a partir do percentual já arredondado deixaria
// fresta (três motivos iguais dão 33,3% x 3 = 99,9%).
$canc_js = [
    'rotulos' => [],
    'valores' => [],
    'cores' => [],
];
foreach ($ind_cancelamentos['motivos'] as $m) {
    $canc_js['rotulos'][] = $m['nome'];
    $canc_js['valores'][] = $m['quantidade'];
    $canc_js['cores'][] = $m['cor'];
}
?>
<div class="row">
    <div class="col-12 d-flex">
        <div class="card flex-fill">
            <div class="card-body py-3">
                <div class="row align-items-start mb-2">
                    <div class="col">
                        <h5 class="card-title mb-1">Cancelamentos</h5>
                        <p class="text-muted mb-0"><small>Contratos encerrados nos últimos <?php echo count($ind_movimento_meses); ?> meses, por motivo do encerramento.</small></p>
                    </div>
                </div>

                <?php if (!empty($ind_cancelamentos['motivos'])) { ?>
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-sm-4">
                            <span class="text-muted"><small>Contratos encerrados</small></span>
                            <h3 class="mb-0"><?php echo numero($ind_cancelamentos['total']); ?></h3>
                        </div>
                        <div class="col-12 col-sm-4">
                            <span class="text-muted"><small>Valor que deixou de entrar</small></span>
                            <h3 class="mb-0 text-warning">R$ <?php echo reais($ind_cancelamentos['valor']); ?></h3>
                        </div>
                        <div class="col-12 col-sm-4">
                            <span class="text-muted"><small>Duração média do contrato</small></span>
                            <h3 class="mb-0"><?php echo number_format($ind_cancelamentos['meses_medio'], 1, ',', '.'); ?> <small class="text-muted">meses</small></h3>
                        </div>
                    </div>

                    <div class="row align-items-center">
                        <div class="col-12 col-lg-5">
                            <?php // A pizza responde "por que saem"; o Apex desenha legenda e percentual. ?>
                            <div id="grafico_cancelamentos"></div>
                        </div>
                        <div class="col-12 col-lg-7">
                            <p class="text-muted mb-2"><small>Valor por motivo &middot; quanto cada um custou no período</small></p>
                            <?php foreach ($ind_cancelamentos['motivos'] as $motivo) {
                                // Mesmo piso de 2% dos outros cards, condicionado a valor > 0: um
                                // motivo pequeno ao lado de um grande arredondaria para zero e sumiria.
                                $largura = ($canc_maior_valor > 0 && $motivo['valor'] > 0)
                                    ? max(2, round(($motivo['valor'] / $canc_maior_valor) * 100))
                                    : 0;
                            ?>
                                <div class="row align-items-center g-2 mb-2">
                                    <div class="col-5 col-md-4 text-truncate" title="<?php echo htmlspecialchars($motivo['nome'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <small><?php echo htmlspecialchars($motivo['nome'], ENT_QUOTES, 'UTF-8'); ?></small>
                                    </div>
                                    <div class="col">
                                        <div class="progress" style="height: 10px;">
                                            <?php // A cor é a mesma da fatia: é o que liga uma barra à sua parte da pizza. ?>
                                            <div class="progress-bar" role="progressbar" style="width: <?php echo (int) $largura; ?>%; background-color: <?php echo htmlspecialchars($motivo['cor'], ENT_QUOTES, 'UTF-8'); ?>;" aria-valuenow="<?php echo (int) $motivo['valor']; ?>" aria-valuemin="0" aria-valuemax="<?php echo (int) $canc_maior_valor; ?>" aria-label="<?php echo htmlspecialchars($motivo['nome'], ENT_QUOTES, 'UTF-8'); ?>"></div>
                                        </div>
                                    </div>
                                    <div class="col-auto text-end" style="min-width: 96px;">
                                        <small class="fw-bold">R$ <?php echo reais($motivo['valor']); ?></small>
                                        <small class="d-block text-muted"><?php echo numero($motivo['quantidade']); ?> contrato(s)</small>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                    </div>

                    <small class="text-muted d-block mt-2">
                        O valor é o do ciclo cheio do contrato, sem rateio — a mesma conta das saídas do gráfico
                        acima, no mesmo período. A duração média vai da criação do contrato até o encerramento.
                    </small>
                <?php } else { ?>
                    <div class="alert alert-secondary mb-0" role="alert">
                        <div class="alert-message">Nenhum contrato foi encerrado nos últimos <?php echo count($ind_movimento_meses); ?> meses nesta empresa.</div>
                    </div>
                <?php } ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($ind_cancelamentos['motivos'])) { ?>
    <script>
        // DOMContentLoaded, e não $(function(){}): o app.js que traz jQuery e o
        // ApexCharts é carregado no footer, ou seja, DEPOIS desta view.
        document.addEventListener("DOMContentLoaded", function() {
            if (typeof ApexCharts === 'undefined') return;

            var dados = <?php echo json_encode($canc_js, JSON_UNESCAPED_UNICODE); ?>;

            new ApexCharts(document.querySelector("#grafico_cancelamentos"), {
                chart: {
                    type: 'donut',
                    height: 280,
                    // O tema do AppStack anima por padrão; aqui o gráfico é
                    // leitura rápida, não apresentação.
                    animations: { enabled: false }
                },
                series: dados.valores,
                labels: dados.rotulos,
                colors: dados.cores,
                legend: { position: 'bottom' },
                dataLabels: {
                    formatter: function(pct) {
                        // Uma casa decimal, como os percentuais dos outros
                        // cards, e vírgula decimal para não destoar da tela.
                        return pct.toFixed(1).replace('.', ',') + '%';
                    }
                },
                tooltip: {
                    y: {
                        formatter: function(valor) {
                            return valor + ' contrato(s)';
                        }
                    }
                },
                noData: { text: 'Sem cancelamentos no período.' }
            }).render();
        });
    </script>
<?php } ?>

<div class="row">
    <div class="col-12 d-flex">
        <div class="card flex-fill">
            <div class="card-body py-3">
                <div class="row align-items-start">
                    <div class="col">
                        <h5 class="card-title mb-1">Domínios</h5>
                    </div>
                    <?php if ($dom_inicial['total'] > 0) { ?>
                        <div class="col-auto">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="switch_dominios_cdw">
                                <label class="form-check-label" for="switch_dominios_cdw">Somente gerenciados pela CDW</label>
                            </div>
                        </div>
                    <?php } ?>
                </div>

                <?php if ($dom_inicial['total'] > 0) { ?>
                    <p class="text-muted mb-3 lh-1">
                        <small>Situação dos domínios sincronizados dos servidores. Cada percentual é sobre o total
                            <span id="dominios_escopo">da base</span>: <strong id="dominios_total"><?php echo numero($dom_inicial['total']); ?></strong> domínio(s).</small>
                    </p>

                    <div class="alert alert-secondary d-none" role="alert" id="dominios_aviso_cdw">
                        <div class="alert-message">Nenhum domínio desta empresa está marcado como gerenciado pela CDW. A marcação é feita no cadastro do domínio, dentro do contrato.</div>
                    </div>

                    <div class="row">
                        <?php foreach ($blocos_dominios as $chave => $bloco) {
                            $faixa = $dominios_js['todos']['faixas'][$chave];
                        ?>
                            <div class="col-12 col-sm-6 col-xl-3 mb-3 mb-xl-0">
                                <div class="d-flex align-items-center mb-1">
                                    <i class="mdi <?php echo $bloco['icone']; ?> text-<?php echo $bloco['cor']; ?> fs-2 me-2"></i>
                                    <div>
                                        <span class="text-muted"><?php echo $bloco['titulo']; ?></span>
                                        <small class="d-block text-muted"><?php echo $bloco['descricao']; ?></small>
                                    </div>
                                </div>
                                <h2 class="mb-1 text-<?php echo $bloco['cor']; ?>" data-dom-valor="<?php echo $chave; ?>"><?php echo $faixa['valor']; ?></h2>
                                <div class="progress mb-1" style="height: 10px;">
                                    <div class="progress-bar bg-<?php echo $bloco['cor']; ?>" role="progressbar" data-dom-barra="<?php echo $chave; ?>" style="width: <?php echo $faixa['largura']; ?>%;" aria-valuenow="<?php echo $faixa['quantidade']; ?>" aria-valuemin="0" aria-valuemax="<?php echo $dominios_js['todos']['quantidade']; ?>" aria-label="<?php echo $bloco['titulo']; ?>"></div>
                                </div>
                                <div class="d-flex align-items-center justify-content-between">
                                    <small class="text-muted"><strong data-dom-pct="<?php echo $chave; ?>"><?php echo $faixa['pct']; ?></strong> do total</small>
                                    <button type="button" class="btn btn-sm btn-outline-<?php echo $bloco['cor']; ?> js-ver-dominios" data-faixa="<?php echo $bloco['faixa']; ?>" data-titulo="<?php echo $bloco['titulo']; ?>" data-descricao="<?php echo $bloco['descricao']; ?>" data-dom-botao="<?php echo $chave; ?>" <?php if ($faixa['quantidade'] === 0) echo 'disabled=""'; ?>>
                                        <i class="mdi mdi-format-list-bulleted"></i> VER DOMÍNIOS
                                    </button>
                                </div>
                                <?php if (!empty($bloco['nota_cdw'])) { ?>
                                    <small class="text-muted d-none mt-1" data-dom-nota="<?php echo $chave; ?>"><?php echo $bloco['nota_cdw']; ?></small>
                                <?php } ?>
                            </div>
                        <?php } ?>
                    </div>
                <?php } else { ?>
                    <div class="alert alert-secondary mb-0" role="alert">
                        <div class="alert-message">
                            Nenhum domínio sincronizado nesta empresa. Cadastre um servidor em
                            <a href="<?php echo base_url('servidores'); ?>">Servidores</a> e sincronize para acompanhar os vencimentos.
                        </div>
                    </div>
                <?php } ?>
            </div>
        </div>
    </div>
</div>

<?php if ($dom_inicial['total'] > 0) { ?>
    <div class="modal fade" id="modal_dominios_card" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="dominios_modal_titulo">Domínios</h5>
                        <small class="text-muted" id="dominios_modal_resumo">Carregando...</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body" id="dominios_modal_corpo">
                    <p class="text-muted mb-0"><i class="fa fa-spinner fa-spin"></i> Carregando...</p>
                </div>
                <div class="modal-footer">
                    <a href="<?php echo base_url('servidores/dominios'); ?>" class="btn btn-outline-secondary"><i class="mdi mdi-open-in-new"></i> ABRIR TELA DE DOMÍNIOS</a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">FECHAR</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // DOMContentLoaded, e não $(function(){}): o app.js que traz o jQuery é
        // carregado no footer, ou seja, DEPOIS desta view.
        document.addEventListener("DOMContentLoaded", function() {
            // Os dois conjuntos vêm prontos do PHP — alternar é só trocar
            // texto e largura, sem nova requisição e sem recalcular percentual.
            var dominios = <?php echo json_encode($dominios_js, JSON_UNESCAPED_UNICODE); ?>;

            var $switch = $('#switch_dominios_cdw');

            function aplicarEscopo() {
                var somenteCdw = $switch.is(':checked');
                var conjunto = somenteCdw ? dominios.cdw : dominios.todos;

                $('#dominios_total').text(conjunto.total);
                $('#dominios_escopo').text(somenteCdw ? 'gerenciado pela CDW' : 'da base');

                $.each(conjunto.faixas, function(chave, faixa) {
                    $('[data-dom-valor="' + chave + '"]').text(faixa.valor);
                    $('[data-dom-pct="' + chave + '"]').text(faixa.pct);
                    $('[data-dom-barra="' + chave + '"]')
                        .css('width', faixa.largura + '%')
                        .attr('aria-valuenow', faixa.quantidade)
                        .attr('aria-valuemax', conjunto.quantidade);
                    // Faixa zerada não tem lista para abrir: melhor o botão
                    // desligado do que um modal que só diz "nada aqui".
                    $('[data-dom-botao="' + chave + '"]').prop('disabled', faixa.quantidade === 0);
                });

                // Zero domínios gerenciados é diferente de zero vencimentos: sem
                // o aviso, quatro zeros pareceriam "está tudo em dia".
                $('#dominios_aviso_cdw').toggleClass('d-none', !(somenteCdw && conjunto.quantidade === 0));

                // Notas que só valem no escopo CDW (hoje: por que "sem contrato"
                // é necessariamente zero quando o switch está ligado).
                $('[data-dom-nota]').toggleClass('d-none', !somenteCdw);
            }

            $switch.on('change', aplicarEscopo);

            // --- Modal VER DOMÍNIOS ------------------------------------------
            // Colunas iguais às da aba Domínios da visão geral do cliente. A
            // faixa não vira coluna porque cada botão abre uma faixa só — ela
            // já está no título do modal.
            var colunas = ['Ações', 'Domínio', 'Vínculo', 'Em uso', 'Vencimento', 'Local de registro', 'Gerenciado CDW', 'Observações'];

            function celulaTexto(valor, classe) {
                return $('<td></td>').addClass(classe || '').text(valor ? valor : '—');
            }

            function linhaDominio(d) {
                var $tr = $('<tr></tr>');

                // Ações: o domínio pode estar hospedado sem constar em contrato
                // nenhum, e nesse caso não há para onde levar o usuário.
                var $acoes = $('<td align="center" class="text-nowrap"></td>');
                if (d.url_contrato) {
                    $acoes.append($('<a class="btn btn-sm btn-outline-secondary"><i class="fa fa-eye"></i></a>')
                        .attr('href', d.url_contrato)
                        .attr('title', d.contratos > 1 ?
                            'Este domínio está em ' + d.contratos + ' contratos; abre o primeiro' :
                            'Abrir o contrato deste domínio'));
                } else {
                    $acoes.append($('<small class="text-muted"></small>')
                        .attr('title', 'Domínio hospedado que não consta em nenhum contrato').text('—'));
                }
                $tr.append($acoes);

                $tr.append($('<td></td>').append($('<small></small>').text(d.domain)));
                $tr.append($('<td align="center"></td>').append(
                    $('<span class="badge bg-success"><i class="mdi mdi-server"></i> </span>')
                    .attr('title', 'Status no painel: ' + (d.servidor_status || '—'))
                    .append(document.createTextNode(d.servidor || '—'))));
                $tr.append(celulaTexto(d.em_uso, 'text-center'));
                $tr.append(celulaTexto(d.vencimento, 'text-center'));
                $tr.append(celulaTexto(d.registrador));
                $tr.append($('<td align="center"></td>').append(d.gerenciado ?
                    $('<span class="badge bg-primary"></span>').text('Sim') :
                    $('<span class="badge bg-light text-dark border"></span>').text('Não')));
                $tr.append(celulaTexto(d.observacoes));

                return $tr;
            }

            function carregarDominios(faixa, descricao, totalFaixa) {
                var somenteCdw = $switch.is(':checked');
                var $corpo = $('#dominios_modal_corpo');
                var escopo = somenteCdw ? 'somente gerenciados pela CDW' : 'todos os domínios da base';

                $corpo.html('<p class="text-muted mb-0"><i class="fa fa-spinner fa-spin"></i> Carregando os domínios...</p>');
                $('#dominios_modal_resumo').text(descricao + ' · ' + escopo);

                $.post('<?php echo base_url('painel/json_getdominioscard'); ?>', {
                    faixa: faixa,
                    gerenciados: somenteCdw ? '1' : '0'
                }, null, 'json').done(function(retorno) {
                    if (!retorno || !retorno.success) {
                        $corpo.html($('<div class="alert alert-danger mb-0"><div class="alert-message"></div></div>')
                            .find('.alert-message').text(retorno && retorno.message ? retorno.message : 'Não foi possível carregar os domínios.').end());
                        return;
                    }

                    if (!retorno.data.dominios.length) {
                        $corpo.html($('<div class="alert alert-success mb-0"><div class="alert-message"></div></div>')
                            .find('.alert-message').text('Nenhum domínio nesta faixa' +
                                (somenteCdw ? ' entre os gerenciados pela CDW' : '') + '.').end());
                        $('#dominios_modal_resumo').text('0 domínio(s) · ' + escopo);
                        return;
                    }

                    // Teto batido: o usuário precisa saber que está vendo uma
                    // parte, senão trata a lista como se fosse a base inteira.
                    if (retorno.data.truncado) {
                        $corpo.html($('<div class="alert alert-warning"><div class="alert-message"></div></div>')
                            .find('.alert-message').text('Mostrando os ' + retorno.data.limite +
                                ' primeiros de ' + totalFaixa + ' domínios, do vencimento mais próximo em diante. A lista completa está em Servidores > Domínios.').end());
                    } else {
                        $corpo.empty();
                    }

                    var $thead = $('<thead></thead>').append($('<tr></tr>'));
                    $.each(colunas, function(i, rotulo) {
                        $thead.find('tr').append($('<th></th>').text(rotulo));
                    });

                    var $tbody = $('<tbody></tbody>');
                    $.each(retorno.data.dominios, function(i, d) {
                        $tbody.append(linhaDominio(d));
                    });

                    // append, e não html: o aviso de corte já está no corpo.
                    $corpo.append($('<div class="table-responsive"></div>').append(
                        $('<table class="table table-sm table-striped table-bordered table-hover"></table>')
                        .append($thead).append($tbody)));
                    $corpo.append('<p class="text-muted mb-0"><small>Somente leitura. O cadastro de domínio fica no contrato; a base completa, com filtro e paginação, em Servidores &gt; Domínios.</small></p>');

                    $('#dominios_modal_resumo').text((retorno.data.truncado ?
                        retorno.data.total + ' de ' + totalFaixa :
                        retorno.data.total) + ' domínio(s) · ' + descricao + ' · ' + escopo);
                }).fail(function() {
                    $corpo.html('<div class="alert alert-danger mb-0"><div class="alert-message">Falha de comunicação ao carregar os domínios.</div></div>');
                });
            }

            // Um botão por indicador: cada um abre a lista da SUA faixa. Busca a
            // cada abertura, e não uma vez só — entre uma e outra o usuário pode
            // ter mexido no switch.
            $('.js-ver-dominios').on('click', function() {
                var $botao = $(this);

                // O total da faixa sai do próprio card (o endpoint devolve no
                // máximo o teto de linhas, então ele não saberia dizer quantas
                // existem sem um COUNT a mais).
                var conjunto = $switch.is(':checked') ? dominios.cdw : dominios.todos;
                var faixaCard = conjunto.faixas[$botao.data('dom-botao')];

                $('#dominios_modal_titulo').text($botao.data('titulo'));
                $('#modal_dominios_card').modal('show');

                carregarDominios($botao.data('faixa'), $botao.data('descricao'), faixaCard ? faixaCard.quantidade : 0);
            });
        });
    </script>
<?php } ?>

<div class="row">
    <div class="col-12 d-flex">
        <div class="card flex-fill">
            <div class="card-body py-3">
                <h5 class="card-title mb-1">Espaço em disco dos contratos</h5>

                <?php if ($espaco_base > 0) { ?>
                    <p class="text-muted mb-3 lh-1">
                        <small>Contratos vigentes que já encostaram ou passaram do espaço contratado. O uso soma o disco dos
                            domínios do contrato que têm correspondência nos servidores; o percentual é sobre os
                            <strong><?php echo numero($espaco_base); ?></strong> contrato(s) vigente(s) com espaço contratado definido.</small>
                    </p>

                    <?php if ($ind_espaco['sem_espaco'] > 0) { ?>
                        <!-- <div class="alert alert-secondary" role="alert">
                            <div class="alert-message">
                                <?php echo numero($ind_espaco['sem_espaco']); ?> contrato(s) vigente(s) estão fora desta conta por não terem espaço contratado definido<?php if ($ind_espaco['sem_espaco_com_uso'] > 0) { ?>
                                — e <strong><?php echo numero($ind_espaco['sem_espaco_com_uso']); ?></strong> del(es) já consomem disco<?php } ?>.
                                O espaço é definido no cadastro do contrato.
                            </div>
                        </div> -->
                    <?php } ?>

                    <div class="row">
                        <?php foreach ($blocos_espaco as $chave => $bloco) {
                            $faixa = $espaco_faixas[$chave];
                        ?>
                            <div class="col-12 col-md-4 mb-3 mb-md-0">
                                <div class="d-flex align-items-center mb-1">
                                    <i class="mdi <?php echo $bloco['icone']; ?> text-<?php echo $bloco['cor']; ?> fs-2 me-2"></i>
                                    <div>
                                        <span class="text-muted"><?php echo $bloco['titulo']; ?></span>
                                        <small class="d-block text-muted"><?php echo $bloco['descricao']; ?></small>
                                    </div>
                                </div>
                                <h2 class="mb-1 text-<?php echo $bloco['cor']; ?>"><?php echo $faixa['valor']; ?></h2>
                                <div class="progress mb-1" style="height: 10px;">
                                    <div class="progress-bar bg-<?php echo $bloco['cor']; ?>" role="progressbar" style="width: <?php echo $faixa['largura']; ?>%;" aria-valuenow="<?php echo $faixa['quantidade']; ?>" aria-valuemin="0" aria-valuemax="<?php echo $espaco_base; ?>" aria-label="<?php echo $bloco['titulo']; ?>"></div>
                                </div>
                                <div class="d-flex align-items-center justify-content-between">
                                    <small class="text-muted"><strong><?php echo $faixa['pct']; ?></strong> do total</small>
                                    <button type="button" class="btn btn-sm btn-outline-<?php echo $bloco['cor']; ?> js-ver-contratos" data-faixa="<?php echo $bloco['faixa']; ?>" data-titulo="<?php echo $bloco['titulo']; ?>" data-descricao="<?php echo $bloco['descricao']; ?>" <?php if ($faixa['quantidade'] === 0) echo 'disabled=""'; ?>>
                                        <i class="mdi mdi-format-list-bulleted"></i> VER CONTRATOS
                                    </button>
                                </div>
                            </div>
                        <?php } ?>
                    </div>
                <?php } else { ?>
                    <div class="alert alert-secondary mb-0" role="alert">
                        <div class="alert-message">
                            <?php if ($ind_espaco['sem_espaco'] > 0) { ?>
                                Nenhum dos <?php echo numero($ind_espaco['sem_espaco']); ?> contrato(s) vigente(s) desta empresa tem espaço contratado definido — sem ele não há como medir o uso. O espaço é definido no cadastro do contrato.
                            <?php } else { ?>
                                Nenhum contrato vigente nesta empresa. O acompanhamento de espaço começa quando o cliente tem contrato com espaço contratado definido.
                            <?php } ?>
                        </div>
                    </div>
                <?php } ?>
            </div>
        </div>
    </div>
</div>

<?php if ($espaco_base > 0) { ?>
    <div class="modal fade" id="modal_contratos_espaco" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="contratos_modal_titulo">Contratos</h5>
                        <small class="text-muted" id="contratos_modal_resumo">Carregando...</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body" id="contratos_modal_corpo">
                    <p class="text-muted mb-0"><i class="fa fa-spinner fa-spin"></i> Carregando...</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">FECHAR</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // DOMContentLoaded, e não $(function(){}): o app.js que traz o jQuery é
        // carregado no footer, ou seja, DEPOIS desta view.
        document.addEventListener("DOMContentLoaded", function() {
            // A faixa não vira coluna: cada botão abre uma faixa só, e ela já
            // está no título do modal.
            var colunas = ['Ações', 'Cliente', 'Espaço contratado', 'Em uso', 'Uso', 'Domínios'];

            function linhaContrato(c, cor) {
                var $tr = $('<tr></tr>');

                var $acoes = $('<td align="center" class="text-nowrap"></td>');
                $acoes.append($('<a class="btn btn-sm btn-outline-secondary"><i class="fa fa-eye"></i></a>')
                    .attr('href', c.url_contrato)
                    .attr('title', 'Abrir o contrato'));
                $tr.append($acoes);

                $tr.append($('<td></td>').append($('<a></a>')
                    .attr('href', c.url_cliente)
                    .attr('title', 'Abrir a visão geral do cliente')
                    .append($('<small></small>').text(c.cliente))));

                $tr.append($('<td align="center"></td>').append($('<small></small>').text(c.espaco)));
                $tr.append($('<td align="center"></td>').append($('<small></small>').text(c.em_uso)));

                // O número passa de 100%, a barra satura: acima disso ela não
                // tem para onde crescer e só o número informa o tamanho do estouro.
                var $uso = $('<td></td>');
                $uso.append($('<div class="text-end"></div>').append($('<small class="fw-bold"></small>').text(c.pct)));
                $uso.append($('<div class="progress mt-1" style="height: 6px;"></div>').append(
                    $('<div class="progress-bar" role="progressbar"></div>')
                    .addClass('bg-' + cor)
                    .css('width', c.pct_barra + '%')));
                $tr.append($uso);

                var $dominios = $('<td align="center"></td>').append($('<small></small>').text(c.dominios));
                // Vínculo sem leitura de disco faz o contrato parecer mais folgado
                // do que está: sem o aviso o zero passaria por "site vazio".
                if (c.dominios_sem_dado > 0) {
                    $dominios.append($('<small class="d-block text-muted"></small>')
                        .text(c.dominios_sem_dado + ' sem leitura de disco'));
                }
                $tr.append($dominios);

                return $tr;
            }

            function carregarContratos(faixa, titulo, descricao, cor) {
                var $corpo = $('#contratos_modal_corpo');

                $corpo.html('<p class="text-muted mb-0"><i class="fa fa-spinner fa-spin"></i> Carregando os contratos...</p>');
                $('#contratos_modal_resumo').text(descricao);

                $.post('<?php echo base_url('painel/json_getcontratoscard'); ?>', {
                    faixa: faixa
                }, null, 'json').done(function(retorno) {
                    if (!retorno || !retorno.success) {
                        $corpo.html($('<div class="alert alert-danger mb-0"><div class="alert-message"></div></div>')
                            .find('.alert-message').text(retorno && retorno.message ? retorno.message : 'Não foi possível carregar os contratos.').end());
                        return;
                    }

                    if (!retorno.data.contratos.length) {
                        $corpo.html($('<div class="alert alert-success mb-0"><div class="alert-message"></div></div>')
                            .find('.alert-message').text('Nenhum contrato nesta faixa.').end());
                        $('#contratos_modal_resumo').text('0 contrato(s) · ' + descricao);
                        return;
                    }

                    var $thead = $('<thead></thead>').append($('<tr></tr>'));
                    $.each(colunas, function(i, rotulo) {
                        $thead.find('tr').append($('<th></th>').text(rotulo));
                    });

                    var $tbody = $('<tbody></tbody>');
                    $.each(retorno.data.contratos, function(i, c) {
                        $tbody.append(linhaContrato(c, cor));
                    });

                    $corpo.html($('<div class="table-responsive"></div>').append(
                        $('<table class="table table-sm table-striped table-bordered table-hover"></table>')
                        .append($thead).append($tbody)));
                    $corpo.append('<p class="text-muted mb-0"><small>Somente leitura. O espaço contratado e os domínios são editados dentro do contrato. O uso soma só os domínios com correspondência nos servidores.</small></p>');

                    $('#contratos_modal_resumo').text(retorno.data.total + ' contrato(s) · ' + descricao);
                }).fail(function() {
                    $corpo.html('<div class="alert alert-danger mb-0"><div class="alert-message">Falha de comunicação ao carregar os contratos.</div></div>');
                });
            }

            // Um botão por indicador: cada um abre a lista da SUA faixa.
            $('.js-ver-contratos').on('click', function() {
                var $botao = $(this);
                // A cor da barra do modal é a do indicador que abriu, para a lista
                // continuar falando a mesma língua do card.
                var cor = ($botao.attr('class').match(/btn-outline-(\w+)/) || [])[1] || 'primary';

                $('#contratos_modal_titulo').text($botao.data('titulo'));
                $('#modal_contratos_espaco').modal('show');

                carregarContratos($botao.data('faixa'), $botao.data('titulo'), $botao.data('descricao'), cor);
            });
        });
    </script>
<?php } ?>

<div class="row">
    <div class="col-12 d-flex">
        <div class="card flex-fill">
            <div class="card-body py-3">
                <h5 class="card-title mb-1">Contratos vigentes por tipo de serviço</h5>
                <p class="text-muted mb-3"><small>Quantidade de contratos vigentes que possuem cada tipo de serviço. Um contrato com mais de um tipo aparece em cada um deles.</small></p>

                <?php if (!empty($ind_por_servico)) { ?>
                    <?php foreach ($ind_por_servico as $servico) {
                        $total = (int) $servico->total;
                        // Largura relativa ao maior tipo (não ao total de
                        // contratos): o gráfico compara os tipos entre si.
                        // Piso de 3%: com uma diferença grande entre o maior e
                        // o menor, a barra do menor arredondaria para 0 e o
                        // tipo pareceria não ter contrato nenhum.
                        $percent = ($maior_servico > 0) ? max(3, round(($total / $maior_servico) * 100)) : 0;
                    ?>
                        <div class="row align-items-center g-2 mb-2">
                            <div class="col-5 col-md-4 text-truncate" title="<?php echo htmlspecialchars($servico->service_type_name, ENT_QUOTES, 'UTF-8'); ?>">
                                <small><?php echo htmlspecialchars($servico->service_type_name, ENT_QUOTES, 'UTF-8'); ?></small>
                            </div>
                            <div class="col">
                                <div class="progress" style="height: 10px;">
                                    <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo (int) $percent; ?>%;" aria-valuenow="<?php echo $total; ?>" aria-valuemin="0" aria-valuemax="<?php echo $maior_servico; ?>" aria-label="<?php echo htmlspecialchars($servico->service_type_name, ENT_QUOTES, 'UTF-8'); ?>"></div>
                                </div>
                            </div>
                            <div class="col-auto text-end" style="min-width: 44px;">
                                <small class="fw-bold"><?php echo numero($total); ?></small>
                            </div>
                        </div>
                    <?php } ?>
                <?php } else { ?>
                    <div class="alert alert-secondary mb-0" role="alert">
                        <div class="alert-message">Nenhum contrato vigente com tipo de serviço nesta empresa.</div>
                    </div>
                <?php } ?>
            </div>
        </div>
    </div>
</div>