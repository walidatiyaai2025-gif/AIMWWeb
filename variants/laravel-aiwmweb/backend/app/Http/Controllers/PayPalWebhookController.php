<?php

namespace App\Http\Controllers;

use App\Billing\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PayPalWebhookController extends Controller
{
    public function __invoke(Request $request, SubscriptionService $service): JsonResponse
    {
        return response()->json(['status' => $service->handleWebhook('paypal', $request)]);
    }
}
