<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Seeder;

class DevelopmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('project_templates')->insert([
            'tenant_id' => 1,
            'code' => 'PROJECT_TEMPLATE_HALAL',
            'name' => 'Halal Project Template',
            'desc' => 'Description for Halal Project Template',
        ]);

        DB::table('project_template_steps')->insert([
            [
                'tenant_id' => 1,
                'project_template_id' => 1,
                'name' => 'Data Umum Pelaku Usaha',
                'desc' => 'Melengkapi data umum pelaku usaha',
                'order_no' => 1,
                'term' => 'day',
                'estimation_time_in_term' => 1,
            ],
            [
                'tenant_id' => 1,
                'project_template_id' => 1,
                'name' => 'Kebijakan Halal',
                'desc' => 'Melengkapi dokumen poster halal haram dan penerapan SJPH',
                'order_no' => 2,
                'term' => 'day',
                'estimation_time_in_term' => 3,
            ],
            [
                'tenant_id' => 1,
                'project_template_id' => 1,
                'name' => 'Dokumentasi Pelatihan (Pre-Test) & Hasil Post Test',
                'desc' => 'Melengkapi hasil pelatihan dan hasil ujian',
                'order_no' => 3,
                'term' => 'day',
                'estimation_time_in_term' => 5,
            ],
            [
                'tenant_id' => 1,
                'project_template_id' => 1,
                'name' => 'Daftar Bahan Halal dan Daftar Produk',
                'desc' => 'Melengkapi dokumen daftar bahan halal dan daftar produk',
                'order_no' => 4,
                'term' => 'day',
                'estimation_time_in_term' => 3,
            ],
            [
                'tenant_id' => 1,
                'project_template_id' => 1,
                'name' => 'Matrik Bahan vs Produk',
                'desc' => 'Melengkapi dokumen matrik bahan vs produk',
                'order_no' => 5,
                'term' => 'day',
                'estimation_time_in_term' => 2,
            ],
            [
                'tenant_id' => 1,
                'project_template_id' => 1,
                'name' => 'Dokumen Pembelian Bahan',
                'desc' => 'Melengkapi dokumen pembelian bahan',
                'order_no' => 6,
                'term' => 'day',
                'estimation_time_in_term' => 2,
            ],
            [
                'tenant_id' => 1,
                'project_template_id' => 1,
                'name' => 'Dokumen Pemeriksaan Bahan',
                'desc' => 'Melengkapi dokumen pemeriksaan bahan',
                'order_no' => 7,
                'term' => 'day',
                'estimation_time_in_term' => 2
            ],
            [
                'tenant_id' => 1,
                'project_template_id' => 1,
                'name' => 'Dokumen Penyimpanan Bahan',
                'desc' => 'Melengkapi dokumen penyimpanan bahan',
                'order_no' => 8,
                'term' => 'day',
                'estimation_time_in_term' => 2,
            ],
            [
                'tenant_id' => 1,
                'project_template_id' => 1,
                'name' => 'Dokumen Pengolahan Bahan',
                'desc' => 'Melengkapi dokumen pengolahan bahan',
                'order_no' => 9,
                'term' => 'day',
                'estimation_time_in_term' => 2,
            ],
            [
                'tenant_id' => 1,
                'project_template_id' => 1,
                'name' => 'Dokumen Distribusi Bahan',
                'desc' => 'Melengkapi dokumen distribusi bahan',
                'order_no' => 10,
                'term' => 'day',
                'estimation_time_in_term' => 2,
            ],
            [
                'tenant_id' => 1,
                'project_template_id' => 1,
                'name' => 'Dokumen Produksi Bahan',
                'desc' => 'Melengkapi dokumen produksi bahan',
                'order_no' => 11,
                'term' => 'day',
                'estimation_time_in_term' => 2,
            ],
            [
                'tenant_id' => 1,
                'project_template_id' => 1,
                'name' => 'Dokumen Pernyataan Bebas Babi',
                'desc' => 'Melengkapi dokumen pernyataan bebas babi',
                'order_no' => 12,
                'term' => 'day',
                'estimation_time_in_term' => 2
            ],
            [
                'tenant_id' => 1,
                'project_template_id' => 1,
                'name' => 'Dokumen Layout Dapur DLL',
                'desc' => 'Melengkapi dokumen layout dapur dan lain - lain',
                'order_no' => 13,
                'term' => 'day',
                'estimation_time_in_term' => 2
            ],
            [
                'tenant_id' => 1,
                'project_template_id' => 1,
                'name' => 'Dokumen Diagram Alur Proses',
                'desc' => 'Melengkapi dokumen diagram alur proses',
                'order_no' => 14,
                'term' => 'day',
                'estimation_time_in_term' => 2
            ],
            [
                'tenant_id' => 1,
                'project_template_id' => 1,
                'name' => 'Dokumen Komitmen Penggunaan Bahan Halal',
                'desc' => 'Melengkapi dokumen komitmen penggunaan bahan halal',
                'order_no' => 15,
                'term' => 'day',
                'estimation_time_in_term' => 2
            ],
            [
                'tenant_id' => 1,
                'project_template_id' => 1,
                'name' => 'Dokumen SK - Penunjukan Penyelia',
                'desc' => 'Melengkapi dokumen SK - Penunjukan Penyelia',
                'order_no' => 16,
                'term' => 'day',
                'estimation_time_in_term' => 2
            ],
            [
                'tenant_id' => 1,
                'project_template_id' => 1,
                'name' => 'Dokumen SK - Penunjukan Tim Manajemen',
                'desc' => 'Melengkapi dokumen SK - Penunjukan Tim Manajemen',
                'order_no' => 17,
                'term' => 'day',
                'estimation_time_in_term' => 2
            ]
        ]);

        DB::table('form_project_step_template')->insert([
            [
                'project_template_step_id' => 1,
                'form_template_id' => 1
            ],
            [
                'project_template_step_id' => 2,
                'form_template_id' => 2
            ],
            [
                'project_template_step_id' => 2,
                'form_template_id' => 3
            ],
            [
                'project_template_step_id' => 3,
                'form_template_id' => 7
            ],
            [
                'project_template_step_id' => 3,
                'form_template_id' => 8
            ],
            [
                'project_template_step_id' => 4,
                'form_template_id' => 9
            ],
            [
                'project_template_step_id' => 4,
                'form_template_id' => 10
            ],
            [
                'project_template_step_id' => 5,
                'form_template_id' => 11
            ],
            [
                'project_template_step_id' => 6,
                'form_template_id' => 12
            ],
            [
                'project_template_step_id' => 7,
                'form_template_id' => 13
            ],
            [
                'project_template_step_id' => 8,
                'form_template_id' => 16
            ],
            [
                'project_template_step_id' => 10,
                'form_template_id' => 19
            ],
            [
                'project_template_step_id' => 11,
                'form_template_id' => 18
            ],
            [
                'project_template_step_id' => 16,
                'form_template_id' => 5
            ],
            [
                'project_template_step_id' => 16,
                'form_template_id' => 6
            ],
            [
                'project_template_step_id' => 17,
                'form_template_id' => 4
            ]
        ]);
    }
}
