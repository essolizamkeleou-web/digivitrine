<?php

namespace App\Services;

use App\Models\Category;
use App\Models\City;
use App\Models\Neighborhood;
use App\Models\Subcategory;
use Illuminate\Validation\ValidationException;

/**
 * Turns the slugs sent by a client into catalogue rows, with one error per field when a slug is unknown,
 * inactive, or inconsistent (a subcategory of another category, a neighborhood of another city).
 */
class CatalogueResolver
{
    /**
     * @param  array<string, mixed>  $data  optional keys: category, subcategory, city, neighborhood, country
     * @return array{category: ?Category, subcategory: ?Subcategory, neighborhood: ?Neighborhood}
     */
    public function resolve(array $data): array
    {
        $errors = [];
        $category = null;
        $subcategory = null;
        $neighborhood = null;

        if (isset($data['category'])) {
            $category = Category::query()->where('slug', $data['category'])->where('is_active', true)->first();

            if ($category === null) {
                $errors['category'][] = "Cat\u{E9}gorie inconnue ou inactive.";
            } elseif (isset($data['subcategory'])) {
                $subcategory = $category->subcategories()
                    ->where('slug', $data['subcategory'])
                    ->where('is_active', true)
                    ->first();

                if ($subcategory === null) {
                    $errors['subcategory'][] = "Sous-cat\u{E9}gorie inconnue, inactive, ou qui n'appartient pas \u{E0} cette cat\u{E9}gorie.";
                }
            }
        }

        if (isset($data['city'], $data['neighborhood'])) {
            $city = City::query()
                ->where('slug', $data['city'])
                ->where('country_code', $data['country'] ?? (string) config('digivitrine.default_country'))
                ->where('is_active', true)
                ->first();

            if ($city === null) {
                $errors['city'][] = 'Ville inconnue ou inactive.';
            } else {
                $neighborhood = $city->neighborhoods()
                    ->where('slug', $data['neighborhood'])
                    ->where('is_active', true)
                    ->first();

                if ($neighborhood === null) {
                    $errors['neighborhood'][] = "Quartier inconnu, inactif, ou qui n'appartient pas \u{E0} cette ville.";
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['category' => $category, 'subcategory' => $subcategory, 'neighborhood' => $neighborhood];
    }
}
