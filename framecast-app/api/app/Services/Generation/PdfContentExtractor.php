<?php

namespace App\Services\Generation;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Smalot\PdfParser\Parser;

/**
 * Pulls usable prose out of an uploaded PDF so it can drive a script.
 *
 * Same contract as UrlContentExtractor: if we can't get real text, we throw
 * with something the user can act on. Generating a video from an empty or
 * garbled extraction is worse than saying the file didn't work.
 *
 * The failure that matters here is the SCANNED pdf. A PDF exported from Docs,
 * Word or LaTeX carries a text layer and extracts cleanly; a scan or an
 * image-only brochure carries pictures of words and yields nothing. We detect
 * that and say so, because "your PDF is a scan, we can't read it" is a
 * completely different instruction to the user than "something went wrong".
 */
class PdfContentExtractor
{
    /** Below this many characters we treat the extraction as failed. */
    public const MIN_CONTENT_CHARS = 200;

    /**
     * Hard cap on what we hand the model directly. Longer documents are
     * condensed first (see NEEDS_SUMMARY_CHARS) rather than truncated, so a
     * 40-page report doesn't silently become a video about pages 1–3.
     */
    public const MAX_CONTENT_CHARS = 6000;

    /** Above this, summarise before scripting. */
    public const NEEDS_SUMMARY_CHARS = 6000;

    /**
     * @return array{text: string, pages: int, truncated: bool}
     *
     * @throws RuntimeException with a user-facing message.
     */
    public function extract(string $absolutePath, ?string $originalName = null): array
    {
        $label = $originalName ?: 'that PDF';

        if (! is_readable($absolutePath)) {
            throw new RuntimeException("We couldn't open {$label}. Please try uploading it again.");
        }

        try {
            $document = (new Parser())->parseFile($absolutePath);
            $pages    = count($document->getPages());
            $text     = $document->getText();
        } catch (\Throwable $e) {
            Log::warning('PdfContentExtractor: parse failed', [
                'file'  => $originalName,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(
                "We couldn't read {$label}. It may be password-protected, or saved in a format we can't open. ".
                'Try re-exporting it as a standard PDF, or paste the text directly.'
            );
        }

        $text = $this->normalise($text);

        if (mb_strlen($text) < self::MIN_CONTENT_CHARS) {
            Log::warning('PdfContentExtractor: no text layer', [
                'file'   => $originalName,
                'pages'  => $pages,
                'length' => mb_strlen($text),
            ]);

            throw new RuntimeException(
                "We couldn't find any readable text in {$label}. Scanned documents and image-only PDFs ".
                "store pictures of words rather than the words themselves, so there's nothing to read. ".
                'Try a PDF exported from a document editor, or paste the text directly.'
            );
        }

        $truncated = mb_strlen($text) > self::MAX_CONTENT_CHARS;

        return [
            'text'      => $truncated ? mb_substr($text, 0, self::MAX_CONTENT_CHARS) : $text,
            'pages'     => $pages,
            'truncated' => $truncated,
        ];
    }

    /** Does this document need condensing before it can drive a script? */
    public function needsSummary(string $text): bool
    {
        return mb_strlen($text) > self::NEEDS_SUMMARY_CHARS;
    }

    /**
     * PDF text extraction produces ragged whitespace — hard line breaks mid
     * sentence from the original layout, and runs of spaces where columns were.
     * Collapse it so the model sees prose rather than a page layout.
     */
    private function normalise(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Keep paragraph breaks, flatten single line breaks inside them.
        $text = preg_replace('/\n{2,}/', "\x00", $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/', ' ', $text) ?? $text;
        $text = str_replace("\x00", "\n\n", $text);
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;

        return trim($text);
    }
}
