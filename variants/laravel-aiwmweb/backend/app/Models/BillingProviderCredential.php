<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class BillingProviderCredential extends Model
{
    protected $fillable=['provider','encrypted_credentials']; protected $hidden=['encrypted_credentials'];
    protected function casts(): array { return ['encrypted_credentials'=>'encrypted:array']; }
}
