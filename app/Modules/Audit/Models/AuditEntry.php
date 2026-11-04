<?php

namespace App\Modules\Audit\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditEntry extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('La auditoría es inmutable.'));
        static::deleting(fn () => throw new LogicException('La auditoría es inmutable.'));
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subjectLabel(): string
    {
        return ['expense' => 'Gasto', 'user' => 'Usuario', 'cash' => 'Caja chica', 'catalog' => 'Catálogo'][$this->subject_type] ?? 'Registro';
    }

    public function actionLabel(): string
    {
        return ['created' => 'Registro', 'updated' => 'Edición', 'returned' => 'Devolución', 'status_changed' => 'Cambio de estado', 'funded' => 'Entrega de dinero', 'reviewed' => 'Revisión del fondo'][$this->action] ?? 'Cambio';
    }

    public static function fieldLabel(string $field): string
    {
        return ['spent_on' => 'Fecha del gasto', 'description' => 'Descripción', 'category' => 'Categoría registrada', 'amount' => 'Importe', 'currency' => 'Moneda', 'receipt_name' => 'Comprobante', 'user_id' => 'ID del responsable', 'version' => 'Versión', 'name' => 'Nombre', 'username' => 'Usuario', 'email' => 'Correo', 'role' => 'Rol', 'active' => 'Activa', 'password_changed' => 'Contraseña actualizada', 'code' => 'Rendición', 'delivered_by' => 'ID de quien entregó', 'branch_id' => 'ID de sucursal', 'dni' => 'DNI', 'area' => 'Cargo / área', 'purpose' => 'Motivo', 'received_amount' => 'Monto recibido', 'received_on' => 'Fecha de entrega', 'status' => 'Estado', 'review_note' => 'Comentario de revisión', 'reviewed_by' => 'ID de quien revisó', 'submitted_at' => 'Presentada el', 'closed_at' => 'Cerrada el', 'return_id' => 'ID de devolución', 'returned_on' => 'Fecha de devolución', 'note' => 'Observación', 'kind' => 'Catálogo', 'sort_order' => 'Orden', 'category_id' => 'ID de categoría', 'petty_cash_account_id' => 'ID de caja chica', 'provider' => 'Proveedor', 'tax_id' => 'RUC / DNI', 'receipt_type_id' => 'ID del tipo de comprobante', 'series' => 'Serie', 'document_number' => 'Número', 'observation' => 'Observación'][$field] ?? $field;
    }
}
