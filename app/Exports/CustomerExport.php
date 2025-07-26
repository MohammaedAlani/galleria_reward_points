<?php

namespace App\Exports;

use App\Models\Customer;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class CustomerExport implements FromCollection, WithHeadings, WithMapping, WithEvents
{
    protected $totalPoints = 0;
    protected $totalSpent = 0;

    public function collection()
    {
        return Customer::withTrashed()->get();
    }

    public function headings(): array
    {
        return [
            'ID', 'Name', 'Address', 'Phone', 'Card Number',
            'Total Points', 'Total Spent', 'Balance',
            'Last Transaction', 'Last Transaction Date', 'Last Transaction Amount',
            'Deleted At', 'Created At', 'Updated At'
        ];
    }

    public function map($customer): array
    {
        // Calculate balance
        $balance = $customer->total_points - $customer->total_spent;

        // Accumulate totals for summary row
        $this->totalPoints += $customer->total_points;
        $this->totalSpent += $customer->total_spent;

        return [
            $customer->id,
            $customer->name,
            $customer->address,
            $customer->phone,
            $customer->card_number,
            $customer->total_points,
            $customer->total_spent,
            $balance,
            $customer->last_transaction,
            $customer->last_transaction_date,
            $customer->last_transaction_amount,
            $customer->deleted_at,
            $customer->created_at,
            $customer->updated_at,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet;

                // Calculate the total balance for summary
                $totalBalance = $this->totalPoints - $this->totalSpent;

                // Get the last row index of the data + 2 for spacing
                $lastRow = $sheet->getHighestRow() + 2;

                // Write "Totals" label in column E (5th column)
                $sheet->setCellValue("E{$lastRow}", 'Totals');

                // Write totals in columns F (6th), G (7th), and H (8th)
                $sheet->setCellValue("F{$lastRow}", $this->totalPoints);
                $sheet->setCellValue("G{$lastRow}", $this->totalSpent);
                $sheet->setCellValue("H{$lastRow}", $totalBalance);

                // Optional: Make totals row bold
                $sheet->getStyle("E{$lastRow}:H{$lastRow}")->getFont()->setBold(true);
            },
        ];
    }
}

