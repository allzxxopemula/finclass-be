<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
	protected $fillable = ['user_id', 'aksi'];

	public static function record(?int $userId, string $action): void
	{
		if ($userId) {
			static::create(['user_id' => $userId, 'aksi' => $action]);
		}
	}
}
