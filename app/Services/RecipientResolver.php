<?php

namespace App\Services;

use App\Helper\PhoneNormalizer;
use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class RecipientResolver
{
    public function resolve(array $filter): Collection
    {
        $mode = $filter['mode'] ?? 'all_active';

        return match ($mode) {
            'all_active' => $this->fromCustomers(Customer::query()->whereNotNull('phone')),
            'points_threshold' => $this->byPoints($filter),
            'last_transaction' => $this->byLastTransaction($filter),
            'manual_ids' => $this->fromCustomers(
                Customer::query()->whereIn('id', $filter['customer_ids'] ?? [])
            ),
            'uploaded_phones' => $this->fromPhones($filter['phones'] ?? []),
            default => throw new InvalidArgumentException("Unknown recipient mode: {$mode}"),
        };
    }

    private function byPoints(array $filter): Collection
    {
        $operator = $filter['operator'] ?? '>=';
        $value = (int) ($filter['value'] ?? 0);

        if (!in_array($operator, ['>=', '<=', '>', '<', '='])) {
            throw new InvalidArgumentException("Invalid operator: {$operator}");
        }

        return $this->fromCustomers(
            Customer::query()
                ->whereNotNull('phone')
                ->whereRaw("(total_points - total_spent) {$operator} ?", [$value])
        );
    }

    private function byLastTransaction(array $filter): Collection
    {
        $direction = $filter['direction'] ?? 'within';
        $days = (int) ($filter['days'] ?? 30);
        $cutoff = Carbon::now()->subDays($days);

        $query = Customer::query()->whereNotNull('phone');

        if ($direction === 'within') {
            $query->where('last_transaction_date', '>=', $cutoff);
        } elseif ($direction === 'before') {
            $query->where(function ($q) use ($cutoff) {
                $q->where('last_transaction_date', '<', $cutoff)
                    ->orWhereNull('last_transaction_date');
            });
        } else {
            throw new InvalidArgumentException("Invalid direction: {$direction}");
        }

        return $this->fromCustomers($query);
    }

    private function fromCustomers($query): Collection
    {
        return $query->select(['id', 'phone'])->get()->map(function ($customer) {
            $normalized = PhoneNormalizer::normalize($customer->phone);
            return [
                'customer_id' => $customer->id,
                'phone_raw' => $customer->phone,
                'phone_normalized' => $normalized,
            ];
        })->values();
    }

    private function fromPhones(array $phones): Collection
    {
        return collect($phones)->map(function ($phone) {
            return [
                'customer_id' => null,
                'phone_raw' => $phone,
                'phone_normalized' => PhoneNormalizer::normalize($phone),
            ];
        })->values();
    }
}
