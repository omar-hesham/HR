<?php

use App\Domain\Access\Role;
use App\Filament\Pages\UploadDocuments;
use App\Models\Document;
use App\Services\Malware\MalwareScanner;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

it('refuses uploads with a clear message when no malware scanner is configured', function () {
    $user = userWithRole(Role::Owner);
    app()->detectEnvironment(fn () => 'production');
    config(['recruitment.malware.driver' => 'null']);
    app()->forgetInstance(MalwareScanner::class);

    Livewire::actingAs($user)->test(UploadDocuments::class)
        ->set('data.files', [UploadedFile::fake()->createWithContent('cv.docx', file_get_contents(cvFixture('10_en_quality.docx')))])
        ->call('upload')
        ->assertNotified(__('app.upload.scanner_unavailable'));

    expect(Document::query()->count())->toBe(0);
});
