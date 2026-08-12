<?php
declare(strict_types=1);

namespace Bitrix\Landing\AI\SiteBuilder\Font;

final class AiFontPolicy
{
	public const FALLBACK_TEXT_CLASS = 'g-font-open-sans';
	public const FALLBACK_DISPLAY_CLASS = 'g-font-montserrat';

	private const SERVICE_FONT_CLASS_PREFIXES = [
		'g-font-size-',
		'g-font-weight-',
		'g-font-style-',
	];

	private const FONTS = [
		'g-font-open-sans' => ['name' => 'Open Sans', 'generic' => 'sans-serif', 'roles' => ['text'], 'locales' => ['latin', 'cyrillic']],
		'g-font-roboto' => ['name' => 'Roboto', 'generic' => 'sans-serif', 'roles' => ['text'], 'locales' => ['latin', 'cyrillic']],
		'g-font-inter' => ['name' => 'Inter', 'generic' => 'sans-serif', 'roles' => ['text'], 'locales' => ['latin', 'cyrillic']],
		'g-font-noto-sans' => ['name' => 'Noto Sans', 'generic' => 'sans-serif', 'roles' => ['text'], 'locales' => ['latin', 'cyrillic']],
		'g-font-pt-sans' => ['name' => 'PT Sans', 'generic' => 'sans-serif', 'roles' => ['text'], 'locales' => ['latin', 'cyrillic']],
		'g-font-pt-sans-caption' => ['name' => 'PT Sans Caption', 'generic' => 'sans-serif', 'roles' => ['text'], 'locales' => ['latin', 'cyrillic']],
		'g-font-pt-sans-narrow' => ['name' => 'PT Sans Narrow', 'generic' => 'sans-serif', 'roles' => ['display'], 'locales' => ['latin', 'cyrillic']],
		'g-font-ibm-plex-sans' => ['name' => 'IBM Plex Sans', 'generic' => 'sans-serif', 'roles' => ['text'], 'locales' => ['latin', 'cyrillic']],
		'g-font-source-sans-3' => ['name' => 'Source Sans 3', 'generic' => 'sans-serif', 'roles' => ['text'], 'locales' => ['latin', 'cyrillic']],
		'g-font-manrope' => ['name' => 'Manrope', 'generic' => 'sans-serif', 'roles' => ['text'], 'locales' => ['latin', 'cyrillic']],
		'g-font-nunito-sans' => ['name' => 'Nunito Sans', 'generic' => 'sans-serif', 'roles' => ['text'], 'locales' => ['latin', 'cyrillic']],
		'g-font-rubik' => ['name' => 'Rubik', 'generic' => 'sans-serif', 'roles' => ['text'], 'locales' => ['latin', 'cyrillic']],
		'g-font-mulish' => ['name' => 'Mulish', 'generic' => 'sans-serif', 'roles' => ['text'], 'locales' => ['latin', 'cyrillic']],
		'g-font-fira-sans' => ['name' => 'Fira Sans', 'generic' => 'sans-serif', 'roles' => ['text'], 'locales' => ['latin', 'cyrillic']],
		'g-font-ubuntu' => ['name' => 'Ubuntu', 'generic' => 'sans-serif', 'roles' => ['text'], 'locales' => ['latin', 'cyrillic']],
		'g-font-lora' => ['name' => 'Lora', 'generic' => 'serif', 'roles' => ['text', 'display'], 'locales' => ['latin', 'cyrillic']],
		'g-font-merriweather' => ['name' => 'Merriweather', 'generic' => 'serif', 'roles' => ['text'], 'locales' => ['latin', 'cyrillic']],
		'g-font-pt-serif' => ['name' => 'PT Serif', 'generic' => 'serif', 'roles' => ['text'], 'locales' => ['latin', 'cyrillic']],
		'g-font-noto-serif' => ['name' => 'Noto Serif', 'generic' => 'serif', 'roles' => ['text'], 'locales' => ['latin', 'cyrillic']],
		'g-font-ibm-plex-serif' => ['name' => 'IBM Plex Serif', 'generic' => 'serif', 'roles' => ['text'], 'locales' => ['latin', 'cyrillic']],
		'g-font-montserrat' => ['name' => 'Montserrat', 'generic' => 'sans-serif', 'roles' => ['display'], 'locales' => ['latin', 'cyrillic']],
		'g-font-oswald' => ['name' => 'Oswald', 'generic' => 'sans-serif', 'roles' => ['display'], 'locales' => ['latin', 'cyrillic']],
		'g-font-raleway' => ['name' => 'Raleway', 'generic' => 'sans-serif', 'roles' => ['display'], 'locales' => ['latin', 'cyrillic']],
		'g-font-roboto-slab' => ['name' => 'Roboto Slab', 'generic' => 'serif', 'roles' => ['display'], 'locales' => ['latin', 'cyrillic']],
		'g-font-playfair-display' => ['name' => 'Playfair Display', 'generic' => 'serif', 'roles' => ['display'], 'locales' => ['latin', 'cyrillic']],
		'g-font-cormorant-infant' => ['name' => 'Cormorant Infant', 'generic' => 'serif', 'roles' => ['display'], 'locales' => ['latin', 'cyrillic']],
		'g-font-cormorant-garamond' => ['name' => 'Cormorant Garamond', 'generic' => 'serif', 'roles' => ['display'], 'locales' => ['latin', 'cyrillic']],
		'g-font-alegreya-sans' => ['name' => 'Alegreya Sans', 'generic' => 'sans-serif', 'roles' => ['display', 'text'], 'locales' => ['latin', 'cyrillic']],
		'g-font-space-grotesk' => ['name' => 'Space Grotesk', 'generic' => 'sans-serif', 'roles' => ['display'], 'locales' => ['latin']],
		'g-font-sora' => ['name' => 'Sora', 'generic' => 'sans-serif', 'roles' => ['display'], 'locales' => ['latin']],
		'g-font-lobster' => ['name' => 'Lobster', 'generic' => 'cursive', 'roles' => ['display'], 'locales' => ['latin', 'cyrillic']],
		'g-font-noto-sans-jp' => ['name' => 'Noto Sans JP', 'generic' => 'sans-serif', 'roles' => ['text'], 'locales' => ['jp']],
		'g-font-noto-sans-sc' => ['name' => 'Noto Sans SC', 'generic' => 'sans-serif', 'roles' => ['text'], 'locales' => ['sc']],
		'g-font-noto-sans-kr' => ['name' => 'Noto Sans KR', 'generic' => 'sans-serif', 'roles' => ['text'], 'locales' => ['kr']],
		'g-font-noto-sans-tc' => ['name' => 'Noto Sans TC', 'generic' => 'sans-serif', 'roles' => ['text'], 'locales' => ['tc']],
		'g-font-noto-serif-jp' => ['name' => 'Noto Serif JP', 'generic' => 'serif', 'roles' => ['display', 'text'], 'locales' => ['jp']],
		'g-font-noto-serif-sc' => ['name' => 'Noto Serif SC', 'generic' => 'serif', 'roles' => ['display', 'text'], 'locales' => ['sc']],
		'g-font-ibm-plex-sans-jp' => ['name' => 'IBM Plex Sans JP', 'generic' => 'sans-serif', 'roles' => ['text'], 'locales' => ['jp']],
		'g-font-ibm-plex-sans-sc' => ['name' => 'IBM Plex Sans SC', 'generic' => 'sans-serif', 'roles' => ['text'], 'locales' => ['sc']],
	];

	private const RECOMMENDED_PAIRS = [
		'default/safe' => ['text' => 'g-font-open-sans', 'display' => 'g-font-montserrat'],
		'tech/SaaS/product' => ['text' => 'g-font-inter', 'display' => 'g-font-space-grotesk'],
		'B2B/finance' => ['text' => 'g-font-ibm-plex-sans', 'display' => 'g-font-montserrat'],
		'friendly/local service' => ['text' => 'g-font-nunito-sans', 'display' => 'g-font-rubik'],
		'education/expert' => ['text' => 'g-font-pt-sans', 'display' => 'g-font-roboto-slab'],
		'editorial/premium' => ['text' => 'g-font-lora', 'display' => 'g-font-playfair-display'],
		'hospitality/fashion/culture' => ['text' => 'g-font-lora', 'display' => 'g-font-cormorant-infant'],
		'cyrillic-safe' => ['text' => 'g-font-pt-sans', 'display' => 'g-font-roboto-slab'],
		'cjk-safe' => ['text' => 'g-font-noto-sans-jp', 'display' => 'g-font-noto-serif-jp'],
	];

	public static function getPromptInstructions(): string
	{
		$lines = [
			'- Use only `g-font-*` from the allowlist below; do not invent font slugs from memory.',
			'- If you are not sure about the font, language, or topic: root/text `'
				. self::FALLBACK_TEXT_CLASS
				. '`, headings `'
				. self::FALLBACK_DISPLAY_CLASS
				. '`.',
			'- For the root `<section>`, choose a text-safe font; for `h1-h3`, you may choose a display/accent font from the allowlist.',
			'- For Cyrillic and multilingual sites, prefer cyrillic-safe fonts; for CJK, use only CJK-safe families.',
			'- Text-safe allowlist: ' . implode(', ', self::getClassesByRole('text')) . '.',
			'- Display/accent allowlist: ' . implode(', ', self::getClassesByRole('display')) . '.',
			'- CJK-safe allowlist: ' . implode(', ', self::getClassesByLocale(['jp', 'sc', 'kr', 'tc'])) . '.',
			'- Recommended pairs: ' . self::formatRecommendedPairs() . '.',
		];

		return implode("\n", $lines);
	}

	public static function getAllowedFontClasses(): array
	{
		return array_keys(self::FONTS);
	}

	public static function getFontByClass(string $fontClass): ?array
	{
		$fontClass = self::normalizeFontClass($fontClass);
		if ($fontClass === '')
		{
			return null;
		}

		return self::FONTS[$fontClass] ?? null;
	}

	public static function isAllowedFontClass(string $fontClass): bool
	{
		return self::getFontByClass($fontClass) !== null;
	}

	public static function resolveFontForClass(string $fontClass): array
	{
		$fontClass = self::normalizeFontClass($fontClass);
		$font = self::getFontByClass($fontClass);
		if ($font !== null)
		{
			return [
				'class' => $fontClass,
				'name' => (string)$font['name'],
				'generic' => (string)$font['generic'],
				'isFallback' => false,
			];
		}

		$fallback = self::getFallbackTextFont();

		return [
			'class' => $fontClass !== '' ? $fontClass : self::FALLBACK_TEXT_CLASS,
			'name' => (string)$fallback['name'],
			'generic' => (string)$fallback['generic'],
			'isFallback' => true,
		];
	}

	public static function normalizeHtmlFontClasses(string $html): string
	{
		if (trim($html) === '')
		{
			return $html;
		}

		return preg_replace_callback('/\bclass\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/iu', static function (array $matches): string
		{
			$rawValue = (string)($matches[1] ?? '');
			$quote = '';
			$classValue = '';
			if (str_starts_with($rawValue, '"'))
			{
				$quote = '"';
				$classValue = (string)($matches[2] ?? '');
			}
			elseif (str_starts_with($rawValue, "'"))
			{
				$quote = "'";
				$classValue = (string)($matches[3] ?? '');
			}
			else
			{
				$classValue = (string)($matches[4] ?? '');
			}

			$classParts = preg_split('/\s+/', trim($classValue)) ?: [];
			$normalizedParts = [];
			foreach ($classParts as $classPart)
			{
				$classPart = trim($classPart);
				if ($classPart === '')
				{
					continue;
				}

				if (!preg_match('/^g-font-[a-z0-9-]+$/iu', $classPart))
				{
					$normalizedParts[] = $classPart;
					continue;
				}

				$fontClass = self::normalizeFontClass($classPart);
				$normalizedParts[] = self::isServiceFontClass($fontClass) || self::isAllowedFontClass($fontClass)
					? $fontClass
					: self::FALLBACK_TEXT_CLASS;
			}

			$normalizedValue = implode(' ', $normalizedParts);
			if ($quote === '')
			{
				return 'class=' . $normalizedValue;
			}

			return 'class=' . $quote . $normalizedValue . $quote;
		}, $html) ?? $html;
	}

	private static function getFallbackTextFont(): array
	{
		return self::FONTS[self::FALLBACK_TEXT_CLASS];
	}

	private static function getClassesByRole(string $role): array
	{
		$classes = [];
		foreach (self::FONTS as $fontClass => $font)
		{
			if (in_array($role, $font['roles'], true))
			{
				$classes[] = $fontClass;
			}
		}

		return $classes;
	}

	private static function getClassesByLocale(array $locales): array
	{
		$classes = [];
		foreach (self::FONTS as $fontClass => $font)
		{
			if (array_intersect($locales, $font['locales']) !== [])
			{
				$classes[] = $fontClass;
			}
		}

		return $classes;
	}

	private static function formatRecommendedPairs(): string
	{
		$pairs = [];
		foreach (self::RECOMMENDED_PAIRS as $label => $pair)
		{
			$pairs[] = $label . ' root ' . $pair['text'] . ' + headings ' . $pair['display'];
		}

		return implode('; ', $pairs);
	}

	private static function normalizeFontClass(string $fontClass): string
	{
		return strtolower(trim($fontClass));
	}

	private static function isServiceFontClass(string $fontClass): bool
	{
		foreach (self::SERVICE_FONT_CLASS_PREFIXES as $prefix)
		{
			if (str_starts_with($fontClass, $prefix))
			{
				return true;
			}
		}

		return false;
	}
}
