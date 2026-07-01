<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    protected $table = 'promotion';
    protected $primaryKey = 'promotion_id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'title','description','discount_type','discount_value',
        'banner_image','link','promo_code','start_date','end_date','is_active',
        'promotion_type','show_in_dashboard_header',
    ];

    public function services()
    {
        return $this->belongsToMany(
            Service::class,
            'promotion_service',
            'promotion_id',
            'service_id',
            'promotion_id',
            'id'
        );
    }
}
