<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step\Helper;

use Bitrix\Landing\AI\SiteBuilder\Font\AiFontPolicy;

final class ChangeAiSiteHtmlQualityDiagnostics
{
	private const SERVICE_FONT_PREFIXES = [
		'g-font-size-',
		'g-font-weight-',
		'g-font-style-',
	];

	public function inspect(string $html, array $styleContext = [], bool $isAddBlock = false): array
	{
		$diagnostics = [];
		if (!$this->rootHasFontClass($html))
		{
			$diagnostics[] = 'missing_root_g_font';
		}
		if ($isAddBlock && !$this->hasContainerToken($html))
		{
			$diagnostics[] = 'add_block_missing_container';
		}
		if ($isAddBlock && $this->rootHasContainerToken($html))
		{
			$diagnostics[] = 'add_block_root_has_container';
		}
		if ($this->hasImageWithoutSizeConstraints($html))
		{
			$diagnostics[] = 'image_missing_size_constraints';
		}
		if ($this->hasForbiddenFontFamilyClass($html))
		{
			$diagnostics[] = 'forbidden_font_family_class';
		}
		if (preg_match('/\sstyle\s*=/iu', $html))
		{
			$diagnostics[] = 'inline_style_attribute';
		}
		if ($this->hasUnknownFontClass($html))
		{
			$diagnostics[] = 'unknown_g_font_class';
		}
		if ($isAddBlock && $styleContext !== [] && !$this->reusesAnyStyleToken($html, $styleContext))
		{
			$diagnostics[] = 'add_block_style_context_not_reused';
		}
		if ($isAddBlock && !$this->reusesPaletteToken($html, $styleContext))
		{
			$diagnostics[] = 'add_block_palette_detached';
		}

		return array_values(array_unique($diagnostics));
	}

	private function rootHasFontClass(string $html): bool
	{
		$tokens = preg_split('/\s+/u', trim($this->extractRootClasses($html))) ?: [];
		foreach ($tokens as $token)
		{
			$token = mb_strtolower($this->resolveBaseToken((string)$token));
			if (str_starts_with($token, 'g-font-') && !$this->isServiceFontClass($token))
			{
				return true;
			}
		}

		return false;
	}

	private function extractRootClasses(string $html): string
	{
		if (preg_match('/<section\b[^>]*\bclass\s*=\s*(["\'])(.*?)\1/isu', $html, $matches))
		{
			return (string)($matches[2] ?? '');
		}

		return '';
	}

	private function hasContainerToken(string $html): bool
	{
		if (!preg_match_all('/\bclass\s*=\s*(["\'])(.*?)\1/isu', $html, $matches))
		{
			return false;
		}

		foreach ($matches[2] as $classValue)
		{
			if ($this->classValueHasContainerToken((string)$classValue))
			{
				return true;
			}
		}

		return false;
	}

	private function rootHasContainerToken(string $html): bool
	{
		return $this->classValueHasContainerToken($this->extractRootClasses($html));
	}

	private function classValueHasContainerToken(string $classValue): bool
	{
		$tokens = preg_split('/\s+/u', trim($classValue)) ?: [];
		foreach ($tokens as $token)
		{
			if ($this->isContainerToken($this->resolveBaseToken((string)$token)))
			{
				return true;
			}
		}

		return false;
	}

	private function isContainerToken(string $token): bool
	{
		if ($token === 'mx-auto' || $token === 'container')
		{
			return true;
		}

		return str_starts_with($token, 'max-w-') && !in_array($token, ['max-w-full', 'max-w-none'], true);
	}

	private function hasImageWithoutSizeConstraints(string $html): bool
	{
		if (!preg_match_all('/<img\b[^>]*>/isu', $html, $matches))
		{
			return false;
		}

		foreach ($matches[0] as $tag)
		{
			$class = $this->extractClassAttribute((string)$tag);
			if (!$this->hasWidthConstraint($class) || !$this->hasHeightConstraint($class))
			{
				return true;
			}
		}

		return false;
	}

	private function extractClassAttribute(string $tag): string
	{
		if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/isu', $tag, $matches))
		{
			return (string)($matches[2] ?? '');
		}

		return '';
	}

	private function hasWidthConstraint(string $classes): bool
	{
		return preg_match('/(^|\s)(w-|max-w-)/u', $this->normalizeVariantClasses($classes)) === 1;
	}

	private function hasHeightConstraint(string $classes): bool
	{
		$normalized = $this->normalizeVariantClasses($classes);
		if (preg_match('/(^|\s)max-h-/u', $normalized) === 1)
		{
			return true;
		}
		if (preg_match('/(^|\s)h-auto(\s|$)/u', $normalized) === 1)
		{
			return false;
		}

		return preg_match('/(^|\s)h-/u', $normalized) === 1;
	}

	private function normalizeVariantClasses(string $classes): string
	{
		$tokens = preg_split('/\s+/u', trim($classes)) ?: [];
		$normalized = [];
		foreach ($tokens as $token)
		{
			$normalized[] = $this->resolveBaseToken((string)$token);
		}

		return implode(' ', $normalized);
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

	private function hasForbiddenFontFamilyClass(string $html): bool
	{
		return preg_match('/(^|[\s"\'])(font-sans|font-serif|font-mono|\[[^\]]*font-family[^\]]*\])($|[\s"\'])/iu', $html) === 1;
	}

	private function hasUnknownFontClass(string $html): bool
	{
		if (!preg_match_all('/(^|[\s"\'])(g-font-[a-z0-9-]+)($|[\s"\'])/iu', $html, $matches))
		{
			return false;
		}

		foreach ($matches[2] as $fontClass)
		{
			$fontClass = mb_strtolower(trim((string)$fontClass));
			if ($this->isServiceFontClass($fontClass))
			{
				continue;
			}
			if (!AiFontPolicy::isAllowedFontClass($fontClass))
			{
				return true;
			}
		}

		return false;
	}

	private function isServiceFontClass(string $fontClass): bool
	{
		foreach (self::SERVICE_FONT_PREFIXES as $prefix)
		{
			if (str_starts_with($fontClass, $prefix))
			{
				return true;
			}
		}

		return false;
	}

	private function reusesAnyStyleToken(string $html, array $styleContext): bool
	{
		foreach ($this->flattenStyleTokens($styleContext) as $token)
		{
			if ($token !== '' && str_contains($html, $token))
			{
				return true;
			}
		}

		return false;
	}

	private function reusesPaletteToken(string $html, array $styleContext): bool
	{
		$colors = is_array($styleContext['colors'] ?? null) ? $styleContext['colors'] : [];
		$palette = is_array($styleContext['design_profile']['palette'] ?? null)
			? $styleContext['design_profile']['palette']
			: []
		;
		foreach ($palette as $items)
		{
			if (is_array($items))
			{
				$colors = array_merge($colors, $items);
			}
		}
		if ($colors === [])
		{
			return true;
		}

		foreach ($colors as $token)
		{
			$token = trim((string)$token);
			if ($token !== '' && str_contains($html, $token))
			{
				return true;
			}
		}

		return false;
	}

	private function flattenStyleTokens(array $value): array
	{
		$result = [];
		array_walk_recursive($value, static function (mixed $item) use (&$result): void {
			if (is_string($item))
			{
				$result[] = trim($item);
			}
		});

		return array_values(array_unique(array_filter($result)));
	}
}
