<?php

namespace App\Services\Finance;

use App\Models\CustomerLedger;
use App\Models\Ledger;
use App\Models\ProjectHeadSubhead;
use Illuminate\Support\Facades\DB;

/** Updates a cash voucher's original source and ledger leg in place. */
final class LegacyCashVoucherCorrection
{
    /** @return array{source:CustomerLedger,ledger:Ledger} */
    public function correct(int $projectId, int $ledgerId, int $sourceId, int $accountId, array $changes, int $userId): array
    {
        return DB::transaction(function () use ($projectId, $ledgerId, $sourceId, $accountId, $changes, $userId): array {
            $source = CustomerLedger::query()->whereKey($sourceId)->where('project_id', $projectId)
                ->whereIn('transaction_type', ['CR', 'CP', 'PPR'])->where('is_active', 1)
                ->lockForUpdate()->firstOrFail();
            $ledger = Ledger::query()->withoutGlobalScopes()->where('customer_ledger_id', $source->id)
                ->whereKey($ledgerId)->where('type', $source->transaction_type)->where('is_active', 1)
                ->whereIn('project_head_subheads_id', ProjectHeadSubhead::where('project_id', $projectId)->select('id'))
                ->lockForUpdate()->firstOrFail();
            if (Ledger::query()->withoutGlobalScopes()->where('customer_ledger_id', $source->id)
                ->where('type', $source->transaction_type)->where('is_active', 1)->count() !== 1 ||
                (int) $ledger->customer_ledger_id !== (int) $source->id) {
                throw new \DomainException('CASH_VOUCHER_SINGLE_SOURCE_LEG_REQUIRED');
            }

            $account = ProjectHeadSubhead::query()->whereKey($accountId)->where('project_id', $projectId)
                ->lockForUpdate()->firstOrFail();
            $type = (string) $source->transaction_type;
            if (isset($changes['reference']) && (string) $changes['reference'] !== (string) $source->reference &&
                CustomerLedger::query()->where('project_id', $projectId)->where('transaction_type', $type)
                    ->where('reference', $changes['reference'])->where('id', '<>', $source->id)->exists()) {
                throw new \DomainException('CASH_VOUCHER_REFERENCE_ALREADY_USED');
            }
            $source->forceFill(array_merge($changes, [
                'project_id' => $projectId,
                'customer_id' => $account->customer_id ?? $source->customer_id,
                'plot_id' => $account->plot_id ?? $source->plot_id,
                'transaction_type' => $type,
            ]))->saveQuietly();

            $ledger->forceFill([
                'project_head_subheads_id' => $account->id,
                'date' => $changes['date'],
                'amount_in' => in_array($type, ['CR', 'PPR'], true) ? $changes['amount_in'] : 0,
                'amount_out' => $type === 'CP' ? $changes['amount_out'] : 0,
                'reference' => $changes['reference'] ?? $source->reference,
                'detail' => $changes['description'],
                'update_by' => $userId,
            ])->saveQuietly();

            if ($type === 'PPR') {
                $bookingVoucher = \App\Models\BookingVoucher::query()->where('customer_ledger_id', $source->id)
                    ->orWhere('ledger_id', $ledger->id)->lockForUpdate()->first();
                if ($bookingVoucher) {
                    $bookingVoucher->forceFill([
                        'customer_id' => $source->customer_id,
                        'plot_id' => $source->plot_id,
                        'amount' => $changes['amount_in'],
                        'receipt_date' => $changes['date'],
                        'description' => $changes['description'],
                        'payment_type' => $changes['payment_type'],
                        't_number' => $changes['t_number'],
                        'bank_id' => $changes['bank_id'],
                        'passing_date' => $changes['passing_date'],
                        'update_by' => $userId,
                    ])->save();
                }
            }

            return ['source' => $source->refresh(), 'ledger' => $ledger->refresh()];
        }, 3);
    }
}
