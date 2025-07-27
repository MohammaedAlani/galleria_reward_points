<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class TransactionsExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
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
            'اسم الزبون', 'نوع الحركة', 'المبلغ ', 'رقم الحركة',
            'تاريخ الحركة', 'اضيفت بواسطة', 'تمت الموافقة بواسطة ', 'حالة الحركة'
        ];
    }

    public function map($transaction): array
    {
        return [
            $transaction->customer_id,
            $transaction->transaction_type,
            $transaction->transaction_amount,
            $transaction->transaction_number ,
            $transaction->transaction_date ,
            $transaction->add_by ,
            $transaction->approved_by ,
            $transaction->transaction_status ,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
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

    private function getStatusText($status)
    {
        if (is_null($status)) {
            return 'غير محدد';
        }

        $statusMap = [
            'pending' => 'قيد الانتظار',
            'completed' => 'مكتملة',
            'cancelled' => 'ملغية',
            'failed' => 'فاشلة',
            'approved' => 'موافق عليه',
            'rejected' => 'مرفوض'
        ];

        return $statusMap[$status] ?? $status;
    }
}
