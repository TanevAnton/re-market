{{-- The document.

     STANDALONE ON PURPOSE — no site header, no navigation, no Livewire. This
     is a thing that gets printed and filed, and everything the rest of the
     site puts around a page is noise on paper.

     Every value here comes from the frozen columns on the invoice and the
     payment, never from config or from a live join. An invoice issued last
     year must still say what it said last year, even if the company has since
     changed its address or registered for VAT. --}}
<!DOCTYPE html>
<html lang="bg">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Фактура № {{ $invoice->number }}</title>

    <style>
        /* Deliberately plain and self-contained: a document that depends on
           the site's build output is a document that breaks the week somebody
           changes a Tailwind class. Black on white, because that is what a
           printer does anyway. */
        :root { color-scheme: light; }

        body {
            margin: 0;
            padding: 32px 24px;
            background: #fff;
            color: #111;
            font: 14px/1.5 -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }

        .sheet { max-width: 720px; margin: 0 auto; }

        h1 { margin: 0 0 2px; font-size: 20px; letter-spacing: -0.01em; }

        .muted  { color: #555; font-size: 12px; }
        .mono   { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
        .right  { text-align: right; }

        .head   { display: flex; flex-wrap: wrap; gap: 24px; justify-content: space-between;
                  align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 16px; }

        .parties { display: flex; flex-wrap: wrap; gap: 32px; margin-top: 24px; }
        .party   { flex: 1 1 240px; }
        .party h2 { margin: 0 0 6px; font-size: 11px; text-transform: uppercase;
                    letter-spacing: 0.08em; color: #555; }
        .party pre { margin: 0; font: inherit; white-space: pre-wrap; }

        table { width: 100%; border-collapse: collapse; margin-top: 28px; }
        th, td { padding: 8px 0; border-bottom: 1px solid #ddd; text-align: left; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: #555; }
        td.right, th.right { text-align: right; }

        .totals { margin-top: 16px; margin-left: auto; width: 260px; }
        .totals div { display: flex; justify-content: space-between; padding: 4px 0; }
        .totals .grand { border-top: 2px solid #111; margin-top: 4px; padding-top: 8px;
                         font-weight: 700; font-size: 16px; }

        .note { margin-top: 24px; border-top: 1px solid #ddd; padding-top: 12px; }

        .print-hint { margin-top: 32px; }

        @media print {
            body { padding: 0; }
            .print-hint { display: none; }
        }
    </style>
</head>
<body>
<div class="sheet">

    <div class="head">
        <div>
            <h1>Фактура № {{ $invoice->number }}</h1>
            <p class="muted" style="margin:0">
                Дата на издаване: {{ $invoice->issued_on->format('d.m.Y') }}
            </p>
        </div>

        <div class="right">
            <p class="muted" style="margin:0">Плащане</p>
            <p class="mono" style="margin:0">{{ $invoice->payment->reference }}</p>
            <p class="muted" style="margin:4px 0 0">
                {{ $invoice->payment->provider === \App\Models\Payment::BANK
                    ? 'Банков превод' : $invoice->payment->provider }}
            </p>
        </div>
    </div>

    <div class="parties">
        {{-- Both sides frozen. The issuer block was copied at issue time, so a
             later change of address cannot rewrite a document already filed. --}}
        <div class="party">
            <h2>Доставчик</h2>
            <pre>{{ $invoice->issuer }}</pre>
        </div>

        <div class="party">
            <h2>Получател</h2>
            <pre>{{ collect([
                $invoice->payment->bill_to_name,
                $invoice->payment->bill_to_eik ? 'ЕИК: '.$invoice->payment->bill_to_eik : null,
                $invoice->payment->bill_to_vat ? 'ДДС №: '.$invoice->payment->bill_to_vat : null,
                $invoice->payment->bill_to_address.', '.$invoice->payment->bill_to_city,
                $invoice->payment->bill_to_person ? 'МОЛ: '.$invoice->payment->bill_to_person : null,
            ])->filter()->implode("\n") }}</pre>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Описание</th>
                <th class="right">Количество</th>
                <th class="right">Сума</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    Кредит за платена видимост в {{ config('app.name') }}
                    <br><span class="muted">Услуга, използваема само в рамките на платформата</span>
                </td>
                <td class="right mono">1</td>
                <td class="right mono">{{ \App\Models\Invoice::money($invoice->net_cents) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="totals">
        <div>
            <span class="muted">Данъчна основа</span>
            <span class="mono">{{ \App\Models\Invoice::money($invoice->net_cents) }}</span>
        </div>
        <div>
            <span class="muted">ДДС {{ rtrim(rtrim(number_format((float) $invoice->vat_rate, 2, ',', ''), '0'), ',') }}%</span>
            <span class="mono">{{ \App\Models\Invoice::money($invoice->vat_cents) }}</span>
        </div>
        <div class="grand">
            <span>Общо</span>
            <span class="mono">{{ $invoice->formattedTotal() }}</span>
        </div>
    </div>

    {{-- The ground for charging no VAT, when there is none charged. A document
         that simply omits the subject is not a document an accountant accepts
         — see App\Support\BillingIdentity for why this is required config
         rather than something with a default. --}}
    @if ($invoice->vat_note)
        <div class="note">
            <p style="margin:0">{{ $invoice->vat_note }}</p>
        </div>
    @endif

    <div class="note">
        <p class="muted" style="margin:0">
            Платено на {{ optional($invoice->payment->confirmed_at)->format('d.m.Y') }}.
            Документът е издаден електронно и е валиден без подпис и печат.
        </p>
    </div>

    <div class="print-hint">
        <button type="button" onclick="window.print()">Разпечатай / запази като PDF</button>
    </div>
</div>
</body>
</html>
