<?php

declare(strict_types=1);

namespace Bitrix\Landing\Markup\Moderation;

use Bitrix\Landing\Markup\Package;

/**
 * Moderated text of a markup package: page meta, texts of the declared images, names and
 * field labels of the declared forms and the visible text of the entry document, ordered
 * by descending signal.
 *
 * Nothing is truncated: the whole text is moderated, chunk by chunk.
 */
class PageTextExtractor
{
	/** Input budget of a single moderation call, not a cap on the moderated text. */
	public const CHUNK_CHARS = 16000;

	/**
	 * Every chunk is a free model request. The visible text of an entry within its size limit
	 * fits; more text can only come from padded meta or image texts, and such a page is
	 * refused rather than moderated in part.
	 */
	public const MAX_CHUNKS = 64;

	private const META_FIELDS = ['title', 'pageTitle', 'description', 'ogTitle', 'ogDescription'];

	private const IMAGE_FIELDS = ['alt', 'prompt'];

	/**
	 * @return array<int, string>
	 */
	public function extract(Package $package): array
	{
		return array_merge(
			$this->extractMeta($package),
			$this->extractImageTexts($package),
			$this->extractFormTexts($package),
			$this->extractPageText($package),
		);
	}

	/**
	 * @return array<int, array<int, string>>
	 */
	public function extractChunks(Package $package): array
	{
		$chunks = [];
		$chunk = [];
		$length = 0;

		foreach ($this->extract($package) as $line)
		{
			foreach ($this->splitLine($line) as $part)
			{
				$partLength = mb_strlen($part);
				if ($chunk !== [] && ($length + $partLength) > self::CHUNK_CHARS)
				{
					$chunks[] = $chunk;
					$chunk = [];
					$length = 0;
				}
				$chunk[] = $part;
				$length += $partLength;
			}
		}

		if ($chunk !== [])
		{
			$chunks[] = $chunk;
		}

		return $chunks;
	}

	/**
	 * @return array<int, string>
	 */
	private function extractMeta(Package $package): array
	{
		$meta = $package->getMeta();
		$lines = [];
		foreach (self::META_FIELDS as $field)
		{
			$value = $this->normalize($meta[$field] ?? null);
			if ($value !== '')
			{
				$lines[] = $value;
			}
		}

		return $lines;
	}

	/**
	 * @return array<int, string>
	 */
	private function extractImageTexts(Package $package): array
	{
		$lines = [];
		foreach ($package->getImageDeclaration() as $image)
		{
			foreach (self::IMAGE_FIELDS as $field)
			{
				$value = $this->normalize($image[$field] ?? null);
				if ($value !== '')
				{
					$lines[] = $value;
				}
			}
		}

		return $lines;
	}

	/**
	 * A malformed part of the declaration is skipped: the extractor does not rely on the validation.
	 * @return array<int, string>
	 */
	private function extractFormTexts(Package $package): array
	{
		$lines = [];
		foreach ($package->getFormDeclaration() as $form)
		{
			$lines[] = $this->normalize($form['name'] ?? null);
			foreach ((is_array($form['fields'] ?? null) ? $form['fields'] : []) as $field)
			{
				$lines[] = $this->normalize(is_array($field) ? ($field['label'] ?? null) : null);
			}
		}

		return array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
	}

	/**
	 * @return array<int, string>
	 */
	private function extractPageText(Package $package): array
	{
		$entry = $package->getEntry();
		if ($entry === null)
		{
			return [];
		}

		$html = $this->stripNonText((string)$entry['content']);

		$lines = [];
		// Only what a browser opens as a tag is markup: "< 100" or a trailing "<" stays text.
		foreach ((array)preg_split('#<(?:/?[a-z]|[!?])[^>]*>#is', $html) as $piece)
		{
			$text = $this->normalize(html_entity_decode((string)$piece, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
			if ($text !== '')
			{
				$lines[] = $text;
			}
		}

		return $lines;
	}

	private function stripNonText(string $html): string
	{
		$patterns = [
			'#<!--.*?-->#s',
			// The name must end the way a tag name does, or <style-card> is taken for <style>.
			'#<(script|style)(?=[\s/>])[^>]*>.*?</\1\s*>#is',
			// An unclosed script or style leaves its body as page text otherwise.
			'#<(script|style)(?=[\s/>])[^>]*>.*$#is',
		];

		foreach ($patterns as $pattern)
		{
			$stripped = preg_replace($pattern, ' ', $html);
			if (is_string($stripped))
			{
				$html = $stripped;
			}
		}

		return $html;
	}

	private function normalize(mixed $value): string
	{
		if (!is_scalar($value))
		{
			return '';
		}

		$text = (string)$value;
		$collapsed = preg_replace('/[\s\x{00a0}]+/u', ' ', $text);

		return trim(is_string($collapsed) ? $collapsed : $text);
	}

	/**
	 * @return array<int, string>
	 */
	private function splitLine(string $line): array
	{
		if (mb_strlen($line) <= self::CHUNK_CHARS)
		{
			return [$line];
		}

		return mb_str_split($line, self::CHUNK_CHARS);
	}
}
