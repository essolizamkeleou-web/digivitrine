<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * Initial catalogue of trades, taken from the project specification.
 * It is a starting point to be validated with the field team, NOT an exhaustive list.
 *
 * Idempotent and non-destructive: a category is created only when its slug is missing,
 * and existing rows are never modified. Positions follow the order below (10, 20, 30...).
 *
 * Accents are written as \u{..} escapes so that this file stays pure ASCII.
 */
class CategorySeeder extends Seeder
{
    /**
     * @return list<array{slug: string, name: string}>
     */
    public function data(): array
    {
        return [
            ['slug' => 'couture', 'name' => 'Couture'],
            ['slug' => 'menuiserie', 'name' => 'Menuiserie'],
            ['slug' => 'mecanique', 'name' => "M\u{E9}canique"],
            ['slug' => 'coiffure', 'name' => 'Coiffure'],
            ['slug' => 'cordonnier', 'name' => 'Cordonnier'],
            ['slug' => 'reparation', 'name' => "R\u{E9}paration"],
            ['slug' => 'peinture', 'name' => 'Peinture'],
            ['slug' => 'soudure', 'name' => 'Soudure'],
            ['slug' => 'maconnerie', 'name' => "Ma\u{E7}onnerie"],
            ['slug' => 'commerce', 'name' => 'Commerce'],
            ['slug' => 'restauration', 'name' => 'Restauration'],
            ['slug' => 'imprimerie', 'name' => 'Imprimerie'],
            ['slug' => 'photographie', 'name' => 'Photographie'],
            ['slug' => 'decoration', 'name' => "D\u{E9}coration"],
        ];
    }

    public function run(): void
    {
        foreach ($this->data() as $index => $category) {
            Category::firstOrCreate(
                ['slug' => $category['slug']],
                ['name' => $category['name'], 'position' => ($index + 1) * 10, 'is_active' => true],
            );
        }

        $this->command?->info('CategorySeeder : '.Category::count().' categorie(s) en base.');
    }
}
