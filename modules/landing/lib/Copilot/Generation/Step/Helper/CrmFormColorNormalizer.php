<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step\Helper;

final class CrmFormColorNormalizer
{
	public const COLOR_KEYS = [
		'primary',
		'primaryText',
		'text',
		'background',
		'fieldBorder',
		'fieldBackground',
		'fieldFocusBackground',
	];

	private const TEXT_CONTRAST_RATIO = 4.5;
	private const BORDER_CONTRAST_RATIO = 3.0;
	private const DARK_TEXT = '#0f172a';
	private const LIGHT_TEXT = '#ffffff';
	private const PURE_DARK_TEXT = '#000000';

	private const LIGHT_PRESET = [
		'primary' => '#0f172a',
		'primaryText' => '#ffffff',
		'text' => '#0f172a',
		'background' => '#ffffff',
		'fieldBorder' => '#64748b',
		'fieldBackground' => '#f8fafc',
		'fieldFocusBackground' => '#ffffff',
	];

	private const DARK_PRESET = [
		'primary' => '#ffffff',
		'primaryText' => '#0f172a',
		'text' => '#ffffff',
		'background' => '#0f172a',
		'fieldBorder' => '#94a3b8',
		'fieldBackground' => '#1e293b',
		'fieldFocusBackground' => '#334155',
	];

	private const TAILWIND_COLORS = [
		'white' => '#ffffff',
		'black' => '#000000',
		'slate-50' => '#f8fafc',
		'slate-100' => '#f1f5f9',
		'slate-200' => '#e2e8f0',
		'slate-300' => '#cbd5e1',
		'slate-400' => '#94a3b8',
		'slate-500' => '#64748b',
		'slate-600' => '#475569',
		'slate-700' => '#334155',
		'slate-800' => '#1e293b',
		'slate-900' => '#0f172a',
		'slate-950' => '#020617',
		'zinc-50' => '#fafafa',
		'zinc-100' => '#f4f4f5',
		'zinc-900' => '#18181b',
		'zinc-950' => '#09090b',
		'neutral-50' => '#fafafa',
		'neutral-100' => '#f5f5f5',
		'neutral-900' => '#171717',
		'neutral-950' => '#0a0a0a',
		'stone-50' => '#fafaf9',
		'stone-100' => '#f5f5f4',
		'stone-900' => '#1c1917',
		'stone-950' => '#0c0a09',
	];

	public static function buildContextFromHtmlBlock(array $htmlBlock, string $html = ''): array
	{
		$spec = is_array($htmlBlock['spec'] ?? null) ? $htmlBlock['spec'] : [];
		$palette = '';
		if (is_string($spec['palette'] ?? null))
		{
			$palette = (string)$spec['palette'];
		}
		elseif (is_string($htmlBlock['palette'] ?? null))
		{
			$palette = (string)$htmlBlock['palette'];
		}

		return [
			'spec' => $spec,
			'palette' => $palette,
			'html' => $html,
		];
	}

	public function normalize(array $rawColors = [], array $context = []): array
	{
		$palette = $this->extractPalette($context);
		$sectionBackground = $this->resolveSectionBackground($palette, $context);
		$isDark = $this->isDarkColor($sectionBackground);
		$colors = $this->buildFallbackColors($palette, $sectionBackground, $isDark);

		foreach (self::COLOR_KEYS as $key)
		{
			$value = $rawColors[$key] ?? null;
			if (!is_string($value))
			{
				continue;
			}

			$baseColor = match ($key)
			{
				'background' => $sectionBackground,
				'fieldBackground', 'fieldFocusBackground', 'fieldBorder' => $colors['background'],
				'text' => $colors['background'],
				'primaryText' => $colors['primary'],
				default => $colors['background'],
			};

			$normalized = $this->normalizeColorValue($value, $baseColor);
			if ($normalized !== null)
			{
				$colors[$key] = $normalized;
			}
		}

		return $this->ensureContrast($colors, $isDark);
	}

	public function encodeForHtmlAttribute(array $colors): string
	{
		$encoded = json_encode(
			array_intersect_key($colors, array_flip(self::COLOR_KEYS)),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		return is_string($encoded) ? $encoded : '{}';
	}

	public function contrastRatio(string $firstColor, string $secondColor): float
	{
		$first = $this->parseHexColor($firstColor);
		$second = $this->parseHexColor($secondColor);
		if ($first === null || $second === null)
		{
			return 1.0;
		}

		$firstLuminance = $this->relativeLuminance($first);
		$secondLuminance = $this->relativeLuminance($second);
		$lighter = max($firstLuminance, $secondLuminance);
		$darker = min($firstLuminance, $secondLuminance);

		return ($lighter + 0.05) / ($darker + 0.05);
	}

	private function buildFallbackColors(array $palette, string $sectionBackground, bool $isDark): array
	{
		$colors = $isDark ? self::DARK_PRESET : self::LIGHT_PRESET;
		$surface = $palette['surface'] ?? $palette['bg'] ?? null;
		$surfaceAlt = $palette['surface_alt'] ?? null;
		$accent = $palette['accent'] ?? null;
		$accentText = $palette['accent_text'] ?? null;
		$text = $palette['text'] ?? null;

		if ($surface !== null)
		{
			$colors['background'] = $this->normalizeColorValue($surface, $sectionBackground) ?? $colors['background'];
		}
		if ($surfaceAlt !== null)
		{
			$colors['fieldBackground'] = $this->normalizeColorValue($surfaceAlt, $colors['background']) ?? $colors['fieldBackground'];
		}
		if ($accent !== null)
		{
			$colors['primary'] = $this->normalizeColorValue($accent, $colors['background']) ?? $colors['primary'];
		}
		if ($accentText !== null)
		{
			$accentText = $this->normalizeColorValue($accentText, $colors['primary']);
			if ($accentText !== null && $this->contrastRatio($accentText, $colors['primary']) >= self::TEXT_CONTRAST_RATIO)
			{
				$colors['primaryText'] = $accentText;
			}
		}
		if ($text !== null)
		{
			$text = $this->normalizeColorValue($text, $colors['background']);
			if ($text !== null)
			{
				$colors['text'] = $text;
			}
		}

		return $colors;
	}

	private function ensureContrast(array $colors, bool $isDark): array
	{
		$colors['primaryText'] = $this->ensureTextContrast($colors['primaryText'], [$colors['primary']]);
		$colors['text'] = $this->ensureTextContrast($colors['text'], [
			$colors['background'],
			$colors['fieldBackground'],
			$colors['fieldFocusBackground'],
		]);

		if ($this->contrastRatio($colors['text'], $colors['fieldBackground']) < self::TEXT_CONTRAST_RATIO)
		{
			$colors['fieldBackground'] = $isDark ? self::DARK_PRESET['fieldBackground'] : self::LIGHT_PRESET['fieldBackground'];
		}
		if ($this->contrastRatio($colors['text'], $colors['fieldFocusBackground']) < self::TEXT_CONTRAST_RATIO)
		{
			$colors['fieldFocusBackground'] = $isDark ? self::DARK_PRESET['fieldFocusBackground'] : self::LIGHT_PRESET['fieldFocusBackground'];
		}

		$colors['text'] = $this->ensureTextContrast($colors['text'], [
			$colors['background'],
			$colors['fieldBackground'],
			$colors['fieldFocusBackground'],
		]);
		$colors['fieldBorder'] = $this->ensureBorderContrast(
			$colors['fieldBorder'],
			$colors['fieldBackground'],
			$colors['background'],
			$isDark,
		);

		return array_intersect_key($colors, array_flip(self::COLOR_KEYS));
	}

	private function ensureTextContrast(string $currentColor, array $surfaces): string
	{
		if ($this->passesAllContrasts($currentColor, $surfaces, self::TEXT_CONTRAST_RATIO))
		{
			return $currentColor;
		}

		$candidates = [
			self::DARK_TEXT,
			self::LIGHT_TEXT,
			self::PURE_DARK_TEXT,
		];
		$bestColor = $currentColor;
		$bestRatio = 0.0;
		foreach ($candidates as $candidate)
		{
			$ratio = $this->minContrastRatio($candidate, $surfaces);
			if ($ratio > $bestRatio)
			{
				$bestColor = $candidate;
				$bestRatio = $ratio;
			}
			if ($ratio >= self::TEXT_CONTRAST_RATIO)
			{
				return $candidate;
			}
		}

		return $bestColor;
	}

	private function ensureBorderContrast(
		string $currentColor,
		string $fieldBackground,
		string $background,
		bool $isDark,
	): string
	{
		if (
			$this->contrastRatio($currentColor, $fieldBackground) >= self::BORDER_CONTRAST_RATIO
			|| $this->contrastRatio($currentColor, $background) >= self::BORDER_CONTRAST_RATIO
		)
		{
			return $currentColor;
		}

		$candidates = $isDark
			? ['#94a3b8', '#cbd5e1', '#ffffff']
			: ['#64748b', '#475569', '#334155'];
		foreach ($candidates as $candidate)
		{
			if (
				$this->contrastRatio($candidate, $fieldBackground) >= self::BORDER_CONTRAST_RATIO
				|| $this->contrastRatio($candidate, $background) >= self::BORDER_CONTRAST_RATIO
			)
			{
				return $candidate;
			}
		}

		return $candidates[0];
	}

	private function passesAllContrasts(string $color, array $surfaces, float $threshold): bool
	{
		foreach ($surfaces as $surface)
		{
			if ($this->contrastRatio($color, (string)$surface) < $threshold)
			{
				return false;
			}
		}

		return true;
	}

	private function minContrastRatio(string $color, array $surfaces): float
	{
		$ratios = [];
		foreach ($surfaces as $surface)
		{
			$ratios[] = $this->contrastRatio($color, (string)$surface);
		}

		return $ratios === [] ? 1.0 : min($ratios);
	}

	private function extractPalette(array $context): array
	{
		$palette = [];
		$rawPalette = '';
		if (is_string($context['palette'] ?? null))
		{
			$rawPalette = (string)$context['palette'];
		}
		elseif (is_array($context['spec'] ?? null) && is_string($context['spec']['palette'] ?? null))
		{
			$rawPalette = (string)$context['spec']['palette'];
		}

		if ($rawPalette !== '')
		{
			preg_match_all(
				'/\b(bg|surface|surface_alt|text|muted|accent|accent_text)\s*=\s*(#[0-9a-fA-F]{3}|#[0-9a-fA-F]{6}|#[0-9a-fA-F]{8})\b/',
				$rawPalette,
				$matches,
				PREG_SET_ORDER,
			);
			foreach ($matches as $match)
			{
				$color = $this->normalizeColorValue($match[2], '#ffffff');
				if ($color !== null)
				{
					$palette[$match[1]] = $color;
				}
			}
		}

		return $palette;
	}

	private function resolveSectionBackground(array $palette, array $context): string
	{
		$background = $palette['surface'] ?? $palette['bg'] ?? null;
		if ($background !== null)
		{
			return $background;
		}

		$fromClasses = $this->extractBackgroundFromTailwindClasses((string)($context['containerClass'] ?? ''));
		if ($fromClasses !== null)
		{
			return $fromClasses;
		}

		return $this->extractBackgroundFromTailwindClasses((string)($context['html'] ?? '')) ?? '#ffffff';
	}

	private function extractBackgroundFromTailwindClasses(string $source): ?string
	{
		if ($source === '')
		{
			return null;
		}

		if (preg_match('/(?:^|\s|")bg-\[(#[0-9a-fA-F]{3}|#[0-9a-fA-F]{6}|#[0-9a-fA-F]{8})\](?:\s|$|")/', $source, $match))
		{
			return $this->normalizeColorValue($match[1], '#ffffff');
		}

		if (preg_match('/(?:^|\s|")bg-([a-z]+(?:-\d{2,3})?)(?:\/\d+)?(?:\s|$|")/', $source, $match))
		{
			$color = self::TAILWIND_COLORS[$match[1]] ?? null;

			return $color !== null ? $this->normalizeColorValue($color, '#ffffff') : null;
		}

		return null;
	}

	private function isDarkColor(string $color): bool
	{
		$parsed = $this->parseHexColor($color);
		if ($parsed === null)
		{
			return false;
		}

		return $this->relativeLuminance($parsed) < 0.35;
	}

	private function normalizeColorValue(string $value, string $baseColor): ?string
	{
		$color = $this->parseHexColor($value);
		if ($color === null)
		{
			return null;
		}

		if ($color['a'] < 1.0)
		{
			$base = $this->parseHexColor($baseColor) ?? $this->parseHexColor('#ffffff');
			$color = $this->composite($color, $base);
		}

		return $this->rgbToHex($color);
	}

	/**
	 * @return array{r: int, g: int, b: int, a: float}|null
	 */
	private function parseHexColor(string $value): ?array
	{
		$value = trim($value);
		if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value, $match))
		{
			return null;
		}

		$hex = strtolower($match[1]);
		if (strlen($hex) === 3)
		{
			return [
				'r' => hexdec($hex[0] . $hex[0]),
				'g' => hexdec($hex[1] . $hex[1]),
				'b' => hexdec($hex[2] . $hex[2]),
				'a' => 1.0,
			];
		}

		$alpha = 1.0;
		if (strlen($hex) === 8)
		{
			$alpha = round(hexdec(substr($hex, 6, 2)) / 255, 4);
			$hex = substr($hex, 0, 6);
		}

		return [
			'r' => hexdec(substr($hex, 0, 2)),
			'g' => hexdec(substr($hex, 2, 2)),
			'b' => hexdec(substr($hex, 4, 2)),
			'a' => $alpha,
		];
	}

	/**
	 * @param array{r: int, g: int, b: int, a: float} $foreground
	 * @param array{r: int, g: int, b: int, a: float} $background
	 * @return array{r: int, g: int, b: int, a: float}
	 */
	private function composite(array $foreground, array $background): array
	{
		$alpha = max(0.0, min(1.0, $foreground['a']));

		return [
			'r' => (int)round($foreground['r'] * $alpha + $background['r'] * (1.0 - $alpha)),
			'g' => (int)round($foreground['g'] * $alpha + $background['g'] * (1.0 - $alpha)),
			'b' => (int)round($foreground['b'] * $alpha + $background['b'] * (1.0 - $alpha)),
			'a' => 1.0,
		];
	}

	/**
	 * @param array{r: int, g: int, b: int, a: float} $color
	 */
	private function rgbToHex(array $color): string
	{
		return sprintf('#%02x%02x%02x', $color['r'], $color['g'], $color['b']);
	}

	/**
	 * @param array{r: int, g: int, b: int, a: float} $color
	 */
	private function relativeLuminance(array $color): float
	{
		$channels = [
			$color['r'] / 255,
			$color['g'] / 255,
			$color['b'] / 255,
		];
		$linear = array_map(
			static fn(float $channel): float => $channel <= 0.03928
				? $channel / 12.92
				: (($channel + 0.055) / 1.055) ** 2.4,
			$channels,
		);

		return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
	}
}
