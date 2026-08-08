<?php

namespace App\Services\Web;

/**
 * Turns a {@see WebLookup} result into the message the channel sees.
 *
 * The shape is one **source card** per source that had something to say — a linked title
 * followed by a quoted extract — under a header naming what was asked:
 *
 *     🔎 **merkle tree**
 *
 *     **[Merkle tree — Wikipedia](https://en.wikipedia.org/wiki/Merkle_tree)**
 *     > In cryptography and computer science, a hash tree or Merkle tree is a tree in
 *     > which every "leaf" node is labelled with the cryptographic hash of a data block.
 *
 *     _Related:_ [Binary tree](…) · [Blockchain](…)
 *
 * Three constraints shape it. A message body is capped at 2000 characters (see
 * SendMessageData); the timeline renders at most three link previews per message (see
 * LinkPreviewService); and the renderer is a deliberately small markdown subset (see
 * useMarkdown.ts) — bold, italic, links, blockquote and lists are in, headings and tables
 * are not. Everything below stays inside that subset.
 */
class WebLookupFormatter
{
    /** Under the 2000 cap, with margin for the header when a message has to split. */
    private const LIMIT = 1900;

    /** Any more and the tail of the message is bare URLs while the top has preview cards. */
    private const LINK_BUDGET = 3;

    /**
     * How much of an extract to quote.
     *
     * A Wikipedia lead section runs to several paragraphs, and pasting one into a chat
     * timeline buries the conversation above it. Two or three sentences answer the
     * question; anyone who wants the rest has the link, and the preview card underneath
     * already carries a longer description.
     */
    private const EXTRACT = 320;

    /**
     * DuckDuckGo's direct answer, capped.
     *
     * Usually a few words ("42", "1.61 km"), but it's third-party text going straight into
     * a message with nothing else bounding it — a definition can run long.
     */
    private const ANSWER = 400;

    /** A link label is a chip, not a sentence; the API hands back whole sentences. */
    private const LABEL = 60;

    /**
     * @param  array<string, mixed>  $result  A {@see WebLookup::lookup()} return value.
     * @return array<int, string> One message per element — usually one.
     */
    public function format(string $query, array $result): array
    {
        if ($result['answer'] === null && $result['abstract'] === null && $result['wikipedia'] === null) {
            // Said plainly. There's no ranked-web-results source behind this command, and
            // a vague "something went wrong" would send someone debugging a feature that
            // is working exactly as designed.
            return ['🔎 **'.$this->escape($query)."**\n\nNothing found. `/web` searches DuckDuckGo's instant answers and Wikipedia, which are good at facts and definitions and have nothing to say about news or opinion."];
        }

        $blocks = ['🔎 **'.$this->escape($query).'**'];
        $links = 0;

        // DuckDuckGo's computed one-liner — a conversion, a sum, a definition. Rare, and
        // when it exists it *is* the answer, so it leads and gets no quote marks around
        // it: it's a fact, not an excerpt from somewhere.
        if ($result['answer'] !== null) {
            $blocks[] = '**'.$this->trim($result['answer'], self::ANSWER).'**';
        }

        if ($result['wikipedia'] !== null) {
            $blocks[] = $this->card(
                $result['wikipedia']['title'].' — Wikipedia',
                $result['wikipedia']['url'],
                $result['wikipedia']['extract'],
            );
            $links++;
        }

        // Only reached when this abstract is genuinely different from the Wikipedia one —
        // WebLookup drops it when the two are the same text, which is most of the time.
        if ($result['abstract'] !== null) {
            $blocks[] = $this->card(
                $result['abstract']['source'],
                $result['abstract']['url'],
                $result['abstract']['text'],
            );
            $links++;
        }

        if (($related = $this->related($result['related'], self::LINK_BUDGET - $links)) !== null) {
            $blocks[] = $related;
        }

        return $this->split(implode("\n\n", $blocks));
    }

    /**
     * One source: a linked title, then its extract as a quote.
     *
     * The title carries the link rather than the URL sitting on its own line, because the
     * unfurled preview card that appears underneath already shows the destination — a bare
     * URL as well makes the same link visible three times over. Verified that this still
     * unfurls: LinkPreviewService::extractUrls handles the markdown form, closing paren
     * and all.
     */
    private function card(string $title, string $url, string $text): string
    {
        return '**'.$this->link($title, $url).'**'."\n".$this->quote($this->trim($text));
    }

    /**
     * A markdown link with the URL in angle brackets — `[label](<url>)`.
     *
     * The brackets are the point. A plain `(url)` destination ends at the first unbalanced
     * `)`, so a URL containing one renders as literal `[label](` followed by a stray link,
     * and these URLs come from third parties that will eventually serve one. The angle
     * form accepts anything but `<`, `>` and a newline, none of which appear in a URL —
     * and LinkPreviewService::extractUrls stops at those same characters, so the preview
     * card still resolves. Both halves verified against the real renderer and extractor.
     */
    private function link(string $label, string $url): string
    {
        return '['.$this->escape($label, links: true).'](<'.$url.'>)';
    }

    /** Prefix every line, so a multi-line extract stays inside one quote block. */
    private function quote(string $text): string
    {
        return '> '.str_replace("\n", "\n> ", trim($text));
    }

    /** Cut to a whole sentence, so text never ends mid-clause. */
    private function trim(string $text, ?int $limit = null): string
    {
        $limit ??= self::EXTRACT;

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit);
        $lastStop = (int) mb_strrpos($cut, '. ');

        // Only honour a sentence break that isn't so early it throws the answer away.
        return $lastStop > $limit / 2
            ? mb_substr($cut, 0, $lastStop + 1)
            : rtrim($cut).'…';
    }

    /**
     * The "see also" line, trimmed to whatever link budget is left over.
     *
     * @param  array<int, array{text: string, url: string}>  $related
     */
    private function related(array $related, int $budget): ?string
    {
        if ($related === [] || $budget < 1) {
            return null;
        }

        $parts = array_map(function (array $item): string {
            // The link text is a whole sentence in the API; only the lead phrase before
            // the first dash is the topic's name.
            $label = $this->trim(trim(explode(' - ', $item['text'], 2)[0]), self::LABEL);

            return $this->link($label, $item['url']);
        }, array_slice($related, 0, $budget));

        return '_Related:_ '.implode(' · ', $parts);
    }

    /**
     * Break a long answer on paragraph boundaries.
     *
     * A source card plus a second one can clear the message cap. The frontend has
     * chunkMessage() for the same problem on the typing path; this is the server-side
     * equivalent for text the app generates itself.
     *
     * @return array<int, string>
     */
    private function split(string $message): array
    {
        if (mb_strlen($message) <= self::LIMIT) {
            return [$message];
        }

        $chunks = [];
        $current = '';

        foreach (explode("\n\n", $message) as $block) {
            $candidate = $current === '' ? $block : $current."\n\n".$block;

            if (mb_strlen($candidate) <= self::LIMIT) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $chunks[] = $current;
            }

            // A single block over the limit can't be split on a boundary that isn't there
            // — hard-cut it rather than dropping it.
            $current = mb_strlen($block) <= self::LIMIT ? $block : mb_substr($block, 0, self::LIMIT - 1).'…';
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Defuse markdown in text that came from somewhere else.
     *
     * Article titles arrive from a third party and the query from whoever typed the
     * command; an unescaped `*` or `_` in either would reflow the rest of the message.
     * Text used as a *link label* additionally escapes brackets, since a `]` in a title
     * would close the link early and spill a raw URL into the message.
     */
    private function escape(string $text, bool $links = false): string
    {
        $text = str_replace(['*', '_', '`'], ['\*', '\_', '\`'], $text);

        return $links ? str_replace(['[', ']'], ['\[', '\]'], $text) : $text;
    }
}
