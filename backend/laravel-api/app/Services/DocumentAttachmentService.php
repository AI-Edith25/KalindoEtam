<?php

namespace App\Services;

use App\Models\DocumentAttachment;
use App\Repositories\DocumentAttachmentRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DocumentAttachmentService
{
    protected const DISK = 'local';

    public function __construct(protected DocumentAttachmentRepository $documentAttachmentRepository) {}

    public function upload(string $attachableType, string $attachableId, UploadedFile $file): DocumentAttachment
    {
        return DB::transaction(function () use ($attachableType, $attachableId, $file) {
            $path = $file->store('attachments', self::DISK);

            return $this->documentAttachmentRepository->create([
                'attachable_type' => $attachableType,
                'attachable_id' => $attachableId,
                'disk' => self::DISK,
                'file_path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'extension' => $file->getClientOriginalExtension(),
                'mime_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'uploaded_by' => Auth::id(),
            ]);
        });
    }

    public function download(DocumentAttachment $attachment): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return Storage::disk($attachment->disk)->download($attachment->file_path, $attachment->original_filename);
    }

    public function delete(DocumentAttachment $attachment): void
    {
        DB::transaction(function () use ($attachment) {
            Storage::disk($attachment->disk)->delete($attachment->file_path);
            $this->documentAttachmentRepository->delete($attachment);
        });
    }

    public function listFor(string $attachableType, string $attachableId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->documentAttachmentRepository->forAttachable($attachableType, $attachableId, $perPage);
    }
}
