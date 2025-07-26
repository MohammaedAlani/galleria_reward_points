<?php

namespace App\Exports;

use App\Models\Customer;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class CustomerExport implements FromCollection, WithHeadings
{
    public function collection()
    {
        return Customer::withTrashed()->get([
            'id', 'name', 'address', 'phone', 'card_number',
            'total_points', 'total_spent',
            'last_transaction', 'last_transaction_date', 'last_transaction_amount',
            'deleted_at', 'created_at', 'updated_at'
        ]);
    }

    public function headings(): array
    {
        return [
            'ID', 'Name', 'Address', 'Phone', 'Card Number',
            'Total Points', 'Total Spent', 'Balance Points',
            'Last Transaction', 'Last Transaction Date', 'Last Transaction Amount',
            'Deleted At', 'Created At', 'Updated At'
        ];
    }
}
