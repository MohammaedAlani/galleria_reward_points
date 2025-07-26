<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class SendCustomersExport extends Mailable
{
    use Queueable, SerializesModels;

    public string $filePath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    public function build()
    {
        return $this->subject('Galleria Customer Export Report')
            ->view('emails.customer_export')
            ->attach(Storage::path($this->filePath), [
                'as' => 'customers_export.xlsx',
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
    }
}

