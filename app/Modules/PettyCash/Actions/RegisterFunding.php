<?php

namespace App\Modules\PettyCash\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAudit;
use App\Modules\PettyCash\Models\CashFunding;
use App\Modules\PettyCash\Models\PettyCashAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterFunding
{
    public function execute(User $actor, PettyCashAccount $cash, array $data): CashFunding
    {
        abort_unless($actor->active && $actor->managesExpenses(), 403);

        return DB::transaction(function () use ($actor, $cash, $data) {
            $account = PettyCashAccount::lockForUpdate()->findOrFail($cash->id);
            $existing = CashFunding::where('request_key', $data['request_key'])->first();
            if ($existing) {
                if ($existing->petty_cash_account_id !== $account->id || $existing->actor_id !== $actor->id || bccomp($existing->amount, $data['amount'], 2) !== 0 || $existing->funded_on->toDateString() !== $data['funded_on'] || ($existing->note ?? '') !== ($data['note'] ?? '')) {
                    throw ValidationException::withMessages(['funding' => 'La solicitud de entrega ya fue utilizada con otros datos.']);
                }

                return $existing;
            }
            if (! $account->is_permanent || ! $account->editable() || ! $account->user->active || ! $account->user->canRecordExpenses()) {
                throw ValidationException::withMessages(['funding' => 'La entrega requiere una caja permanente activa de un técnico o administrador.']);
            }
            $lastDate = max(array_map(fn ($date) => substr((string) $date, 0, 10), array_filter([$account->received_on->toDateString(), $account->fundings()->max('funded_on'), $account->expenses()->max('spent_on'), $account->returns()->max('returned_on')])));
            if ($data['funded_on'] < $lastDate || $data['funded_on'] > now(config('expenses.timezone'))->toDateString()) {
                throw ValidationException::withMessages(['funded_on' => 'La entrega no puede ser futura ni anterior a un movimiento ya registrado en esta caja.']);
            }
            $figures = $account->figures();
            $reimbursement = bccomp($data['amount'], $figures['personal_advance'], 2) <= 0 ? bcadd($data['amount'], '0', 2) : $figures['personal_advance'];
            $entry = $account->fundings()->create([
                'actor_id' => $actor->id, 'funded_on' => $data['funded_on'], 'amount' => $data['amount'],
                'reimbursement' => $reimbursement, 'available_addition' => bcsub($data['amount'], $reimbursement, 2),
                'opening_balance' => $figures['difference'], 'closing_balance' => bcadd($figures['difference'], $data['amount'], 2),
                'request_key' => $data['request_key'], 'note' => $data['note'] ?? null,
                'expense_cutoff_id' => $account->expenses()->max('id'),
            ]);
            $account->received_amount = bcadd($figures['received'], $data['amount'], 2);
            $account->version++;
            $account->save();
            app(RecordAudit::class)->record($actor, 'cash', $account->id, 'funded', null, $entry->only(['funded_on', 'amount', 'reimbursement', 'available_addition', 'opening_balance', 'closing_balance', 'note']) + ['funding_id' => $entry->id]);

            return $entry;
        });
    }
}
