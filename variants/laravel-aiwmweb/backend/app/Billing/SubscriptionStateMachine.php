<?php
namespace App\Billing;
use App\Billing\Enums\SubscriptionState;
use App\Billing\Exceptions\InvalidSubscriptionTransition;
final class SubscriptionStateMachine
{
    private const ALLOWED=['TRIALING'=>['ACTIVE','CANCELLED','EXPIRED'],'ACTIVE'=>['PAST_DUE','GRACE','SUSPENDED','CANCELLED','EXPIRED'],'PAST_DUE'=>['ACTIVE','GRACE','SUSPENDED','CANCELLED'],'GRACE'=>['ACTIVE','SUSPENDED','CANCELLED','EXPIRED'],'SUSPENDED'=>['ACTIVE','CANCELLED','EXPIRED'],'CANCELLED'=>['ACTIVE'],'EXPIRED'=>['ACTIVE']];
    public function assert(SubscriptionState $from,SubscriptionState $to): void { if($from===$to)return; if(!in_array($to->value,self::ALLOWED[$from->value]??[],true))throw new InvalidSubscriptionTransition("Invalid subscription transition {$from->value} -> {$to->value}"); }
}
