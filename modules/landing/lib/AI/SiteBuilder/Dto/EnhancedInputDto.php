<?php
namespace Bitrix\Landing\AI\SiteBuilder\Dto;

final class EnhancedInputDto extends AbstractDto
{
	public const REQUIRED_FIELDS = [
		'category',
		'title',
		'brief',
		'profile',
		'audience',
		'design',
		'offer',
		'palette',
		'previewPrompt',
		'imageStyleBrief',
	];
	public const SEO_FIELDS = [
		'seoTitle',
		'seoDescription',
		'seoKeywords',
	];
	public const DOMAINS_FIELD = 'domains';
	public const SCHEMA_FIELDS = [
		'category',
		'title',
		'brief',
		'profile',
		'audience',
		'design',
		'offer',
		'palette',
		'previewPrompt',
		'imageStyleBrief',
		'seoTitle',
		'seoDescription',
		'seoKeywords',
	];

	private array $data;

	private function __construct(array $data)
	{
		$this->data = $data;
	}

	public static function fromArray(array $data): ?self
	{
		$normalized = [];
		foreach (self::REQUIRED_FIELDS as $field)
		{
			if (!array_key_exists($field, $data))
			{
				return null;
			}

			$value = is_scalar($data[$field]) ? (string)$data[$field] : '';
			$value = self::normalizeText($value);
			if ($value === '')
			{
				return null;
			}

			$normalized[$field] = $value;
		}
		foreach (self::SEO_FIELDS as $field)
		{
			$value = is_scalar($data[$field] ?? null) ? (string)$data[$field] : '';
			$normalized[$field] = self::normalizeText($value);
		}
		$normalized[self::DOMAINS_FIELD] = self::normalizeDomains($data[self::DOMAINS_FIELD] ?? null);

		return new self($normalized);
	}

	public static function hasRequiredKeys(array $data): bool
	{
		foreach (self::REQUIRED_FIELDS as $field)
		{
			if (!array_key_exists($field, $data))
			{
				return false;
			}
		}

		return true;
	}

	public function toArray(): array
	{
		return $this->data;
	}

	public function buildTitleSeed(string $fallback = ''): string
	{
		$seed = $this->data['title']
			?? $this->data['category']
			?? $this->data['brief']
			?? $fallback;
		$seed = self::normalizeText((string)$seed);
		if ($seed === '')
		{
			$seed = self::normalizeText($fallback);
		}
		if ($seed === '')
		{
			$seed = 'Magic Bitrix24 Sites AI 2.0';
		}
		if (mb_strlen($seed) <= 72)
		{
			return $seed;
		}

		return rtrim(mb_substr($seed, 0, 71)) . '…';
	}

	public function getPreviewPrompt(): string
	{
		return $this->data['previewPrompt'];
	}

	public function getTitle(): string
	{
		return self::normalizeText($this->data['title'] ?? '');
	}

	public function getCategory(): string
	{
		return self::normalizeText($this->data['category'] ?? '');
	}

	/**
	 * @return string[]
	 */
	public function getAiDomains(): array
	{
		return $this->data[self::DOMAINS_FIELD] ?? [];
	}

	public function getThemeColor(): ?string
	{
		$palette = $this->data['palette'] ?? '';
		$accentColor = null;

		if (preg_match('/(?:Accent|Акцент)\s*:\s*(#[0-9a-fA-F]{6})/u', $palette, $matches) === 1)
		{
			$accentColor = self::normalizeHexColor($matches[1]);
		}

		if (preg_match('/(?:Primary|Основные)\s*:\s*([^;]+)/u', $palette, $matches) === 1)
		{
			preg_match_all('/#[0-9a-fA-F]{6}/', $matches[1], $mainMatches);
			foreach ($mainMatches[0] as $color)
			{
				$color = self::normalizeHexColor($color);
				if ($color !== null && self::isSuitableThemeColor($color))
				{
					return $color;
				}
			}
		}

		if (preg_match('/\baccent\s*=\s*(#[0-9a-fA-F]{6})/i', $palette, $matches) === 1)
		{
			return self::normalizeHexColor($matches[1]);
		}

		return $accentColor;
	}

	public function getSeoTitle(): string
	{
		$title = $this->data['seoTitle'] ?? '';
		if ($title === '')
		{
			$title = $this->buildTitleSeed();
		}

		return self::truncate($title, 140);
	}

	public function getSeoDescription(): string
	{
		$description = $this->data['seoDescription'] ?? '';
		if ($description === '')
		{
			$description = $this->data['brief'] ?? $this->data['profile'] ?? $this->data['category'] ?? '';
		}

		return self::truncate($description, 300);
	}

	public function getSeoKeywords(): string
	{
		$keywords = $this->data['seoKeywords'] ?? '';
		if ($keywords === '')
		{
			$keywords = implode(', ', array_filter([
				$this->data['title'] ?? '',
				$this->data['category'] ?? '',
			]));
		}

		return self::truncate($keywords, 250);
	}

	private static function normalizeText(string $value): string
	{
		$value = preg_replace('/\s+/u', ' ', $value) ?: $value;

		return trim($value);
	}

	/**
	 * @return string[]
	 */
	private static function normalizeDomains(mixed $value): array
	{
		if (!is_array($value))
		{
			return [];
		}

		$domains = [];
		foreach ($value as $item)
		{
			if (!is_scalar($item))
			{
				continue;
			}

			$domain = self::normalizeText((string)$item);
			if ($domain !== '')
			{
				$domains[] = $domain;
			}
		}

		return $domains;
	}

	private static function normalizeHexColor(string $value): ?string
	{
		$value = mb_strtolower(trim($value));
		if (preg_match('/^#[0-9a-f]{6}$/', $value) !== 1)
		{
			return null;
		}

		return $value;
	}

	private static function isSuitableThemeColor(string $color): bool
	{
		$red = hexdec(substr($color, 1, 2));
		$green = hexdec(substr($color, 3, 2));
		$blue = hexdec(substr($color, 5, 2));

		$max = max($red, $green, $blue);
		$min = min($red, $green, $blue);
		$lightness = ($max + $min) / 2;
		$saturation = $max === 0 ? 0 : ($max - $min) / $max;

		return $saturation >= 0.15 && $lightness >= 30 && $lightness <= 225;
	}

	private static function truncate(string $value, int $maxLength): string
	{
		if (mb_strlen($value) <= $maxLength)
		{
			return $value;
		}

		return rtrim(mb_substr($value, 0, $maxLength));
	}
}
