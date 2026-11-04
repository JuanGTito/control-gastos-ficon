<?php

namespace App\Modules\Audit\Http;

use App\Modules\Audit\Models\AuditEntry;
use Illuminate\Http\Request;

class AuditController
{
    public function __invoke(Request $request)
    {
        abort_unless($request->user()->isSuperadmin() || $request->user()->role === 'accountant', 403);
        $entries = AuditEntry::with('actor')->orderByDesc('id')->paginate(20);
        $expense = null;

        return view('audit.history', compact('entries', 'expense'));
    }
}
