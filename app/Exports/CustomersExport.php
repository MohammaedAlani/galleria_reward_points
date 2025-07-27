<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class CustomersExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
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
            'رقم الزبون', 'الاسم', 'رقم الهاتف', 'البريد الإلكتروني',
            'العنوان', 'رقم البطاقة', 'الجنس', 'تاريخ الميلاد', 'العمر',
            'إجمالي النقاط', 'النقاط المتاحة', 'النقاط المستخدمة',
            'قيمة النقاط (د.ع)', 'عدد المعاملات', 'حالة الزبون',
            'تاريخ التسجيل', 'آخر معاملة', 'أيام منذ التسجيل'
        ];
    }

    public function map($customer): array
    {
        return [
            $customer->id,
            $customer->name,
            $customer->phone,
            $customer->email ?? 'غير محدد',
            $customer->address ?? 'غير محدد',
            $customer->card_number ?? 'غير محدد',
            $customer->gender === 'male' ? 'ذكر' : ($customer->gender === 'female' ? 'أنثى' : 'غير محدد'),
            $customer->date_of_birth ?? 'غير محدد',
            $customer->age ?? 'غير محدد',
            $customer->total_points,
            $customer->total_points_can_use,
            $customer->total_spent,
            $customer->points_amount,
            $customer->transaction_count,
            $customer->customer_status,
            $customer->created_at->format('Y-m-d'),
            $customer->last_transaction_date ? $customer->last_transaction_date->format('Y-m-d') : 'لا توجد',
            $customer->days_since_registration
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Style the first row as bold and with background
            1 => [
                'font' => ['bold' => true, 'size' => 12],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF4472C4']
                ],
                'font' => ['color' => ['argb' => 'FFFFFFFF'], 'bold' => true],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ]
            ],

            // Style all cells
            'A:N' => [
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_RIGHT, // RTL support
                    'vertical' => Alignment::VERTICAL_CENTER,
                ]
            ]
        ];
    }
}
