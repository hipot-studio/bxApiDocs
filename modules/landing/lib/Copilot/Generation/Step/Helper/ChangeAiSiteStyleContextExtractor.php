<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step\Helper;

final class ChangeAiSiteStyleContextExtractor
{
	private const MAX_BLOCKS = 30;
	private const MAX_GROUP_ITEMS = 10;
	private const FONT_PREFIX = 'g-font-';
	private const SERVICE_FONT_PREFIXES = [
		'g-font-size-',
		'g-font-weight-',
		'g-font-style-',
	];
	private const GROUPS = [
		'fonts',
		'colors',
		'surfaces',
		'layout',
		'cta',
		'typography',
	];

	public function extract(array $htmlBlocks): array
	{
		$counters = $this->createCounters();
		foreach (array_slice($htmlBlocks, 0, self::MAX_BLOCKS) as $html)
		{
			if (!is_string($html) || trim($html) === '')
			{
				continue;
			}

			$this->collectHtmlSignals($html, $counters);
		}

		return $this->buildContext($counters);
	}

	public function extractBlockSnapshot(string $html, string $code = '', string $summary = ''): array
	{
		$counters = $this->createCounters();
		$this->collectHtmlSignals($html, $counters);
		$context = $this->buildTokenContext($counters);
		$imageCount = $this->countImages($html);
		$rootClasses = $this->limitText($this->extractRootClasses($html), 220);

		$snapshot = [
			'rootClasses' => $rootClasses,
			'styleSummary' => $this->buildStyleSummary($context, $html),
			'imageCount' => $imageCount,
			'hasCrm' => $this->hasCrm($html),
			'dominantFonts' => array_slice($context['fonts'] ?? [], 0, 4),
			'dominantColors' => array_slice($context['colors'] ?? [], 0, 6),
			'sectionTypeGuess' => $this->guessSectionType($code, $summary, $html),
			'imagePattern' => $this->buildImagePattern($html, $imageCount),
			'styleTokens' => $this->compactStyleTokens($context),
		];

		return array_filter(
			$snapshot,
			static fn(mixed $value): bool => $value !== '' && $value !== [] && $value !== null,
		);
	}

	private function createCounters(): array
	{
		return array_fill_keys(self::GROUPS, []);
	}

	private function collectHtmlSignals(string $html, array &$counters): void
	{
		$this->collectClassTokens($html, $counters);
		$this->collectCtaTokens($html, $counters['cta']);
	}

	private function collectClassTokens(string $html, array &$counters): void
	{
		if (!preg_match_all('/\bclass\s*=\s*(["\'])(.*?)\1/isu', $html, $matches))
		{
			return;
		}

		foreach ($matches[2] as $classValue)
		{
			foreach ($this->splitClassTokens((string)$classValue) as $token)
			{
				if ($this->isFontToken($token))
				{
					$this->increment($counters['fonts'], $token);
				}
				if ($this->isColorToken($token))
				{
					$this->increment($counters['colors'], $token);
				}
				if ($this->isSurfaceToken($token))
				{
					$this->increment($counters['surfaces'], $token);
				}
				if ($this->isLayoutToken($token))
				{
					$this->increment($counters['layout'], $token);
				}
				if ($this->isTypographyToken($token))
				{
					$this->increment($counters['typography'], $token);
				}
			}
		}
	}

	private function collectCtaTokens(string $html, array &$counter): void
	{
		if (!preg_match_all('/<a\b[^>]*\bclass\s*=\s*(["\'])(.*?)\1[^>]*>/isu', $html, $matches))
		{
			return;
		}

		foreach ($matches[2] as $classValue)
		{
			foreach ($this->splitClassTokens((string)$classValue) as $token)
			{
				if ($this->isCtaToken($token))
				{
					$this->increment($counter, $token);
				}
			}
		}
	}

	private function splitClassTokens(string $classValue): array
	{
		$tokens = preg_split('/\s+/u', trim($classValue)) ?: [];
		$normalized = [];
		foreach ($tokens as $token)
		{
			$token = trim((string)$token);
			if ($token !== '')
			{
				$normalized[] = $token;
			}
		}

		return $normalized;
	}

	private function isFontToken(string $token): bool
	{
		$baseToken = $this->resolveBaseToken($token);
		if (!str_starts_with($baseToken, self::FONT_PREFIX))
		{
			return false;
		}

		foreach (self::SERVICE_FONT_PREFIXES as $prefix)
		{
			if (str_starts_with($baseToken, $prefix))
			{
				return false;
			}
		}

		return true;
	}

	private function isColorToken(string $token): bool
	{
		$baseToken = $this->resolveBaseToken($token);
		if (str_starts_with($baseToken, 'text-') && $this->isTextScaleToken($baseToken))
		{
			return false;
		}

		return $this->startsWithAny($baseToken, ['bg-', 'text-', 'border-', 'ring-']);
	}

	private function isSurfaceToken(string $token): bool
	{
		$baseToken = $this->resolveBaseToken($token);

		return $baseToken === 'border'
			|| $this->startsWithAny($baseToken, ['rounded-', 'shadow-', 'ring-', 'border-'])
		;
	}

	private function isLayoutToken(string $token): bool
	{
		$baseToken = $this->resolveBaseToken($token);

		return $baseToken === 'mx-auto'
			|| $this->startsWithAny($baseToken, ['max-w-', 'px-', 'py-', 'grid-', 'gap-'])
		;
	}

	private function isCtaToken(string $token): bool
	{
		$baseToken = $this->resolveBaseToken($token);

		return $baseToken === 'inline-flex'
			|| $this->startsWithAny($baseToken, ['rounded-', 'px-', 'py-', 'bg-', 'text-', 'ring-', 'shadow-'])
		;
	}

	private function isTypographyToken(string $token): bool
	{
		$baseToken = $this->resolveBaseToken($token);

		return $this->startsWithAny($baseToken, ['leading-', 'tracking-'])
			|| (str_starts_with($baseToken, 'text-') && $this->isTextScaleToken($baseToken))
		;
	}

	private function isTextScaleToken(string $baseToken): bool
	{
		if (preg_match('/^text-(xs|sm|base|lg|xl|[2-9]xl)$/u', $baseToken) === 1)
		{
			return true;
		}

		return preg_match('/^text-\[(?:\d|clamp\(|var\(|calc\(|[0-9.]+(?:px|rem|em|%))/u', $baseToken) === 1;
	}

	private function resolveBaseToken(string $token): string
	{
		$pos = strrpos($token, ':');
		if ($pos === false)
		{
			return $token;
		}

		$bracketPos = strpos($token, '[');
		if ($bracketPos !== false && $pos > $bracketPos)
		{
			return $token;
		}

		return substr($token, $pos + 1);
	}

	private function startsWithAny(string $token, array $prefixes): bool
	{
		foreach ($prefixes as $prefix)
		{
			if (str_starts_with($token, $prefix))
			{
				return true;
			}
		}

		return false;
	}

	private function increment(array &$counter, string $token): void
	{
		$counter[$token] = ($counter[$token] ?? 0) + 1;
	}

	private function buildContext(array $counters): array
	{
		$context = $this->buildTokenContext($counters);
		$designProfile = $this->buildDesignProfile($context);
		if ($designProfile !== [])
		{
			$context['design_profile'] = $designProfile;
		}

		return $context;
	}

	private function buildTokenContext(array $counters): array
	{
		$context = [];
		foreach (self::GROUPS as $group)
		{
			$items = $this->topTokens($counters[$group] ?? []);
			if ($items !== [])
			{
				$context[$group] = $items;
			}
		}

		return $context;
	}

	private function buildDesignProfile(array $context): array
	{
		if ($context === [])
		{
			return [];
		}

		$profile = [
			'fonts' => array_slice($context['fonts'] ?? [], 0, 5),
			'palette' => [
				'backgrounds' => $this->filterTokensByBasePrefix($context['colors'] ?? [], ['bg-'], 5),
				'text' => $this->filterTokensByBasePrefix($context['colors'] ?? [], ['text-'], 5),
				'accent' => $this->filterTokensByBasePrefix($context['cta'] ?? [], ['bg-', 'ring-', 'border-'], 5),
			],
			'surfaces' => [
				'radius' => $this->filterTokensByBasePrefix($context['surfaces'] ?? [], ['rounded-'], 5),
				'shadow' => $this->filterTokensByBasePrefix($context['surfaces'] ?? [], ['shadow-'], 5),
				'border' => $this->filterTokensByBasePrefix($context['surfaces'] ?? [], ['border', 'border-', 'ring-'], 5),
			],
			'layout' => [
				'containers' => $this->filterTokensByBasePrefix($context['layout'] ?? [], ['mx-auto', 'max-w-', 'px-'], 6),
				'sectionSpacing' => $this->filterTokensByBasePrefix($context['layout'] ?? [], ['py-'], 4),
				'grid' => $this->filterTokensByBasePrefix($context['layout'] ?? [], ['grid-', 'gap-'], 6),
			],
			'typography' => [
				'headingScale' => $this->filterTokensByBasePrefix($context['typography'] ?? [], ['text-3xl', 'text-4xl', 'text-5xl', 'text-6xl', 'text-7xl', 'tracking-'], 6),
				'bodyScale' => $this->filterTokensByBasePrefix($context['typography'] ?? [], ['text-sm', 'text-base', 'text-lg', 'leading-'], 6),
			],
			'cta' => array_slice($context['cta'] ?? [], 0, 8),
			'density' => $this->guessDensity($context['layout'] ?? []),
			'tone' => $this->guessTone($context['colors'] ?? []),
		];

		return $this->removeEmptyProfileValues($profile);
	}

	private function compactStyleTokens(array $context): array
	{
		$tokens = [];
		foreach (self::GROUPS as $group)
		{
			$items = array_slice($context[$group] ?? [], 0, 6);
			if ($items !== [])
			{
				$tokens[$group] = $items;
			}
		}

		return $tokens;
	}

	private function extractRootClasses(string $html): string
	{
		if (preg_match('/<section\b[^>]*\bclass\s*=\s*(["\'])(.*?)\1/isu', $html, $matches))
		{
			return trim((string)($matches[2] ?? ''));
		}

		return '';
	}

	private function buildStyleSummary(array $context, string $html): string
	{
		$parts = [];
		$tone = $this->guessTone($context['colors'] ?? []);
		if ($tone !== '')
		{
			$parts[] = $tone;
		}
		$density = $this->guessDensity($context['layout'] ?? []);
		if ($density !== '')
		{
			$parts[] = $density . ' density';
		}
		if (!empty($context['fonts'][0]))
		{
			$parts[] = 'font ' . $context['fonts'][0];
		}
		if (!empty($context['surfaces'][0]))
		{
			$parts[] = 'surface ' . $context['surfaces'][0];
		}
		if ($this->hasCrm($html))
		{
			$parts[] = 'CRM';
		}

		return $this->limitText(implode('; ', $parts), 180);
	}

	private function countImages(string $html): int
	{
		return preg_match_all('/<img\b/isu', $html) ?: 0;
	}

	private function hasCrm(string $html): bool
	{
		$lower = mb_strtolower($html);

		return str_contains($lower, '#crm_form#')
			|| str_contains($lower, 'bitrix24forms')
			|| str_contains($lower, 'bitrix24form')
			|| str_contains($lower, 'data-b24form')
		;
	}

	private function guessSectionType(string $code, string $summary, string $html): string
	{
		$text = mb_strtolower($code . ' ' . $summary . ' ' . strip_tags($html));
		foreach ([
			'menu' => ['menu', 'nav', 'navbar', 'меню', 'навигац'],
			'hero' => ['hero', 'cover', 'главн', 'первый экран'],
			'pricing' => ['price', 'pricing', 'тариф', 'цена'],
			'contact' => ['contact', 'контакт', 'crm', 'form', 'заявк'],
			'testimonials' => ['testimonial', 'review', 'отзыв'],
			'features' => ['feature', 'benefit', 'преимуществ', 'возможност'],
			'gallery' => ['gallery', 'portfolio', 'галере', 'портфолио'],
			'faq' => ['faq', 'question', 'вопрос'],
			'footer' => ['footer', 'подвал'],
		] as $type => $needles)
		{
			foreach ($needles as $needle)
			{
				if (str_contains($text, $needle))
				{
					return $type;
				}
			}
		}

		return 'content';
	}

	private function buildImagePattern(string $html, int $imageCount): string
	{
		if ($imageCount <= 0)
		{
			return '';
		}

		if (!preg_match_all('/\bdata-ai-aspect\s*=\s*(["\'])(.*?)\1/isu', $html, $matches))
		{
			return $imageCount === 1 ? '1 image' : $imageCount . ' images';
		}

		$aspects = array_values(array_unique(array_filter(array_map(
			static fn(string $value): string => trim($value),
			$matches[2],
		))));
		if ($aspects === [])
		{
			return $imageCount === 1 ? '1 image' : $imageCount . ' images';
		}

		return $imageCount . ' image(s), aspect ' . implode('/', array_slice($aspects, 0, 3));
	}

	private function filterTokensByBasePrefix(array $tokens, array $prefixes, int $limit): array
	{
		$result = [];
		foreach ($tokens as $token)
		{
			$baseToken = $this->resolveBaseToken((string)$token);
			foreach ($prefixes as $prefix)
			{
				if ($baseToken === $prefix || str_starts_with($baseToken, $prefix))
				{
					$result[] = (string)$token;
					break;
				}
			}
			if (count($result) >= $limit)
			{
				break;
			}
		}

		return $result;
	}

	private function guessDensity(array $layoutTokens): string
	{
		if ($layoutTokens === [])
		{
			return '';
		}

		$joined = ' ' . implode(' ', array_map([$this, 'resolveBaseToken'], $layoutTokens)) . ' ';
		if (preg_match('/\bpy-(2|3|4|5|6|8)\b/', $joined))
		{
			return 'compact';
		}
		if (preg_match('/\b(py-20|py-24|py-28|py-32)\b/', $joined))
		{
			return 'spacious';
		}
		if (preg_match('/\b(gap-1|gap-2|gap-3)\b/', $joined))
		{
			return 'dense';
		}

		return 'balanced';
	}

	private function guessTone(array $colorTokens): string
	{
		if ($colorTokens === [])
		{
			return '';
		}

		$joined = mb_strtolower(' ' . implode(' ', array_map([$this, 'resolveBaseToken'], $colorTokens)) . ' ');
		if (preg_match('/bg-\[#(0|1|2)[0-9a-f]{5}\]|bg-(slate|zinc|neutral|stone|gray)-(800|900|950)|text-white/', $joined))
		{
			return 'dark editorial';
		}
		if (preg_match('/bg-(amber|orange|yellow|rose|stone)-|text-(amber|orange|rose)-/', $joined))
		{
			return 'warm hospitality';
		}
		if (preg_match('/bg-(white|slate-50|gray-50|zinc-50)|text-slate-950|text-gray-950/', $joined))
		{
			return 'light SaaS';
		}

		return 'mixed contemporary';
	}

	private function removeEmptyProfileValues(array $profile): array
	{
		$result = [];
		foreach ($profile as $key => $value)
		{
			if (is_array($value))
			{
				$value = $this->removeEmptyProfileValues($value);
			}
			if ($value !== [] && $value !== '')
			{
				$result[$key] = $value;
			}
		}

		return $result;
	}

	private function limitText(string $text, int $limit): string
	{
		$text = trim(preg_replace('/\s+/u', ' ', $text) ?: $text);
		if (mb_strlen($text) <= $limit)
		{
			return $text;
		}

		return rtrim(mb_substr($text, 0, $limit - 1)) . '…';
	}

	private function topTokens(array $counter): array
	{
		if ($counter === [])
		{
			return [];
		}

		uksort($counter, static function (string $left, string $right) use ($counter): int {
			$countCompare = $counter[$right] <=> $counter[$left];
			if ($countCompare !== 0)
			{
				return $countCompare;
			}

			return $left <=> $right;
		});

		return array_slice(array_keys($counter), 0, self::MAX_GROUP_ITEMS);
	}
}
