<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\ReceiptEntry;
use App\Models\User;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * D1 (UAT review 2026-08-12): mime restriction (image/PDF) applies only to
 * ReceiptEntry proof-of-transfer uploads — every other attachable_type must
 * keep accepting any file type, exactly as before this ticket.
 */
class DocumentAttachmentMimeScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(DocumentEngineSeeder::class);

        Permission::query()->firstOrCreate(['name' => 'system.document_attachment.create', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('system.document_attachment.create');
        Sanctum::actingAs($user);
    }

    public function test_image_upload_to_a_receipt_entry_is_accepted(): void
    {
        $customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $receipt = ReceiptEntry::query()->create([
            'customer_id' => $customer->id,
            'receipt_date' => now()->toDateString(),
            'total_amount' => 100000,
            'allocated_amount' => 0,
        ]);

        $response = $this->postJson('/api/v1/attachments', [
            'attachable_type' => ReceiptEntry::class,
            'attachable_id' => $receipt->id,
            'file' => UploadedFile::fake()->image('bukti-transfer.jpg'),
        ]);

        $response->assertCreated();
    }

    public function test_non_image_pdf_upload_to_a_receipt_entry_is_rejected(): void
    {
        $customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $receipt = ReceiptEntry::query()->create([
            'customer_id' => $customer->id,
            'receipt_date' => now()->toDateString(),
            'total_amount' => 100000,
            'allocated_amount' => 0,
        ]);

        $response = $this->postJson('/api/v1/attachments', [
            'attachable_type' => ReceiptEntry::class,
            'attachable_id' => $receipt->id,
            'file' => UploadedFile::fake()->create('proof.txt', 10, 'text/plain'),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('file');
    }

    public function test_non_image_upload_to_a_different_attachable_type_is_still_accepted_unchanged(): void
    {
        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);

        $response = $this->postJson('/api/v1/attachments', [
            'attachable_type' => Company::class,
            'attachable_id' => $company->id,
            'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ]);

        $response->assertCreated();
    }
}
