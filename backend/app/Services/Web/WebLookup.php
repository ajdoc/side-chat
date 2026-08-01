<?php

namespace App\Services\Web;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Looks a query up in two free, keyless sources and merges what comes back.
 *
 * DuckDuckGo's Instant Answer API and Wikipedia's action API both answer without a key, a
 * signup, or a bill — which is why `/web` can exist at all without adding a paid
 * dependency to the app. What they *don't* do is return ranked web results: both answer
 * factual and definitional questions ("what is X", "who wrote Y") and have nothing to say
 * about news or opinion. That limit is real and the command says so out loud rather than
 * letting a miss look like a failure.
 *
 * Both are queried every time and the results combined, because they're good at different
 * halves of the same question: DuckDuckGo often has a one-line answer or a definition and
 * a set of related links, Wikipedia has the paragraph of context. Falling back from one to
 * the other would throw away whichever arrived second.
 *
 * Never called on the request path — see {@see \App\Jobs\PostWebLookup} for why.
 */
class WebLookup
{
    private const DUCKDUCKGO = 'https://api.duckduckgo.com/';

    private const WIKIPEDIA = 'https://en.wikipedia.org/w/api.php';

    /** Two lookups run back to back inside one queued job; neither may hang it. */
    private const TIMEOUT = 5;

    /**
     * Wikipedia's API etiquette guide asks that clients identify themselves, and an
     * anonymous agent is the documented way to get rate-limited.
     */
    private const USER_AGENT = 'SideChat/1.0 (+/web command)';

    /**
     * Everything found for a query, ready to render.
     *
     * @return array{
     *     answer: ?string,
     *     abstract: ?array{text: string, source: string, url: string},
     *     wikipedia: ?array{title: string, extract: string, url: string},
     *     related: array<int, array{text: string, url: string}>,
     *     merged: bool,
     * }
     */
    public function lookup(string $query): array
    {
        $ddg = $this->duckDuckGo($query);
        $wiki = $this->wikipedia($query);

        // DuckDuckGo's abstract is *very often* the opening of the same Wikipedia article
        // fetched a moment ago. Printing both shows the reader the same sentence twice
        // under two headings, which reads like a bug. When they agree the Wikipedia
        // extract wins — it's the longer of the two — and the answer is credited to both.
        $merged = false;

        if ($ddg['abstract'] !== null && $wiki !== null && $this->sameSubstance($ddg['abstract']['text'], $wiki['extract'])) {
            $ddg['abstract'] = null;
            $merged = true;
        }

        return [
            'answer' => $ddg['answer'],
            'abstract' => $ddg['abstract'],
            'wikipedia' => $wiki,
            'related' => $ddg['related'],
            'merged' => $merged,
        ];
    }

    /**
     * DuckDuckGo Instant Answer API — abstracts, definitions, direct answers, related links.
     *
     * @return array{
     *     answer: ?string,
     *     abstract: ?array{text: string, source: string, url: string},
     *     related: array<int, array{text: string, url: string}>,
     * }
     */
    private function duckDuckGo(string $query): array
    {
        $empty = ['answer' => null, 'abstract' => null, 'related' => []];

        $data = $this->get(self::DUCKDUCKGO, [
            'q' => $query,
            'format' => 'json',
            'no_html' => 1,
            'no_redirect' => 1,
            // Collapse "did you mean one of these 40 things" pages: never an answer, and
            // they crowd out the related links that sometimes are.
            'skip_disambig' => 1,
            't' => 'sidechat',
        ]);

        if ($data === null) {
            return $empty;
        }

        // `Answer` is the computed one-liner (a conversion, a sum, a definition). Usually
        // the best thing on the page when it exists, and usually absent.
        $answer = $this->text($data['Answer'] ?? null) ?? $this->text($data['Definition'] ?? null);

        $abstractText = $this->text($data['AbstractText'] ?? null);
        $abstractUrl = $this->text($data['AbstractURL'] ?? null);

        return [
            'answer' => $answer,
            'abstract' => $abstractText !== null && $abstractUrl !== null
                ? [
                    'text' => $abstractText,
                    'source' => $this->text($data['AbstractSource'] ?? null) ?? 'DuckDuckGo',
                    'url' => $abstractUrl,
                ]
                : null,
            'related' => $this->relatedTopics($data['RelatedTopics'] ?? []),
        ];
    }

    /**
     * The handful of "see also" links DuckDuckGo returns alongside an abstract.
     *
     * Capped at three, and not out of politeness: the app renders at most three link
     * previews per message, so a fourth link is one the reader sees as bare text while its
     * neighbours get cards. Nested topic *groups* (a `Topics` key instead of a `FirstURL`)
     * are skipped — they're headings, not destinations.
     *
     * @param  mixed  $topics
     * @return array<int, array{text: string, url: string}>
     */
    private function relatedTopics(mixed $topics): array
    {
        if (! is_array($topics)) {
            return [];
        }

        $out = [];

        foreach ($topics as $topic) {
            if (count($out) >= 3) {
                break;
            }

            if (! is_array($topic)) {
                continue;
            }

            $text = $this->text($topic['Text'] ?? null);
            $url = $this->text($topic['FirstURL'] ?? null);

            if ($text !== null && $url !== null) {
                $out[] = ['text' => $text, 'url' => $url];
            }
        }

        return $out;
    }

    /**
     * Wikipedia's action API: search for the best-matching article and return its intro.
     *
     * One request rather than the two the REST API would need (search for a title, then
     * fetch that title's summary): `generator=search` feeds the search hit straight into
     * the extract query, and `inprop=url` returns the canonical link in the same response,
     * so nothing here has to build a URL out of a title and hope the escaping matches.
     *
     * @return array{title: string, extract: string, url: string}|null
     */
    private function wikipedia(string $query): ?array
    {
        $data = $this->get(self::WIKIPEDIA, [
            'action' => 'query',
            'format' => 'json',
            'formatversion' => 2,
            'generator' => 'search',
            'gsrsearch' => $query,
            'gsrlimit' => 1,
            'prop' => 'extracts|info',
            'exintro' => 1,      // the lead section only
            'explaintext' => 1,  // no wiki markup to strip
            'inprop' => 'url',
            'redirects' => 1,
        ]);

        $page = $data['query']['pages'][0] ?? null;

        if (! is_array($page)) {
            return null;
        }

        $extract = $this->text($page['extract'] ?? null);
        $title = $this->text($page['title'] ?? null);
        $url = $this->text($page['fullurl'] ?? null);

        if ($extract === null || $title === null || $url === null) {
            return null;
        }

        // Returned whole: how much of a lead section belongs in a chat message is a
        // question about how the answer reads, so the formatter decides it.
        return ['title' => $title, 'extract' => $extract, 'url' => $url];
    }

    /**
     * Are these two blurbs saying the same thing?
     *
     * Deliberately crude: strip anything parenthesised, drop everything that isn't a
     * letter or digit, and compare the first 90 characters of what's left.
     *
     * The parenthetical strip is the part that earns its keep. Wikipedia opens a great
     * many articles with a pronunciation gloss — "Idempotence (UK: , US: ) is the property
     * of…" — that DuckDuckGo's copy of the same sentence doesn't carry. Without removing
     * it the two texts diverge at character 12 and the same sentence gets printed twice,
     * which is the exact outcome this check exists to prevent.
     */
    private function sameSubstance(string $a, string $b): bool
    {
        $normalise = static function (string $s): string {
            $s = preg_replace('/\([^)]*\)/', '', $s) ?? $s;

            return substr(strtolower(preg_replace('/[^a-z0-9]+/i', '', $s) ?? ''), 0, 90);
        };

        $a = $normalise($a);

        // Both prefixes must be long enough to mean something — two short stubs matching
        // is a coincidence, not a duplicate.
        return strlen($a) >= 40 && $a === $normalise($b);
    }

    /** A non-empty trimmed string, or null — both APIs use "" where they mean "nothing". */
    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * GET and decode, or null.
     *
     * Every failure — DNS, timeout, a 500, malformed JSON — collapses to null on purpose.
     * There's nothing to be done about any of them here, and the caller's handling is
     * identical in every case: carry on with whatever the other source returned.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    private function get(string $url, array $query): ?array
    {
        try {
            $response = Http::withUserAgent(self::USER_AGENT)
                ->timeout(self::TIMEOUT)
                ->connectTimeout(self::TIMEOUT)
                ->accept('application/json')
                ->get($url, $query);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }
}
