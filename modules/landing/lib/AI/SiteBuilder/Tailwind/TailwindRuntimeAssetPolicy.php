<?php
namespace Bitrix\Landing\AI\SiteBuilder\Tailwind;

final class TailwindRuntimeAssetPolicy
{
	private const RUNTIME_HELPER_PATHS = [
		'assets/js/helpers/tailwind-runtime-save.js',
		'assets/js/helpers/tailwind.js',
	];
	private const ASSET_SECTIONS = [
		'content_ext',
		'js',
		'css',
		'assetStrings',
	];

	private TailwindRuntimeEligibilityService $eligibilityService;

	public function __construct(?TailwindRuntimeEligibilityService $eligibilityService = null)
	{
		$this->eligibilityService = $eligibilityService ?? new TailwindRuntimeEligibilityService();
	}

	public static function getRuntimeHelperPaths(string $basePath): array
	{
		$basePath = rtrim($basePath, '/');
		if ($basePath === '')
		{
			return [];
		}

		return array_map(
			static fn(string $path): string => $basePath . '/' . $path,
			self::RUNTIME_HELPER_PATHS,
		);
	}

	public function filterLandingAssets(int $landingId, array $assetResponse): array
	{
		if ($this->eligibilityService->isLandingSupported($landingId))
		{
			return $assetResponse;
		}

		foreach (self::ASSET_SECTIONS as $section)
		{
			if (array_key_exists($section, $assetResponse))
			{
				$assetResponse[$section] = $this->filterValue($assetResponse[$section]);
			}
		}

		return $assetResponse;
	}

	private function filterValue(mixed $value): mixed
	{
		if (is_string($value))
		{
			return $this->filterString($value);
		}

		if (!is_array($value))
		{
			return $value;
		}

		$isList = $this->isList($value);
		$result = [];
		foreach ($value as $key => $item)
		{
			$filtered = $this->filterValue($item);
			if ($this->shouldDropValue($item, $filtered))
			{
				continue;
			}

			$result[$key] = $filtered;
		}

		return $isList ? array_values($result) : $result;
	}

	private function filterString(string $value): string
	{
		$filtered = $value;

		foreach (self::RUNTIME_HELPER_PATHS as $path)
		{
			$pattern = preg_quote($path, '#');
			$filtered = preg_replace(
				'#<script\b[^>]*\bsrc=["\'][^"\']*' . $pattern . '[^"\']*["\'][^>]*>\s*</script>#is',
				'',
				$filtered,
			) ?? $filtered;
			$filtered = preg_replace(
				'#<link\b[^>]*\bhref=["\'][^"\']*' . $pattern . '[^"\']*["\'][^>]*>#is',
				'',
				$filtered,
			) ?? $filtered;
		}

		if ($this->containsRuntimeHelperPath($filtered) && !str_contains($filtered, '<'))
		{
			return '';
		}

		return $filtered;
	}

	private function shouldDropValue(mixed $original, mixed $filtered): bool
	{
		return (
			is_string($original)
			&& is_string($filtered)
			&& $filtered === ''
			&& $this->containsRuntimeHelperPath($original)
		);
	}

	private function containsRuntimeHelperPath(string $value): bool
	{
		foreach (self::RUNTIME_HELPER_PATHS as $path)
		{
			if (str_contains($value, $path))
			{
				return true;
			}
		}

		return false;
	}

	private function isList(array $value): bool
	{
		$index = 0;
		foreach (array_keys($value) as $key)
		{
			if ($key !== $index)
			{
				return false;
			}
			$index++;
		}

		return true;
	}
}
