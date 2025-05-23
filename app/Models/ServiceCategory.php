<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceCategory extends Model
{
    use HasFactory;
    protected $guarded = ['id'];

    public function subCategories()
    {
        return $this->hasMany(ServiceSubCategory::class);
    }
    // ServiceCategory
    public function services()
    {
        return $this->hasMany(Services::class, 'service_category_id');
    }

    public function forums()
    {
        return $this->hasMany(CommunityForum::class);
    }

    public function getIconAttribute($icon)
    {
        $defaultIcon = 'default_user.png';
        return asset('uploads/category_icons/' . ($icon ?? $defaultIcon));
    }

    //when delete a category related subcategory also deleted
    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($category) {
            $category->subcategories()->delete(); // Deletes all associated subcategories
        });
    }
}
