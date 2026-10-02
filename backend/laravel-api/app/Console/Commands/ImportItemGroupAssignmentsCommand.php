<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\ItemGroup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * One-off loader for the legacy "Item Maintenance" listing export (ItemCode + ItemGroup
 * columns on a sheet named ItemMaintenance, headers on row 4, data from row 6 — matches
 * the "AmendYN" legacy export shape also used by the Import Wizard's other item-side
 * templates). Only ever writes Item.item_group_id on an EXISTING item matched by
 * item_code — every other column (name, uom, price, tax, stock) is left untouched, and
 * an item_code with no match in this file is simply never touched, not nulled.
 *
 * Idempotent: rerunning just reassigns the same groups. Not wired into the Import
 * Wizard UI since this is a one-time data load, not a repeatable self-service import.
 */
class ImportItemGroupAssignmentsCommand extends Command
{
    protected $signature = 'items:import-item-groups {path} {--dry-run : Compute and report without saving any changes}';

    protected $description = 'Backfill Item.item_group_id from a legacy Item Maintenance listing export, by ItemCode, without touching any other Item column.';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('ItemMaintenance') ?? $spreadsheet->getSheet(0);
        $rows = $sheet->toArray(null, true, true, true);

        $pairs = [];
        foreach ($rows as $i => $row) {
            if ($i < 6) {
                continue;
            }

            $code = trim((string) ($row['B'] ?? ''));
            $group = trim((string) ($row['C'] ?? ''));

            if ($code === '' || $group === '') {
                continue;
            }

            $pairs[$code] = $group;
        }

        if ($pairs === []) {
            $this->warn('No ItemCode/ItemGroup rows found in this file.');

            return self::SUCCESS;
        }

        DB::beginTransaction();

        $groupsCreated = 0;
        $updated = 0;
        $unchanged = 0;
        $notFound = [];

        foreach ($pairs as $itemCode => $groupName) {
            $item = Item::query()->where('item_code', $itemCode)->first();

            if (! $item) {
                $notFound[] = $itemCode;

                continue;
            }

            // withTrashed(): the name column's unique index still rejects a soft-deleted
            // row's name, so a lookup that ignores trashed rows would try to recreate it
            // and hit a duplicate-entry error instead of finding and restoring it.
            $group = ItemGroup::withTrashed()->whereRaw('LOWER(name) = ?', [mb_strtolower($groupName)])->first();

            if (! $group) {
                $group = ItemGroup::query()->create(['name' => $groupName]);
                $groupsCreated++;
            } elseif ($group->trashed()) {
                $group->restore();
            }

            if ($item->item_group_id === $group->id) {
                $unchanged++;

                continue;
            }

            $item->item_group_id = $group->id;
            $item->save();
            $updated++;
        }

        if ($dryRun) {
            DB::rollBack();
            $this->warn('--dry-run: no changes were saved.');
        } else {
            DB::commit();
        }

        $this->line('');
        $this->info("Item Groups created: {$groupsCreated}");
        $this->info("Items updated: {$updated}");
        $this->info("Items already in the right group: {$unchanged}");
        $this->info('Item codes not found in this system: '.count($notFound));

        if ($notFound !== []) {
            $this->line(implode(', ', array_slice($notFound, 0, 50)));
            if (count($notFound) > 50) {
                $this->line('... and '.(count($notFound) - 50).' more.');
            }
        }

        return self::SUCCESS;
    }
}
