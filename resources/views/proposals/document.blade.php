@php
    use App\Modules\CommercialProposals\Domain\Money;
    use App\Modules\CommercialProposals\Infrastructure\Rendering\EditorJsRenderer;

    /** @var \App\Modules\CommercialProposals\Infrastructure\Persistence\ProposalVersion $version */
    /** @var \App\Modules\CommercialProposals\Infrastructure\Persistence\Proposal $proposal */
    $branding = $version->branding_snapshot ?? [];
    $items = $version->items;
    $sections = $version->sections->filter(fn ($s) => $s->enabled);

    $money = fn (?string $value): string => $value === null ? '' : Money::fromDecimalString($value)->toDisplayString();
    $quantity = fn (string $min, string $max): string => rtrim(rtrim($min, '0'), '.') === rtrim(rtrim($max, '0'), '.')
        ? $min
        : $min.'–'.$max;

    // Произвольная таблица: итог каждой ценовой колонки; в общий итог —
    // только при одной ценовой колонке и явном include_in_total (§7.2).
    $renderTableSection = function (\App\Modules\CommercialProposals\Infrastructure\Persistence\ProposalSection $section) use ($money): string {
        $payload = $section->payload;
        $columns = $payload['columns'] ?? [];
        $rows = $payload['rows'] ?? [];
        $priceColumn = $payload['price_column'] ?? null;

        $columnSums = [];
        if ($priceColumn !== null) {
            $priceIndex = null;
            foreach ($columns as $i => $column) {
                if (($column['key'] ?? null) === $priceColumn) {
                    $priceIndex = $i;
                    break;
                }
            }
            if ($priceIndex !== null) {
                $sum = '0.00';
                foreach ($rows as $row) {
                    $cell = $row[$priceIndex] ?? null;
                    if (is_string($cell) && preg_match('/^\d+(\.\d{1,2})?$/', $cell) === 1) {
                        $sum = bcadd($sum, $cell, 2);
                    }
                }
                $columnSums[$priceIndex] = $sum;
            }
        }

        $html = '<table><thead><tr>';
        foreach ($columns as $i => $column) {
            $html .= '<th>'.e($column['label'] ?? $column['key'] ?? '').'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($columns as $i => $column) {
                $cell = $row[$i] ?? null;
                $text = is_string($cell) ? $cell : '';
                $html .= '<td>'.e($text).'</td>';
            }
            $html .= '</tr>';
        }
        if ($columnSums !== []) {
            $html .= '<tr class="totals">';
            foreach ($columns as $i => $column) {
                $html .= '<td>'.(isset($columnSums[$i]) ? 'Итого: '.$money($columnSums[$i]) : '').'</td>';
            }
            $html .= '</tr>';
        }
        return $html.'</tbody></table>';
    };
@endphp
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $version->title }} — {{ $branding['name'] ?? 'Коммерческое предложение' }}</title>
    <style>
        :root {
            --ink: #202621; --muted: #626B64; --line: #D7DDD5; --surface: #FCFCFA;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--surface); color: var(--ink);
            font-family: 'Onest', system-ui, sans-serif; line-height: 1.55;
        }
        .sheet { max-width: 900px; margin: 0 auto; padding: 48px 32px 64px; }
        header.doc-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; border-bottom: 2px solid var(--ink); padding-bottom: 20px; }
        header.doc-head img { max-height: 72px; max-width: 240px; object-fit: contain; }
        .org { text-align: right; }
        .org .name { font-size: 20px; font-weight: 600; }
        .org .meta { color: var(--muted); font-size: 13px; }
        h1 { font-size: 26px; margin: 32px 0 4px; }
        .subtitle { color: var(--muted); font-size: 15px; margin: 0 0 20px; }
        .doc-meta { font-size: 13px; color: var(--muted); margin-bottom: 28px; }
        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 32px; font-size: 14px; }
        .parties h2 { font-size: 12px; text-transform: none; color: var(--muted); margin: 0 0 6px; font-weight: 500; }
        table.items { width: 100%; border-collapse: collapse; font-size: 14px; margin: 8px 0 32px; }
        table.items th, table.items td { border-bottom: 1px solid var(--line); padding: 8px 10px; text-align: left; vertical-align: top; }
        table.items th { color: var(--muted); font-weight: 500; font-size: 12px; }
        table.items td.num, table.items th.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        table.items tfoot td { font-weight: 600; border-top: 2px solid var(--ink); border-bottom: none; }
        section.block { margin: 0 0 32px; }
        section.block h2 { font-size: 18px; margin: 0 0 12px; }
        section.block ol.stages { margin: 0; padding-left: 20px; }
        section.block ol.stages li { margin-bottom: 6px; }
        section.block ol.stages .duration { color: var(--muted); }
        section.block table { width: 100%; border-collapse: collapse; font-size: 14px; }
        section.block table th, section.block table td { border: 1px solid var(--line); padding: 6px 10px; text-align: left; }
        section.block table tr.totals td { font-weight: 600; background: #F3F5F1; }
        .price-badge { border: 1px solid var(--line); border-left: 4px solid #9C3D2E; padding: 16px 20px; font-size: 16px; background: #fff; }
        .note { border-top: 1px solid var(--line); padding-top: 16px; color: var(--muted); font-size: 13px; margin-top: 40px; white-space: pre-line; }
        footer.doc-foot { margin-top: 48px; padding-top: 16px; border-top: 1px solid var(--line); color: var(--muted); font-size: 12px; display: flex; justify-content: space-between; gap: 16px; }
        a { color: inherit; }
        @media print {
            body { background: #fff; }
            .sheet { max-width: none; padding: 0; }
            a { text-decoration: none; }
        }
    </style>
</head>
<body>
<div class="sheet">
    <header class="doc-head">
        @if(!empty($branding['large_logo']) && !empty($publicToken))
            <img src="{{ route('kp.logo', ['token' => $publicToken, 'kind' => 'large_logo']) }}" alt="{{ $branding['name'] ?? '' }}">
        @endif
        <div class="org">
            <div class="name">{{ $branding['name'] ?? '' }}</div>
            @if(!empty($branding['legal_name']))<div class="meta">{{ $branding['legal_name'] }}</div>@endif
            @if(!empty($branding['city']))<div class="meta">{{ $branding['city'] }}</div>@endif
            @if(!empty($branding['website']))<div class="meta">{{ $branding['website'] }}</div>@endif
        </div>
    </header>

    <h1>{{ $version->title }}</h1>
    @if($version->subtitle)<p class="subtitle">{{ $version->subtitle }}</p>@endif

    <div class="doc-meta">
        @if($proposal->number)№ {{ $proposal->number }}@endif
        @if($version->issued_at) от {{ $version->issued_at->format('d.m.Y') }}@endif
        · версия v{{ $version->version }}
    </div>

    <div class="parties">
        <div>
            <h2>Клиент</h2>
            <div>{{ $version->client_name ?: '—' }}</div>
            @if($version->client_contact)<div>{{ $version->client_contact }}</div>@endif
        </div>
        <div>
            <h2>Контакты менеджера</h2>
            <div>{{ $version->manager_name ?: '—' }}</div>
            @if($version->manager_email)<div>{{ $version->manager_email }}</div>@endif
            @if($version->manager_phone)<div>{{ $version->manager_phone }}</div>@endif
        </div>
    </div>

    @if($items->isNotEmpty())
        <table class="items">
            <thead>
            <tr>
                <th>№</th>
                <th>Работы</th>
                <th class="num">Кол-во</th>
                <th class="num">Цена</th>
                <th class="num">Сумма</th>
            </tr>
            </thead>
            <tbody>
            @foreach($items as $item)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>
                        {{ $item->name }}
                        @if($item->description)<div style="color: var(--muted); font-size: 12px;">{{ $item->description }}</div>@endif
                    </td>
                    <td class="num">{{ $quantity($item->quantity_min, $item->quantity_max) }}@if($item->unit) {{ $item->unit }}@endif</td>
                    <td class="num">{{ $money($item->unit_price) }}</td>
                    <td class="num">{{ $money($item->amount_min) }}@if($item->amount_min !== $item->amount_max) — {{ $money($item->amount_max) }}@endif</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            <tr>
                <td colspan="4">Итого по работам</td>
                <td class="num">{{ $money($version->total_min) }}@if($version->total_min !== $version->total_max) — {{ $money($version->total_max) }}@endif</td>
            </tr>
            </tfoot>
        </table>
    @endif

    @foreach($sections as $section)
        <section class="block">
            @switch($section->type->value)
                @case('stages')
                    @if(!empty($section->payload['title']))<h2>{{ $section->payload['title'] }}</h2>@endif
                    <ol class="stages">
                        @foreach($section->payload['stages'] ?? [] as $stage)
                            <li>{{ $stage['title'] }} — <span class="duration">{{ $stage['duration'] }}</span></li>
                        @endforeach
                    </ol>
                @break

                @case('custom')
                    @if(!empty($section->payload['title']))<h2>{{ $section->payload['title'] }}</h2>@endif
                    {!! EditorJsRenderer::render(['blocks' => $section->payload['blocks'] ?? []]) !!}
                @break

                @case('table')
                    @if(!empty($section->payload['title']))<h2>{{ $section->payload['title'] }}</h2>@endif
                    {!! $renderTableSection($section) !!}
                @break

                @case('license')
                    @if(!empty($section->payload['title']))<h2>{{ $section->payload['title'] }}</h2>@endif
                    <table>
                        <thead><tr><th>Услуга</th><th>Период</th><th class="num" style="text-align: right;">Стоимость</th></tr></thead>
                        <tbody>
                        @foreach($section->payload['items'] ?? [] as $license)
                            <tr>
                                <td>{{ $license['name'] }}</td>
                                <td>{{ $license['period'] }}</td>
                                <td style="text-align: right;">{{ $money($license['price']) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @break

                @case('price_badge')
                    <div class="price-badge">{{ $section->payload['label'] }}</div>
                @break
            @endswitch
        </section>
    @endforeach

    @if($version->note)
        <div class="note">{{ $version->note }}</div>
    @endif

    <footer class="doc-foot">
        <div>{{ $branding['legal_name'] ?? $branding['name'] ?? '' }}</div>
        @if(!empty($branding['city']))<div>{{ $branding['city'] }}</div>@endif
    </footer>
</div>
</body>
</html>
