<?php

namespace App\Console\Commands;

use App\Exports\CustomerExport;
use Illuminate\Console\Command;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Mail;
use App\Mail\SendCustomersExport;

class ExportCustomersAndMail extends Command
{
    protected $signature = 'export:customers-mail';
    protected $description = 'Export customers table and send by email';

    public function handle()
    {
        $fileName = 'customers_export_' . now()->format('Ymd_His') . '.xlsx';
        $filePath = 'exports/' . $fileName;

        Excel::store(new CustomerExport, $filePath, 'local');

        Mail::to([
            'yaldez.1991@gmail.com'
        ])->send(new SendCustomersExport($filePath));

        $this->info("Customer export completed and emailed to admin@example.com");
    }
}

