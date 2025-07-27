<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class downloadExcelController extends Controller
{
    public function downloadExport($filename)
    {
        $filePath = storage_path('app/public/exports/' . $filename);

        if (!file_exists($filePath) ) {
            abort(404, 'File not found');
        }

        return response()->download($filePath)->deleteFileAfterSend(true);
    }
}
