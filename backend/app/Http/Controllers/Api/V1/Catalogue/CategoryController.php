<?php

namespace App\Http\Controllers\Api\V1\Catalogue;

use App\Http\Controllers\Api\V1\Catalogue\Concerns\CachesReferenceData;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\SubcategoryResource;
use App\Models\Category;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;

class CategoryController
{
    use CachesReferenceData;

    public function index(): JsonResponse
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->with(['subcategories' => fn (Relation $query) => $query->where('is_active', true)->orderBy('position')->orderBy('id')])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return $this->cacheable(CategoryResource::collection($categories));
    }

    public function show(string $category): JsonResponse
    {
        return $this->cacheable(CategoryResource::make($this->find($category)->load([
            'subcategories' => fn (Relation $query) => $query->where('is_active', true)->orderBy('position')->orderBy('id'),
        ])));
    }

    public function subcategories(string $category): JsonResponse
    {
        $subcategories = $this->find($category)
            ->subcategories()
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return $this->cacheable(SubcategoryResource::collection($subcategories));
    }

    private function find(string $slug): Category
    {
        return Category::query()->where('is_active', true)->where('slug', $slug)->firstOrFail();
    }
}
