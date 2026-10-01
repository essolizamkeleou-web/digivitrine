<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Subcategories, only where they bring real value for search (kept small for the MVP).
 * Requires CategorySeeder to have run first.
 *
 * Idempotent and non-destructive: a subcategory is created only when its (category, slug) is missing,
 * and existing rows are never modified.
 *
 * Accents are written as \u{..} escapes so that this file stays pure ASCII.
 */
class SubcategorySeeder extends Seeder
{
    /**
     * Subcategories keyed by category slug.
     *
     * @return array<string, list<array{slug: string, name: string}>>
     */
    public function data(): array
    {
        return [
            'couture' => [
                ['slug' => 'vetements-homme', 'name' => "V\u{EA}tements homme"],
                ['slug' => 'vetements-femme', 'name' => "V\u{EA}tements femme"],
                ['slug' => 'vetements-enfant', 'name' => "V\u{EA}tements enfant"],
            ],
            'mecanique' => [
                ['slug' => 'automobile', 'name' => 'Automobile'],
                ['slug' => 'moto', 'name' => 'Moto'],
            ],
            'reparation' => [
                ['slug' => 'telephone', 'name' => "T\u{E9}l\u{E9}phone"],
                ['slug' => 'electromenager', 'name' => "\u{C9}lectrom\u{E9}nager"],
                ['slug' => 'informatique', 'name' => 'Informatique'],
            ],
        ];
    }

    public function run(): void
    {
        foreach ($this->data() as $categorySlug => $subcategories) {
            $category = Category::where('slug', $categorySlug)->first()
                ?? throw new RuntimeException("Unknown category {$categorySlug}: run CategorySeeder first.");

            foreach ($subcategories as $index => $subcategory) {
                Subcategory::firstOrCreate(
                    ['category_id' => $category->id, 'slug' => $subcategory['slug']],
                    ['name' => $subcategory['name'], 'position' => ($index + 1) * 10, 'is_active' => true],
                );
            }
        }

        $this->command?->info('SubcategorySeeder : '.Subcategory::count().' sous-categorie(s) en base.');
    }
}
