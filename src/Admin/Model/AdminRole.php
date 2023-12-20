<?php


namespace ModStart\Admin\Model;


use Illuminate\Database\Eloquent\Model;

class AdminRole extends Model
{
    protected $table = 'admin_role';

    public function users()
    {
        return $this->belongsToMany(AdminUser::class, 'admin_user_role', 'role_id', 'user_id');
    }

    public function rules()
    {
        return $this->hasMany(AdminRoleRule::class, 'role_id', 'id');
    }

    protected static function boot()
    {
        parent::boot();
        static::deleting(function (AdminRole $model) {
            $model->rules()->delete();
        });
    }


}