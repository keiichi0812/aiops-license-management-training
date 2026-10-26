<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\License;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LicensesTest extends TestCase
{
    use RefreshDatabase;

    public function test_expiring_list_includes_today_and_day_thirty_but_not_other_states_or_owners(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00', 'Asia/Tokyo'));
        $user = User::factory()->create();
        $other = User::factory()->create();
        $today = License::factory()->create(['user_id' => $user->id, 'ends_on' => today()]);
        $day30 = License::factory()->create(['user_id' => $user->id, 'ends_on' => today()->addDays(30)]);
        License::factory()->create(['user_id' => $user->id, 'ends_on' => today()->addDays(31)]);
        License::factory()->create(['user_id' => $user->id, 'ends_on' => today()->subDay()]);
        License::factory()->create(['user_id' => $user->id, 'stopped' => true]);
        License::factory()->create(['user_id' => $user->id, 'starts_on' => today()->addDay()]);
        License::factory()->create(['user_id' => $other->id]);

        $response = $this->actingAs($user)->getJson('/licenses?expiring=1');

        $response->assertJsonCount(2, 'records.data')->assertJsonPath('records.data.0.id', $today->id)->assertJsonPath('records.data.1.id', $day30->id);
        $this->assertSame(2, collect($response->json('summary'))->sum('count'));
        $this->assertSame(10, collect($response->json('summary'))->sum('quantity'));
        $this->assertSame([$user->id], collect($response->json('options.user_id'))->pluck('id')->all());
    }

    public function test_other_user_cannot_read_update_or_delete_a_license_even_with_direct_urls(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $license = License::factory()->create(['user_id' => $owner->id, 'quantity' => 5]);

        $this->actingAs($other)->getJson('/licenses/'.$license->id)->assertNotFound();
        $this->patch('/licenses/'.$license->id, ['quantity' => 1])->assertNotFound();
        $this->delete('/licenses/'.$license->id)->assertNotFound();
        $this->assertDatabaseHas('licenses', ['id' => $license->id, 'user_id' => $owner->id, 'quantity' => 5]);
    }

    public function test_valid_registration_and_update_force_ownership_for_general_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $payload = ['customer_id' => $customer->id, 'product_id' => $product->id, 'user_id' => $other->id, 'quantity' => 3,
            'starts_on' => '2026-10-26', 'ends_on' => '2026-10-26', 'stopped' => false, 'notes' => '登録確認'];

        $this->actingAs($user)->post('/licenses', $payload)->assertRedirect('/licenses');

        $license = License::firstOrFail();
        $this->assertSame($user->id, $license->user_id);
        $this->assertNotEmpty($license->number);
        $this->patch('/licenses/'.$license->id, [...$payload, 'quantity' => 4])->assertRedirect('/licenses');
        $this->assertDatabaseHas('licenses', ['id' => $license->id, 'user_id' => $user->id, 'quantity' => 4]);
        $this->getJson('/licenses/'.$license->id)->assertJsonPath('record.quantity', 4);
        $this->delete('/licenses/'.$license->id)->assertRedirect('/licenses');
        $this->assertModelMissing($license);
    }

    public function test_admin_can_reassign_license_and_list_all_owners(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create();
        $license = License::factory()->create(['user_id' => $user->id]);

        $this->actingAs($admin)->patch('/licenses/'.$license->id, ['customer_id' => $license->customer_id, 'product_id' => $license->product_id,
            'user_id' => $admin->id, 'quantity' => 5, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'stopped' => true])->assertRedirect('/licenses');

        $this->assertDatabaseHas('licenses', ['id' => $license->id, 'user_id' => $admin->id, 'stopped' => true]);
        $this->getJson('/licenses')->assertJsonCount(1, 'records.data')->assertJsonPath('records.data.0.status', '停止中');
    }

    public function test_search_or_conditions_cannot_escape_owner_scope(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $customer = Customer::factory()->create(['name' => '検索対象の顧客']);
        License::factory()->create(['user_id' => $other->id, 'customer_id' => $customer->id, 'number' => 'SECRET-SEARCH']);
        License::factory()->create(['user_id' => $user->id, 'number' => 'OWN-SEARCH']);

        $this->actingAs($user)->getJson('/licenses?q=検索対象')->assertJsonCount(0, 'records.data')->assertJsonCount(0, 'summary');
        $this->getJson('/licenses?q=SEARCH')->assertJsonCount(1, 'records.data')->assertJsonPath('records.data.0.number', 'OWN-SEARCH');
    }

    #[DataProvider('invalidQuantities')]
    public function test_invalid_quantities_are_rejected_without_creating_records(mixed $quantity): void
    {
        $user = User::factory()->create();
        $payload = ['customer_id' => Customer::factory()->create()->id, 'product_id' => Product::factory()->create()->id,
            'quantity' => $quantity, 'starts_on' => '2026-10-26', 'ends_on' => '2026-10-26', 'stopped' => false];

        $this->actingAs($user)->post('/licenses', $payload)->assertSessionHasErrors('quantity');

        $this->assertDatabaseCount('licenses', 0);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidQuantities(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'fractional' => [1.5]];
    }

    public function test_end_before_start_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/licenses', ['customer_id' => Customer::factory()->create()->id, 'product_id' => Product::factory()->create()->id,
            'quantity' => 1, 'starts_on' => '2026-10-26', 'ends_on' => '2026-10-25', 'stopped' => false])->assertSessionHasErrors(['ends_on' => '終了日は開始日以降にしてください。']);

        $this->assertDatabaseCount('licenses', 0);
    }
}
