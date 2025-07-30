<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class TransactionsExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    protected $transactions;
    protected $exportInfo;

    public function __construct($transactions, $exportInfo = [])
    {
        $this->transactions = $transactions;
        $this->exportInfo = $exportInfo;
    }

    public function collection()
    {
        return $this->transactions;
    }

    public function headings(): array
    {
        return [
            'العميل',
            'الموقع',
            'نوع المعاملة',
            'رقم المعاملة',
            'قيمة المعاملة',
            'تاريخ المعاملة',
            'المستخدم الذي وافق على المعاملة',
            'المستخدم الذي أضاف المعاملة',
        ];
    }

    public function map($transaction): array
    {
        return [
            $transaction->customer->name ?? 'غير محدد',
            $this->mapTransactionLocation($transaction->location),
            $this->mapTransactionType($transaction->transaction_type),
            $transaction->transaction_number,
            $transaction->transaction_amount,
            $transaction->transaction_date,
            $transaction->approvedByUser?->name ?? 'غير محدد',
            $transaction->addByUser?->name ?? 'غير محدد',
        ];
    }

    private function mapTransactionLocation($location){
        if (is_null($location)) {
            return 'غير محدد';
        }

        $locationMap = [
            'karada' => 'الكرادة',
            'Jadriyah' => "الجادرية",
            'saydiya' => 'السيدية'
        ];

        return $locationMap[$location] ?? $location;
    }

    private function mapTransactionType($transaction_type)
    {
        if (is_null($transaction_type)) {
            return 'غير محدد';
        }

        $typeMap = [
            'add' =>  'إضافة',
            'return' => 'إرجاع',
            'use' => 'خصم',
        ];

        return $typeMap[$transaction_type] ?? $transaction_type;
    }
}
