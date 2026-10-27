<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Relatório — {{ $activity->title }}</title>
    <style>
        @page { margin: 2.2cm 2cm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11pt; line-height: 1.5; color: #1f2937; }
        header { border-bottom: 1px solid #d1d5db; padding-bottom: 10pt; margin-bottom: 16pt; }
        header h1 { font-size: 17pt; margin: 0 0 6pt; }
        header table { border-collapse: collapse; font-size: 9.5pt; color: #4b5563; }
        header td { padding: 1pt 14pt 1pt 0; border: none; }
        h1, h2, h3, h4 { line-height: 1.25; margin: 14pt 0 6pt; }
        h1 { font-size: 15pt; } h2 { font-size: 13pt; } h3, h4 { font-size: 11.5pt; }
        p { margin: 0 0 8pt; text-align: justify; }
        ul, ol { margin: 0 0 8pt; padding-left: 18pt; }
        li { margin-bottom: 3pt; }
        a { color: #1d4ed8; }
        blockquote { margin: 0 0 8pt; padding-left: 10pt; border-left: 3px solid #d1d5db; color: #4b5563; }
        code { font-family: 'DejaVu Sans Mono', monospace; font-size: 9.5pt; background: #f3f4f6; padding: 0 2pt; }
        pre { background: #f3f4f6; padding: 8pt; font-size: 9pt; white-space: pre-wrap; }
        pre code { background: none; padding: 0; }
        .report table { border-collapse: collapse; width: 100%; margin-bottom: 8pt; }
        .report th, .report td { border: 1px solid #d1d5db; padding: 4pt 6pt; text-align: left; }
        hr { border: none; border-top: 1px solid #d1d5db; margin: 12pt 0; }
    </style>
</head>
<body>
    <header>
        <h1>{{ $activity->title }}</h1>
        <table>
            <tr>
                <td><strong>Aluno(a):</strong> {{ $student }}</td>
                <td><strong>Carga horária:</strong> {{ $hours }}</td>
                @if ($activity->completed_on)
                    <td><strong>Data:</strong> {{ $activity->completed_on->format('d/m/Y') }}</td>
                @endif
            </tr>
        </table>
    </header>
    <main class="report">
        {!! $body !!}
    </main>
</body>
</html>
