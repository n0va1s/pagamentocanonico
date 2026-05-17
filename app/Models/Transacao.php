<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transacao extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'transacoes';

    protected $primaryKey = 'idt_transacao';

    public $timestamps = true;

    protected $fillable = [
        'idt_ofx',
        'num_transacao',
        'dat_transacao',
        'tip_transacao',
        'val_transacao',
        'des_transacao',
        'num_check',
        'nom_pessoa',
    ];

    protected $casts = [
        'dat_transacao' => 'date',
        'val_transacao' => 'decimal:2',
    ];

    public function ofx(): BelongsTo
    {
        return $this->belongsTo(Ofx::class, 'idt_ofx');
    }
}
