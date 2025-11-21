<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InitialCharityDataSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        /*
        |--------------------------------------------------------------------------
        | Location hierarchy: Oman → Muscat → Muscat → Al Khuwair
        |--------------------------------------------------------------------------
        */

        DB::table('users')->insert([

            'id'         => 1,
            'name'       => 'Admin',
            'email'      => 'admin@mithqal.net',
            'password'   => '$2y$12$59Xa0rYotqRH0YJ2Hpiv5O.Cj4U7fpAzYTOZu930kY9k9C82jnO8C', // Change this password
            'created_at' => $now,
            'updated_at' => $now,

        ]);


        DB::table('countries')->insert([
            'id'         => 1,
            'name'       => 'Oman',
            'iso_code'   => 'OM',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('regions')->insert([
            'id'         => 1,
            'country_id' => 1,
            'name'       => 'Muscat Governorate',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('cities')->insert([
            'id'         => 1,
            'region_id'  => 1,
            'name'       => 'Muscat',
            'created_at' => $now,
            'updated_at' => $now,
        ]);



        /*
        |--------------------------------------------------------------------------
        | Device brand / model
        |--------------------------------------------------------------------------
        */
        DB::table('device_brands')->insert([
            'id'         => 1,
            'name'       => 'Android POS',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('device_models')->insert([
            'id'             => 1,
            'device_brand_id' => 1,
            'name'           => 'Z108',
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);


        DB::table('device_models')->insert([
            'id'             => 2,
            'device_brand_id' => 1,
            'name'           => 'Z93',
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Bank
        |--------------------------------------------------------------------------
        */
        DB::table('banks')->insert([
            'id'           => 1,
            'name'         => 'OMAN ARAB BANK',
            'swift_code'   => 'BMUSOMRXXXX',
            // 'iban'         => 'OM00BMUS0000000000000001',
            // 'account_number' => '1234567890',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Organization
        |--------------------------------------------------------------------------
        */
        DB::table('organizations')->insert([
            'id'           => 1,
            'name'         => 'Siraj Charity Foundation',
            'cr_number'    => '1234567',
            'phone'        => '+96890000000',
            'email'        => 'info@omancharity.org',
            'bank_id'      => 1,
            // 'bank_account_number' => '1234567890',
            'country_id'   => 1,
            'region_id'    => 1,
            'city_id'      => 1,

            'address_line1' => 'Al Khuwair, Muscat',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);



        DB::table('organizations')->insert([
            'id'           => 2,
            'name'         => 'Masjid Al Noor',
            'cr_number'    => '1234567',
            'phone'        => '+96890000000',
            'email'        => 'info@omancharity.org',
            'bank_id'      => 1,
            // 'bank_account_number' => '1234567890',
            'country_id'   => 1,
            'region_id'    => 1,
            'city_id'      => 1,

            'address_line1' => 'Al Khuwair, Muscat',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);



        DB::table('organizations')->insert([
            'id'           => 3,
            'name'         => 'Mithqal Charity Foundation',
            'cr_number'    => '1234567',
            'phone'        => '+96890000000',
            'email'        => 'info@omancharity.org',
            'bank_id'      => 1,
            // 'bank_account_number' => '1234567890',
            'country_id'   => 1,
            'region_id'    => 1,
            'city_id'      => 1,

            'address_line1' => 'Al Khuwair, Muscat',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);


        DB::table('organizations')->insert([
            'id'           => 4,
            'name'         => 'Oman Arab Bank',
            'cr_number'    => '1234567',
            'phone'        => '+96890000000',
            'email'        => 'info@omanarabank.org',
            'bank_id'      => 1,
            // 'bank_account_number' => '1234567890',
            'country_id'   => 1,
            'region_id'    => 1,
            'city_id'      => 1,

            'address_line1' => 'Al Khuwair, Muscat',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Commission profile + one share
        |--------------------------------------------------------------------------
        */
        DB::table('commission_profiles')->insert([
            'id'              => 1,

            'name'            => 'Default 100% to Organization',
            'description'     => 'All donations go to Oman Charity Foundation',
            'is_active'       => true,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);



        DB::table('commission_profile_shares')->insert([
            'id'              => 1,
            'commission_profile_id' => 1,
            'label' => 'Organization Share',
            'organization_id' => 1,
            'percentage' => 80.00,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);


        DB::table('commission_profile_shares')->insert([
            'id'              => 2,
            'commission_profile_id' => 1,
            'organization_id' => 3,
             'label' => 'Organization Share2',
            'percentage' => 18.00,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);


        DB::table('commission_profile_shares')->insert([
            'id'              => 3,
            'commission_profile_id' => 1,
            'organization_id' => 4,
            'percentage' => 2.00,
             'label' => 'Organization Share3',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);



        /*
        |--------------------------------------------------------------------------
        | Charity location (where the device physically sits)
        |--------------------------------------------------------------------------
        */
        DB::table('charity_locations')->insert([
            'id'              => 1,
            'organization_id' => 1,
            'name'            => 'Masjid',

            'phone'           => '+96891111111',
            'email'           => 'ahmed@omancharity.org',
            'country_id'      => 1,
            'region_id'       => 1,
            'city_id'         => 1,

            'address_line1'   => 'Main Entrance, Al Khuwair Mall',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Apps + pivot (apps installed on device)
        |--------------------------------------------------------------------------
        */
        DB::table('apps')->insert([
            'id'           => 1,
            'name'         => 'Charity Kiosk App',
            'package_name' => 'com.example.charitykiosk',
            'platform'     => 'android',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Device
        |--------------------------------------------------------------------------
        */
        DB::table('devices')->insert([
            'id'                   => 1,
            'device_brand_id'      => 1,
            'device_model_id'      => 1,
            // 'serial_number'        => 'DEV-OM-MCT-0001',
            'model_number'         => '052084009031127',
            'kiosk_id'             => '6535665',
            'login_generated_token' => Str::random(40),
            'country_id'           => 1,
            'region_id'            => 1,
            'city_id'              => 1,

            'charity_location_id'  => 1,
            'commission_profile_id' => 1,
            'bank_id'              => 1,
            'status'               => 'active',
            'installed_at'         => $now,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);



        DB::table('devices')->insert([
            'id'                   => 2,
            'device_brand_id'      => 1,
            'device_model_id'      => 2,
            // 'serial_number'        => 'DEV-OM-MCT-0001',
            'model_number'         => '052084009ddd031127',
            'kiosk_id'             => '6546626',
            'login_generated_token' => Str::random(40),
            'country_id'           => 1,
            'region_id'            => 1,
            'city_id'              => 1,

            'charity_location_id'  => 1,
            'commission_profile_id' => 1,
            'bank_id'              => 1,
            'status'               => 'active',
            'installed_at'         => $now,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);

        DB::table('device_apps')->insert([
            'device_id'    => 1,
            'app_id'       => 1,

        ]);
    }
}
