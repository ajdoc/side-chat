<?php

namespace App\Http\Requests\Encryption;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Uploading a wrapped key backup.
 *
 * Only ever about the caller's own account — the user id comes from the token, never the
 * payload. The validation is size and shape, because that is all the server is entitled to
 * know: it cannot check that the blob decrypts, and any rule that tried would be a rule about
 * contents it must not be able to read.
 *
 * The `iterations` floor is the one substantive check. It is not protecting the server, it is
 * protecting the person from a client — buggy, or hostile, or just old — that wraps their
 * history behind a KDF cheap enough to brute-force. A blob is only as strong as the work that
 * went into its key, and once it is stored, that is fixed forever.
 */
class StoreKeyBackupRequest extends FormRequest
{
    /** Refuse anything weaker than this, whatever the client claims. */
    public const MINIMUM_ITERATIONS = 100_000;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // A megabyte holds a great many chains and is nowhere near a channel-per-day
            // account's worth. Bounded so the table can't be used as free storage.
            'blob' => ['required', 'string', 'max:1048576'],
            'kdf' => ['required', 'string', 'in:PBKDF2-SHA256'],
            'iterations' => ['required', 'integer', 'min:'.self::MINIMUM_ITERATIONS, 'max:10000000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'iterations.min' => 'That backup was made with weaker settings than we’re willing to store.',
        ];
    }
}
