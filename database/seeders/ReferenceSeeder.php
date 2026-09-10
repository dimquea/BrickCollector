<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the fixed rows of the internal dictionaries.
 *
 * Idempotent: safe to re-run, existing rows are left as the user edited them.
 */
class ReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->statuses();
        $this->links();
    }

    /**
     * Two statuses ship with the application and cannot be deleted. Their
     * name column holds a fallback only: the interface shows a translated
     * label looked up by code, otherwise they would stay in whatever language
     * the installation happened to use.
     */
    private function statuses(): void
    {
        foreach ([
            ['code' => 'box', 'name' => 'Box', 'sort' => 10],
            ['code' => 'manual', 'name' => 'Instructions', 'sort' => 20],
        ] as $status) {
            DB::table('ref_statuses')->updateOrInsert(
                ['code' => $status['code']],
                ['name' => $status['name'], 'is_system' => true, 'sort' => $status['sort']],
            );
        }
    }

    /**
     * Six fixed blocks of external links. The user edits them but cannot add
     * or remove rows.
     *
     * URL patterns are deliberately left empty for now: they need checking
     * against the live sites, and that is a separate task. A block with an
     * empty pattern simply renders no button, so an unfilled row is harmless.
     *
     * Known good, from BrickStore's src/bricklink/core.cpp:
     *   https://www.bricklink.com/catalogItem.asp?{type}={id}   (+ &C={color} for parts)
     */
    private function links(): void
    {
        $blocks = [
            ['code' => 'bricklink', 'label' => 'BrickLink', 'enabled' => true, 'sort' => 10],
            ['code' => 'rebrickable', 'label' => 'Rebrickable', 'enabled' => false, 'sort' => 20],
            ['code' => 'brickset', 'label' => 'Brickset', 'enabled' => false, 'sort' => 30],
            ['code' => 'instructions', 'label' => null, 'enabled' => false, 'sort' => 40],
            ['code' => 'custom1', 'label' => null, 'enabled' => false, 'sort' => 50],
            ['code' => 'custom2', 'label' => null, 'enabled' => false, 'sort' => 60],
        ];

        foreach ($blocks as $block) {
            DB::table('ref_links')->insertOrIgnore([
                'code' => $block['code'],
                'enabled' => $block['enabled'],
                'label' => $block['label'],
                'url_set' => null,
                'url_minifig' => null,
                'url_part' => null,
                'sort' => $block['sort'],
            ]);
        }
    }
}
