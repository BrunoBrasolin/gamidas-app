@php
    use App\Enums\AttentionType;

    $total = count($typeList);
    $label = fn (string $type): string => AttentionType::from($type)->label();
    $short = fn (string $text): string => str_replace('Atenção ', '', $text);

    // Same bar as the page: a track with the percentage filled in.
    $bar = function (?int $percent, string $color) use ($format): string {
        $width = max(0, min(100, $percent ?? 0));
        $fill = $width > 0
            ? '<div style="width: '.$width.'%; height: 7px; background: '.$color.'; border-radius: 4px;"></div>'
            : '';

        return '<table style="width: 100%;" cellspacing="0" cellpadding="0"><tr>'
            .'<td style="padding: 0;"><div style="height: 7px; background: #f0f0f0; border-radius: 4px;">'.$fill.'</div></td>'
            .'<td style="width: 34px; padding: 0 0 0 5px; text-align: right;">'.e($format::rate($percent)).'</td>'
            .'</tr></table>';
    };

    $description = collect([
        'Código '.$game->code,
        $game->created_at ? 'criada em '.$game->created_at->format('d/m/Y H:i') : null,
        $game->creator ? 'por '.$game->creator->firstName() : null,
    ])->filter()->implode(' · ');

    $stats = [
        ['Participantes', $summary['players'], $summary['finished'].' concluíram · '.$summary['playing'].' jogando', 'users'],
        ['Pontuação média', $format::points($summary['average_score']), 'Melhor: '.$format::points($summary['best_score']).' de '.$format::points($summary['max_score']), 'trophy'],
        ['Desafios feitos certo', $format::rate($summary['success_rate']), 'Precisão média '.$format::rate($summary['average_accuracy']), 'target'],
        ['Tipos reconhecidos', $format::rate($summary['concept_rate']), 'Acertou qual atenção usou', 'lightbulb'],
        ['Tempo médio de jogo', $format::elapsed($summary['average_elapsed_seconds']), 'De entrar até o último desafio', 'clock'],
        ['Reação média', $summary['average_reaction_ms'] === null ? '—' : $summary['average_reaction_ms'].' ms', 'No “Mudou a regra!”', 'zap'],
    ];
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $game->title }} · Jogo da Atenção</title>
    <style>
        @page { margin: 28px 30px 44px 30px; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5px; color: #0a0a0a; line-height: 1.35; }
        h1 { font-size: 19px; margin: 0 0 3px 0; font-weight: bold; }
        .muted { color: #737373; }
        .small { font-size: 7.5px; }
        .right { text-align: right; }
        .center { text-align: center; }
        .bold { font-weight: bold; }
        .card { border: 1px solid #e5e5e5; border-radius: 8px; padding: 11px 12px; margin-bottom: 11px; }
        .card-title { font-size: 11px; font-weight: bold; margin: 0 0 2px 0; }
        .card-desc { font-size: 7.5px; color: #737373; margin: 0 0 8px 0; }
        .badge { border: 1px solid #e5e5e5; border-radius: 6px; padding: 1px 5px; font-size: 7px; font-weight: bold; }
        .badge-solid { background: #171717; color: #fafafa; border-color: #171717; }
        .badge-soft { background: #f5f5f5; color: #171717; }
        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th { text-align: left; font-weight: bold; color: #404040; padding: 5px 4px; border-bottom: 1px solid #d4d4d4; }
        table.grid td { padding: 5px 4px; border-bottom: 1px solid #ececec; vertical-align: middle; }
        table.grid thead { display: table-header-group; }
        table.inner { width: 100%; border-collapse: collapse; }
        table.inner td { padding: 5px 4px; border-bottom: 1px solid #ececec; vertical-align: middle; }
        table.inner td.round { border: 1px solid #ffffff; border-bottom: 1px solid #ececec; font-size: 7px; vertical-align: top; padding: 4px 6px 6px 6px; }
        table.inner td.right { text-align: right; }
        table.grid tr { page-break-inside: avoid; }
        table.grid th.right, table.grid td.right { text-align: right; }
        table.grid th.center, table.grid td.center { text-align: center; }
        .tile { width: 18px; height: 18px; border-radius: 5px; text-align: center; padding-top: 3px; }
        .stat { border: 1px solid #e5e5e5; border-radius: 8px; padding: 9px 10px; }
        .stat-value { font-size: 16px; font-weight: bold; margin: 3px 0 2px 0; }
        .cell { display: inline-block; min-width: 24px; border-radius: 5px; padding: 3px 7px; text-align: center; }
        .detail { background: #fafafa; }
        table.grid td.round { border: 1px solid #ffffff; border-bottom: 1px solid #ececec; font-size: 7px; vertical-align: top; padding: 4px 6px 6px 6px; }
        .ok { color: #047857; }
        .bad { color: #be123c; }
    </style>
</head>
<body>
    {{-- Header --}}
    <table style="width: 100%; margin-bottom: 11px;" cellspacing="0" cellpadding="0">
        <tr>
            <td style="vertical-align: top;">
                <h1>{{ $game->title }}</h1>
                <div class="muted">{{ $description }}</div>
            </td>
            <td class="right" style="vertical-align: top; width: 90px;">
                <span class="badge {{ $game->isOpen() ? 'badge-solid' : 'badge-soft' }}">{{ $game->isOpen() ? 'Aberta' : 'Encerrada' }}</span>
                <div class="muted small" style="margin-top: 4px;">Gerado em {{ now()->format('d/m/Y H:i') }}</div>
            </td>
        </tr>
    </table>

    <div class="card" style="padding: 8px 12px;">
        <span class="muted">Link para os jogadores:</span>
        <span class="bold" style="font-family: 'DejaVu Sans Mono', monospace;">{{ route('game.show', $game->code) }}</span>
    </div>

    {{-- Numbers --}}
    <table style="width: 100%; margin: 0 -4px 7px -4px; border-collapse: separate; border-spacing: 4px;" cellspacing="4" cellpadding="0">
        @foreach (array_chunk($stats, 3) as $row)
            <tr>
                @foreach ($row as [$title, $value, $hint, $icon])
                    <td style="width: 33.3%; vertical-align: top;">
                        <div class="stat">
                            <table style="width: 100%;" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td class="muted" style="font-size: 8px;">{{ $title }}</td>
                                    <td class="right" style="width: 14px;"><img src="{{ $icons::uri($icon, '#737373') }}" style="width: 11px; height: 11px;"></td>
                                </tr>
                            </table>
                            <div class="stat-value">{{ $value }}</div>
                            <div class="muted small">{{ $hint }}</div>
                        </div>
                    </td>
                @endforeach
            </tr>
        @endforeach
    </table>

    @if ($summary['players'] === 0)
        <div class="card center" style="padding: 26px 12px;">
            <div class="bold" style="font-size: 11px;">Ninguém entrou ainda</div>
            <div class="muted" style="margin-top: 3px;">Nenhum jogador participou desta sala até agora.</div>
        </div>
    @else
        {{-- Performance by kind of attention --}}
        <div class="card">
            <div class="card-title">Desempenho por tipo de atenção</div>
            <div class="card-desc">“Fez o desafio” mede a execução prática; “Reconheceu o tipo” mede se a pessoa identificou qual atenção estava usando — o objetivo do quiz.</div>
            <table class="grid">
                <thead>
                    <tr>
                        <th>Tipo</th>
                        <th style="width: 19%;">Fez o desafio</th>
                        <th style="width: 19%;">Reconheceu o tipo</th>
                        <th class="right">Precisão</th>
                        <th class="right">Tempo</th>
                        <th class="right">Erros / omissões</th>
                        <th class="right">Pontos</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($types as $row)
                        @php $type = AttentionType::from($row['type']); @endphp
                        <tr>
                            <td>
                                <table cellspacing="0" cellpadding="0">
                                    <tr>
                                        <td style="padding: 0 6px 0 0; border: 0; width: 18px;">
                                            <div class="tile" style="background: {{ $type->color() }};"><img src="{{ $icons::uri($row['type'], '#ffffff') }}" style="width: 11px; height: 11px;"></div>
                                        </td>
                                        <td style="padding: 0; border: 0;">
                                            <div class="bold">{{ $row['label'] }}</div>
                                            <div class="muted small">{{ $row['challenge'] }}</div>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                            <td>{!! $bar($row['success_rate'], $type->color()) !!}</td>
                            <td>{!! $bar($row['concept_rate'], '#171717') !!}</td>
                            <td class="right">{{ $format::rate($row['average_accuracy']) }}</td>
                            <td class="right">
                                @if ($row['type'] === 'alternating')
                                    {{ $row['average_reaction_ms'] === null ? '—' : $row['average_reaction_ms'].' ms' }}
                                @else
                                    {{ $format::seconds($row['average_duration_ms']) }}
                                @endif
                            </td>
                            <td class="right">{{ $row['average_errors'] === null ? '—' : $format::average($row['average_errors']).' / '.$format::average($row['average_misses'] ?? 0) }}</td>
                            <td class="right">{{ $format::points($row['average_points']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="muted small" style="margin-top: 7px;">Tempo: médio para concluir ou responder; na atenção alternada, tempo médio de reação aos alvos. Erros e omissões são médias por jogador.</div>
        </div>

        {{-- What each challenge was called --}}
        <div class="card">
            <div class="card-title">O que cada desafio foi chamado</div>
            <div class="card-desc">Linhas: o tipo certo do desafio. Colunas: o que os jogadores responderam. Fora da diagonal estão as confusões entre os conceitos.</div>
            <table class="grid">
                <thead>
                    <tr>
                        <th>Desafio</th>
                        @foreach ($typeList as $type)
                            <th class="center small">{{ $short($type->label()) }}</th>
                        @endforeach
                        <th class="center small">Sem resposta</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($types as $row)
                        <tr>
                            <td class="bold">{{ $short($row['label']) }}</td>
                            @foreach ([...array_map(fn ($type) => $type->value, $typeList), 'none'] as $column)
                                @php
                                    $count = $row['guesses'][$column];
                                    $share = $row['rounds'] === 0 ? 0 : $count / $row['rounds'];
                                    $right = $column === $row['type'];
                                    $style = match (true) {
                                        $count === 0 => 'color: #737373;',
                                        $right => 'background: #d1fae5; color: #047857; font-weight: bold;',
                                        $share >= 0.25 => 'background: #fecdd3; color: #be123c; font-weight: bold;',
                                        default => 'background: #fef3c7; color: #b45309;',
                                    };
                                @endphp
                                <td class="center"><span class="cell" style="{{ $style }}">{{ $count }}</span></td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Players --}}
        <div class="card" style="page-break-before: always;">
            <div class="card-title">Jogadores</div>
            <div class="card-desc">Ordenados pela pontuação (empate: quem terminou mais rápido). Cada linha traz o resultado de cada desafio.</div>
            {{-- Ten fixed columns: the figures use them one by one, each challenge box takes two. --}}
            <table class="grid" style="table-layout: fixed;">
                <colgroup>
                    @foreach ([5, 15, 10, 10, 10, 10, 10, 10, 10, 10] as $width)
                        <col style="width: {{ $width }}%;">
                    @endforeach
                </colgroup>
                <thead>
                    <tr>
                        <th>#</th>
                        <th colspan="2">Apelido</th>
                        <th class="right">Pontos</th>
                        <th class="right">Desafios certos</th>
                        <th class="right">Tipos reconhecidos</th>
                        <th class="right">Erros</th>
                        <th class="right">Omissões</th>
                        <th class="right" colspan="2">Tempo total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($players as $player)
                        @php $line = $loop->index < $detailLimit ? 'border-bottom: 0;' : ''; @endphp
                        {{-- One cell per player keeps the figures and the challenge boxes on the same page. --}}
                        <tr>
                        <td colspan="10" style="padding: 0; border: 0; page-break-inside: avoid;">
                        <table class="inner" style="table-layout: fixed;">
                            <colgroup>
                                @foreach ([5, 15, 10, 10, 10, 10, 10, 10, 10, 10] as $width)
                                    <col style="width: {{ $width }}%;">
                                @endforeach
                            </colgroup>
                        <tr>
                            <td class="bold" style="{{ $line }}">{{ $player['position'] }}</td>
                            <td colspan="2" style="{{ $line }}">
                                {{ $player['nickname'] }}
                                @unless ($player['finished'])
                                    <span class="badge">jogando {{ $player['answered'] }}/{{ $total }}</span>
                                @endunless
                            </td>
                            <td class="right bold" style="{{ $line }}">{{ $format::points($player['score']) }}</td>
                            <td class="right" style="{{ $line }}">{{ $player['correct_rounds'] }}/{{ $total }}</td>
                            <td class="right" style="{{ $line }}">{{ $player['concept_hits'] }}/{{ $total }}</td>
                            <td class="right" style="{{ $line }}">{{ $player['errors'] }}</td>
                            <td class="right" style="{{ $line }}">{{ $player['misses'] }}</td>
                            <td class="right" colspan="2" style="{{ $line }}">{{ $format::elapsed($player['elapsed_seconds']) }}</td>
                        </tr>
                        @if ($loop->index < $detailLimit)
                        <tr>
                            @foreach ($typeList as $type)
                                @php $round = collect($player['rounds'])->firstWhere('type', $type->value); @endphp
                                <td colspan="2" class="detail round">
                                    <b>{{ $type->label() }}</b><br>
                                    @if ($round)
                                        <span class="{{ $round['correct'] ? 'ok' : 'bad' }}"><b>{{ $round['correct'] ? '✓' : '✕' }}</b></span> {{ $format::roundDetail($round) }}<br>
                                        <span class="muted">Precisão {{ $round['accuracy'] }}% · {{ $round['type'] === 'alternating' && $round['reaction_ms'] !== null ? 'reação '.$round['reaction_ms'].' ms' : $format::seconds($round['duration_ms']) }}</span><br>
                                        <span class="{{ $round['guess_correct'] ? 'ok' : 'bad' }}">Respondeu: {{ $round['guess'] ? $label($round['guess']) : 'sem resposta' }}</span><br>
                                        <b>{{ $round['points'] }} pontos</b>
                                    @else
                                        <span class="muted">Ainda não jogou</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                        @endif
                        </table>
                        </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if (count($players) > $detailLimit)
                <div class="muted small" style="margin-top: 7px;">O resultado de cada desafio aparece só para os {{ $detailLimit }} primeiros; a planilha traz todos os jogadores.</div>
            @endif
        </div>
    @endif

    <div class="muted small">Demonstração didática dos conceitos, não um teste psicológico validado. Só o apelido de cada jogador é registrado.</div>
</body>
</html>
