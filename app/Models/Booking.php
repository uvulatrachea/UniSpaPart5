<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Booking extends Model
{
    use HasFactory;

    protected $table = 'booking';
    protected $primaryKey = 'booking_id';
    protected $keyType = 'string';
    public $incrementing = false;
    
    protected $fillable = [
        'booking_id',
        'customer_id',
        'slot_id',
        'total_amount',
        'discount_amount',
        'final_amount',
        'status',
        'deposit_amount',
        'payment_method',
        'payment_status',
        'depo_qr_pic',
        'digital_receipt'
    ];
    
    protected $casts = [
        'total_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'deposit_amount' => 'decimal:2'
    ];
    
    public $timestamps = false;
    
    // Relationships
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }
    
    public function slot()
    {
        return $this->belongsTo(Slot::class, 'slot_id', 'slot_id');
    }
    
    public function participants()
    {
        return $this->hasMany(BookingParticipant::class, 'booking_id', 'booking_id');
    }
    
    public function review()
    {
        return $this->hasOne(Review::class, 'booking_id', 'booking_id');
    }

    /**
     * Customer can manage (cancel/edit) only until X hours before the slot start.
     *
     * Minimal implementation to satisfy AppointmentController usage.
     */
    public function canBeManagedByCustomer(int $hoursBefore = 24): bool
    {
        $slot = $this->relationLoaded('slot') ? $this->getRelation('slot') : $this->slot()->first();

        if (!$slot || empty($slot->slot_date) || empty($slot->start_time)) {
            return false;
        }

        try {
            $dateStr = $slot->slot_date instanceof Carbon
                ? $slot->slot_date->format('Y-m-d')
                : (string) $slot->slot_date;
            $startAt = Carbon::parse($dateStr . ' ' . $slot->start_time, config('app.timezone'));
        } catch (\Throwable) {
            return false;
        }

        return now(config('app.timezone'))->lt($startAt->subHours($hoursBefore));
    }
}