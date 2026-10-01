<?php

namespace Tests\Feature;

use App\Models\Artisan;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\AssertsDatabaseRejections;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    use AssertsDatabaseRejections;
    use RefreshDatabase;

    private Artisan $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->profile = Artisan::factory()->create();
    }

    public function test_artisan_has_many_services(): void
    {
        $this->profile->services()->create(['name' => 'Robe sur mesure']);
        $this->profile->services()->create(['name' => 'Retouche']);

        $this->assertCount(2, $this->profile->services);
        $this->assertTrue($this->profile->services->first()->artisan->is($this->profile));
    }

    public function test_model_defaults_position_and_active_flag(): void
    {
        $service = $this->profile->services()->create(['name' => 'Retouche'])->fresh();

        $this->assertSame(0, $service->position);
        $this->assertTrue($service->is_active);
    }

    public function test_database_applies_defaults_and_generates_a_uuid(): void
    {
        DB::table('services')->insert(['artisan_id' => $this->profile->id, 'name' => 'Retouche']);

        $service = Service::firstOrFail();

        $this->assertTrue($service->is_active);
        $this->assertSame(0, $service->position);
        $this->assertTrue(Str::isUuid($service->uuid));
    }

    public function test_description_is_optional(): void
    {
        $service = $this->profile->services()->create(['name' => 'Retouche'])->fresh();

        $this->assertNull($service->description);
    }

    public function test_service_name_cannot_be_blank(): void
    {
        $this->assertRejected(
            fn () => DB::table('services')->insert(['artisan_id' => $this->profile->id, 'name' => '   ']),
            'blank service name'
        );
    }

    public function test_service_requires_an_existing_artisan(): void
    {
        $this->assertRejected(
            fn () => DB::table('services')->insert(['artisan_id' => 999999999, 'name' => 'Retouche']),
            'service without artisan'
        );
    }

    public function test_ownership_and_uuid_are_not_mass_assignable(): void
    {
        $service = new Service;

        $this->assertFalse($service->isFillable('artisan_id'));
        $this->assertFalse($service->isFillable('uuid'));
        $this->assertTrue($service->isFillable('name'));
    }

    public function test_permanently_deleting_an_artisan_removes_its_services(): void
    {
        $service = Service::factory()->for($this->profile)->create();

        $this->profile->forceDelete();

        $this->assertDatabaseMissing('services', ['id' => $service->id]);
    }

    public function test_soft_deleting_an_artisan_keeps_its_services(): void
    {
        $service = Service::factory()->for($this->profile)->create();

        $this->profile->delete();

        $this->assertDatabaseHas('services', ['id' => $service->id]);
    }
}
