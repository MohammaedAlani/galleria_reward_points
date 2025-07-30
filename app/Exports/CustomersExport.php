<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class CustomersExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    protected $customers;
    protected $exportInfo;

    public function __construct($customers, $exportInfo = [])
    {
        $this->customers = $customers;
        $this->exportInfo = $exportInfo;
    }

    public function collection()
    {
        return $this->customers;
    }

    public function headings(): array
    {
        return [
            'رقم العميل',
            'اسم العميل',
            'رقم الهاتف',
            'العنوان',
            'رقم البطاقة',
            'إجمالي النقاط',
            'إجمالي المبلغ المنفق',
            'الرصيد المتبقي',
            'آخر معاملة',
            'قيمة آخر معاملة',
            'عدد المعاملات'
        ];
    }

    public function map($customer): array
    {
        return [
            $customer->id,
            $customer->name,
            $customer->phone,
            $customer->address ?? 'غير محدد',
            $customer->card_number ?? 'غير محدد',
            $customer->total_points,
            $customer->total_spent,
            $customer->total_points - $customer->total_spent,
            $customer->last_transaction,
            $customer->last_transaction_amount,
            $customer->transactions_count
        ];
    }
}
