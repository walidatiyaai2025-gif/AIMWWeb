<?php
namespace App\Billing;
use App\Billing\Providers\BillingProvider;
use InvalidArgumentException;
final class BillingProviderManager
{
    public function __construct(private readonly BillingProvider $paypal) {}
    public function for(string $name): BillingProvider { if($name!=='paypal'||$this->paypal->name()!=='paypal')throw new InvalidArgumentException("Unsupported billing provider: {$name}"); return $this->paypal; }
}
