<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ServesStoredFiles;
use App\Models\Widget;
use App\Services\Widgets\VideoWidget;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves a video widget's *uploaded* clips over a short-lived signed URL — the same
 * private-disk model {@see AttachmentController} and {@see SpaceDocumentFileController} use,
 * and for the same reason: a <video> tag can't carry a bearer token, so the signature is the
 * grant. {@see VideoWidget::forViewer()} mints those URLs per viewer.
 *
 * Uniquely here, the file isn't a row — it's an entry in the widget's JSON state, so the URL
 * names the widget plus the source's id and this looks it up. Only an entry the widget
 * actually holds, and actually uploaded, can be served: a signature for one clip must not
 * become a way to read any path the state happens to contain.
 *
 * The response is a *file* response, not a stream, which is what buys range support — see
 * {@see ServesStoredFiles}. Without it, scrubbing a long video would be impossible.
 */
class WidgetVideoController extends Controller
{
    use ServesStoredFiles;

    public function show(Widget $widget, string $source): BinaryFileResponse
    {
        abort_unless($widget->type === 'video', 404);

        $clip = $this->uploadedSource($widget, $source);

        return response()->file($this->storedFilePath($clip['disk'], $clip['path']), [
            'Content-Type' => $clip['mime'] ?? 'video/mp4',
            'Content-Disposition' => 'inline; filename="'.addslashes((string) ($clip['title'] ?? 'video')).'"',
        ]);
    }

    /**
     * The playlist entry this signed URL names — 404 unless it's one we host ourselves.
     *
     * @return array<string, mixed>
     */
    private function uploadedSource(Widget $widget, string $sourceId): array
    {
        foreach ((array) ($widget->state['playlist'] ?? []) as $entry) {
            if (! is_array($entry) || (string) ($entry['id'] ?? '') !== $sourceId) {
                continue;
            }

            // `provider: upload` is the only kind with bytes of ours behind it. A YouTube or
            // embed entry has no path, and a direct link is someone else's file.
            abort_unless(
                ($entry['provider'] ?? null) === 'upload'
                    && is_string($entry['disk'] ?? null)
                    && is_string($entry['path'] ?? null),
                404,
            );

            return $entry;
        }

        abort(404);
    }
}
