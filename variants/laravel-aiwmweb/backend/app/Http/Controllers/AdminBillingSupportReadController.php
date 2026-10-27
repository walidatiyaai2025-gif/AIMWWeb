<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

final class AdminBillingSupportReadController
{
    public function __invoke(): View
    {
        return view('billing.admin-support', [
            'canonicalOperationId' => 'AIMW-BILL-5811B45F89',
        ]);
    }
}
