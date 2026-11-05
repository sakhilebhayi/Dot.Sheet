@php
    $cellStyle = function (array $cell): string {
        $x = $cell['x'] ?? [];
        $rules = [];
        if ($x['bold'] ?? false) { $rules[] = 'font-weight:700'; }
        if ($x['italic'] ?? false) { $rules[] = 'font-style:italic'; }
        $lines = array_keys(array_filter(['underline' => $x['underline'] ?? false, 'line-through' => $x['strike'] ?? false]));
        if ($lines !== []) { $rules[] = 'text-decoration:'.implode(' ', $lines); }
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($x['font_color'] ?? ''))) { $rules[] = 'color:'.$x['font_color']; }
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($x['bg_color'] ?? ''))) { $rules[] = 'background:'.$x['bg_color']; }
        if (in_array($x['align'] ?? null, ['left', 'center', 'right'], true)) { $rules[] = 'text-align:'.$x['align']; }
        elseif (preg_match('/^[−-]?[R$€£]?\s?[\d\s.,]+%?$/u', $cell['text']) === 1) { $rules[] = 'text-align:right'; }

        return implode(';', $rules);
    };
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $spreadsheet->name }}: {{ $tab->name }}</title>
    <style>
        @page { margin: 12mm; }
        body { margin: 0; color: #16201b; font-family: DejaVu Sans, Helvetica, Arial, sans-serif; font-size: 10px; }
        h1 { margin: 0 0 2px; font-size: 14px; }
        p { margin: 0 0 10px; color: #56645d; }
        table { border-collapse: collapse; }
        td { padding: 3px 6px; border: 1px solid #d6dcd8; vertical-align: top; }
        .press { margin: 12px 0; }
        @media print { .press { display: none; } }
    </style>
</head>
<body>
    <h1>{{ $spreadsheet->name }}</h1>
    <p>{{ $tab->name }}</p>
    @unless ($forPdf)
        <div class="press"><button type="button">Print</button></div>
        <script nonce="{{ Illuminate\Support\Facades\Vite::cspNonce() }}">document.querySelector('.press button').addEventListener('click', () => print());</script>
    @endunless
    @if ($page['too_large'])
        <p>This sheet is too large to lay out for paper. Download it as Excel instead.</p>
    @else
        <table>
            @foreach ($page['rows'] as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td @if ($cell['colspan'] > 1) colspan="{{ $cell['colspan'] }}" @endif @if ($cell['rowspan'] > 1) rowspan="{{ $cell['rowspan'] }}" @endif style="{{ $cellStyle($cell) }}">{{ $cell['text'] }}</td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
