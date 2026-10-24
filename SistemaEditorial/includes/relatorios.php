<?php
/**
 * Helpers compartilhados pelos relatórios editoriais (relatorio_*.php).
 */
require_once __DIR__ . '/permissoes.php';
require_once __DIR__ . '/periodo.php';
require_once __DIR__ . '/csv.php';

// Quantas linhas aparecem na tela; o CSV sempre leva todas.
const LIMITE_LINHAS_TELA = 500;

const ROTULOS_STATUS_LIVRO = [
    'em_andamento' => 'Em andamento',
    'concluido'    => 'Concluído',
    'extraviado'   => 'Extraviado',
    'cancelado'    => 'Cancelado',
];

const ROTULOS_TIPO_MOVIMENTACAO = [
    'avanco'            => 'Avanço',
    'repeticao'         => 'Repetição',
    'pulo_opcional'     => 'Pulo (opcional)',
    'retrocesso'        => 'Retrocesso',
    'troca_responsavel' => 'Troca de responsável',
    'resgate'           => 'Resgate',
];

function exigirPermissaoRelatorios(): void
{
    if (!usuarioTemPermissao('relatorios.ver')) {
        $_SESSION['flash'] = ['type' => 'erro', 'message' => 'Você não tem permissão para ver relatórios.'];
        header('Location: index.php');
        exit;
    }
}

function rotuloStatusLivro(string $status): string
{
    return ROTULOS_STATUS_LIVRO[$status] ?? $status;
}

function rotuloTipoMovimentacao(string $tipo): string
{
    return ROTULOS_TIPO_MOVIMENTACAO[$tipo] ?? $tipo;
}

function dataValidaIso(string $valor): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $valor);
    return $d !== false && $d->format('Y-m-d') === $valor;
}

/**
 * Lê filtro_de / filtro_ate do GET. Retorna [de, ate, erro]. Formato inválido
 * ou intervalo invertido vira erro (e o relatório não executa), em vez de ser
 * corrigido em silêncio.
 */
function lerPeriodoGet(): array
{
    $de = trim($_GET['filtro_de'] ?? '');
    $ate = trim($_GET['filtro_ate'] ?? '');
    $erro = null;
    if (($de !== '' && !dataValidaIso($de)) || ($ate !== '' && !dataValidaIso($ate))) {
        $erro = 'Informe datas válidas no filtro de período.';
    } elseif ($de !== '' && $ate !== '' && $de > $ate) {
        $erro = 'A data inicial não pode ser maior que a data final.';
    }
    return [$de, $ate, $erro];
}

function formatarDataHoraBr(?string $valor): string
{
    return $valor ? date('d/m/Y H:i', strtotime($valor)) : '';
}

function formatarDecimalBr(float $valor): string
{
    return number_format($valor, 1, ',', '.');
}

// Dias inteiros entre duas datas, calculados em PHP (DateTime::diff) e não com
// DATEDIFF/NOW() do MySQL — mesma cautela de fuso documentada nas outras telas.
function diasEntre(string $inicio, ?string $fim = null): int
{
    $fimDt = $fim !== null ? new DateTime($fim) : new DateTime();
    return (int)(new DateTime($inicio))->diff($fimDt)->days;
}

function nomeArquivoCsv(string $base, string $de, string $ate): string
{
    $sufixo = ($de !== '' || $ate !== '') ? '_' . ($de !== '' ? $de : 'inicio') . '_a_' . ($ate !== '' ? $ate : 'hoje') : '';
    return $base . $sufixo . '.csv';
}

/**
 * Query string dos filtros ativos (sem valores vazios), para montar o link do CSV.
 */
function queryFiltros(array $filtros, array $extra = []): string
{
    $q = array_filter($filtros + $extra, fn($v) => $v !== '' && $v !== 0 && $v !== null);
    return http_build_query($q);
}

/**
 * Permanências (estadias) de livros em etapas, no período.
 *
 * Uma "permanência" é o tempo contínuo do livro numa etapa: começa numa
 * movimentação de avanço/repetição/retrocesso/resgate e segue enquanto vierem
 * trocas de responsável (mesma etapa). Trocar de mesa NÃO é uma passagem nova —
 * contar cada linha de movimentação separada inflaria "passagens" e encurtaria
 * a média. Pulos de etapa opcional têm duração zero e não são permanência.
 * O período filtra pela data de ENTRADA da permanência. Permanência ainda
 * aberta conta até agora (e é marcada como em andamento).
 */
function calcularPermanencias(mysqli $conn, string $de, string $ate): array
{
    $r = $conn->query("
        SELECT m.id, m.livro_id, m.etapa_id, m.tipo, m.rodada, m.excedente,
               m.data_entrada, m.data_saida,
               l.titulo, e.nome AS etapa_nome, e.ordem AS etapa_ordem, u.nome AS responsavel_nome
        FROM movimentacoes m
        JOIN livros l ON l.id = m.livro_id
        JOIN etapas e ON e.id = m.etapa_id
        JOIN usuarios u ON u.id = m.responsavel_id
        ORDER BY m.livro_id, m.data_entrada, m.id
    ");

    $agora = new DateTime();
    $permanencias = [];
    $atual = null;

    $fechar = function () use (&$atual, &$permanencias, $agora) {
        if ($atual === null) return;
        $fim = $atual['saida'] !== null ? new DateTime($atual['saida']) : $agora;
        $atual['segundos'] = max(0, $fim->getTimestamp() - (new DateTime($atual['entrada']))->getTimestamp());
        $atual['em_aberto'] = $atual['saida'] === null;
        $permanencias[] = $atual;
        $atual = null;
    };

    while ($m = $r->fetch_assoc()) {
        if ($atual !== null && (int)$atual['livro_id'] !== (int)$m['livro_id']) {
            $fechar();
        }
        if ($m['tipo'] === 'pulo_opcional') {
            continue;
        }
        if ($m['tipo'] === 'troca_responsavel' && $atual !== null && (int)$atual['etapa_id'] === (int)$m['etapa_id']) {
            $atual['saida'] = $m['data_saida'];
            $atual['responsavel_nome'] = $m['responsavel_nome'];
            continue;
        }
        $fechar();
        $atual = [
            'livro_id' => (int)$m['livro_id'], 'titulo' => $m['titulo'],
            'etapa_id' => (int)$m['etapa_id'], 'etapa_nome' => $m['etapa_nome'], 'etapa_ordem' => (int)$m['etapa_ordem'],
            'tipo' => $m['tipo'], 'rodada' => (int)$m['rodada'], 'excedente' => (int)$m['excedente'],
            'entrada' => $m['data_entrada'], 'saida' => $m['data_saida'],
            'responsavel_nome' => $m['responsavel_nome'],
        ];
    }
    $fechar();

    return array_values(array_filter($permanencias, function ($p) use ($de, $ate) {
        $diaEntrada = substr($p['entrada'], 0, 10);
        return ($de === '' || $diaEntrada >= $de) && ($ate === '' || $diaEntrada <= $ate);
    }));
}
