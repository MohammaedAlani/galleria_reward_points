<?php

namespace App\Console\Commands;

use App\Mail\SendCustomersExport;
use Illuminate\Console\Command;
use App\Exports\CustomerExport;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Storage;

class ExportCustomersAndMail extends Command
{
    protected $signature = 'export:customers-mail';
    protected $description = 'Export customers table to file only';

    public function handle()
    {
        $fileName = 'customers_export_' . now()->format('Ymd_His') . '.xlsx';
        $filePath = 'exports/' . $fileName;

        $this->info("Export started, file will be stored as: $filePath");

        try {
            $result = Excel::store(new CustomerExport, $filePath, 'local');

            if ($result) {
                $this->info("Excel::store returned TRUE");
            } else {
                $this->error("Excel::store returned FALSE");
            }

            $fullPath = Storage::path($filePath);

            if (file_exists($fullPath)) {
                $this->info("File exists at: $fullPath");
            } else {
                $this->error("File does NOT exist at: $fullPath");
            }

            try {
                Mail::to(['yaldez.1991@gmail.com', 'algarawe.sss@gmail.com'])->send(new SendCustomersExport($filePath));
                $this->info('Email sent successfully!');
            } catch (\Exception $e) {
                $this->error('Failed to send email: ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            $this->error("Exception during export: " . $e->getMessage());
        }
    }

}
