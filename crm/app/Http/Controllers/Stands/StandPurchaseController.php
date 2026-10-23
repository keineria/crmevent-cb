<?php

namespace App\Http\Controllers\Stands;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\StandPurchase;
use App\Models\StandWorkbook;
use App\Services\Stands\StandPurchases;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StandPurchaseController extends Controller
{
    /** Marks one sold stand as paid, with complete documents or invoiced (or clears the mark). */
    public function update(Request $request, StandWorkbook $workbook): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:191'],
            'flag' => ['required', Rule::in(array_keys(StandPurchase::FLAGS))],
            'value' => ['required', 'boolean'],
        ]);

        $stand = StandPurchases::find($workbook, $data['key']);
        if ($stand === null) {
            return back()->with('error', 'Ese stand ya no aparece como comprado en la hoja.');
        }

        $purchase = StandPurchase::firstOrNew(['stand_workbook_id' => $workbook->id, 'stand_key' => $data['key']]);
        $purchase->fill(['name' => $stand['name'], $data['flag'] => (bool) $data['value'], 'updated_by' => $request->user()->id])->save();

        AuditLog::record('stands.purchase_updated', $workbook, [
            'stand' => trim(($stand['zone'] ? $stand['zone'].' · ' : '').$stand['number'].' · '.$stand['name']),
            'campo' => StandPurchase::FLAGS[$data['flag']],
            'valor' => $data['value'] ? 'Sí' : 'No',
        ]);

        return back();
    }
}
