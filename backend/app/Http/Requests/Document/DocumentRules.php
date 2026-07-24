<?php

namespace App\Http\Requests\Document;

/**
 * The upload rules for a Side Desk document, shared by the channel and side chat requests.
 * The Docs app is for office documents — PDF, Word, Excel — so the allowlist is tight, and
 * the size cap matches what a private disk should reasonably hold per file.
 */
final class DocumentRules
{
    /** @return array<string, mixed> */
    public static function upload(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,csv', 'max:20480'],
        ];
    }
}
