<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The invoice itself — a printable page, not a PDF.
 *
 * WHY HTML AND NOT A GENERATED PDF. A PDF here means a new composer
 * dependency, a font stack that has to render Cyrillic correctly on a server
 * nobody looks at, and a rendering path that can break silently months after
 * the last person tested it. A print stylesheet gives the same document
 * through the browser's own „print to PDF", which every seller already has and
 * which renders their own fonts correctly by definition.
 *
 * If a machine-readable PDF is needed later — for an accounting import, say —
 * it becomes a second rendering of the same frozen row, not a reason to have
 * built one now.
 *
 * NOT A LIVEWIRE COMPONENT: nothing on this page changes, and a document that
 * carries site chrome, a header and a JavaScript runtime is a document that
 * prints badly.
 */
class ShowInvoice
{
    public function __invoke(Request $request, Invoice $invoice): View
    {
        /*
         * Yours, or an admin's. 404 rather than 403 for the same reason the
         * moderation routes do it: a 403 confirms the document exists.
         */
        abort_unless(
            $invoice->user_id === $request->user()?->id || $request->user()?->is_admin,
            404,
        );

        return view('invoice', [
            'invoice' => $invoice->load('payment'),
        ]);
    }
}
