<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\License;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('Training!2026');
        $users = [];
        foreach ([['admin', '管理者', 'admin', 5000], ['user-a', '受講者A', 'user', 3000], ['user-b', '受講者B', 'user', 4000]] as [$key, $name, $role, $rate]) {
            $users[$key] = User::firstOrNew(['email' => $key.'@example.test']);
            if (! $users[$key]->exists) {
                $users[$key]->forceFill(['name' => $name, 'password' => $password, 'role' => $role, 'hourly_rate' => $rate, 'email_verified_at' => now()])->save();
            }
        }

        $customer = Customer::firstOrCreate(['code' => 'ALP'], ['name' => 'アルファ商事', 'contact_name' => '研修担当', 'email' => 'contact@example.test']);
        $other = Customer::firstOrCreate(['code' => 'BTA'], ['name' => 'ベータ製作所']);
        $product = Product::firstOrCreate(['code' => 'APP-01'], ['name' => '業務アプリ Standard', 'description' => '研修用の架空製品']);
        $second = Product::firstOrCreate(['code' => 'APP-02'], ['name' => '業務アプリ Pro']);
        foreach ([
            [-10, 0, false, '終了日当日'], [-10, 30, false, '30日先'], [-10, 31, false, '31日先'],
            [-20, -1, false, '期限切れ'], [-10, 5, true, '停止中'], [1, 20, false, '利用開始前'],
        ] as $i => [$start, $end, $stopped, $notes]) {
            License::firstOrCreate(['number' => 'LIC-DEMO-'.($i + 1)], [
                'customer_id' => $customer->id, 'product_id' => $product->id, 'user_id' => User::where('email', 'user-a@example.test')->firstOrFail()->id,
                'quantity' => 5 + $i, 'starts_on' => today()->addDays($start), 'ends_on' => today()->addDays($end), 'stopped' => $stopped, 'notes' => $notes,
            ]);
        }
        License::firstOrCreate(['number' => 'LIC-OTHER-USER'], ['customer_id' => $other->id, 'product_id' => $second->id, 'user_id' => User::where('email', 'user-b@example.test')->firstOrFail()->id, 'quantity' => 10, 'starts_on' => today()->subDays(10), 'ends_on' => today()->addDays(10), 'stopped' => false, 'notes' => '他人の契約・閲覧範囲の確認用']);

    }
}
