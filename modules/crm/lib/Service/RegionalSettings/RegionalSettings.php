<?php

namespace Bitrix\Crm\Service\RegionalSettings;

use Bitrix\Crm\Service\RegionalSettings\Maps\BaseRegionalSettingMap;
use Bitrix\Main\Application;
use Bitrix\Main\Context;

final class RegionalSettings
{
	public const DIMENSION_REGION = 'region';
	public const DIMENSION_CULTURE = 'culture';

	public const ALLOWED_DIMENSIONS = [self::DIMENSION_REGION, self::DIMENSION_CULTURE];
	public const DIMENSION_SEPARATOR = '|';
	public const VALUE_SEPARATOR = '=';
	public const DEFAULT_KEY = '*';

	private ?string $defaultRegion = null;
	private ?string $defaultCulture = null;

	public function getSetting(
		BaseRegionalSettingMap $map,
		?string $regionId = null,
		?string $cultureId = null,
	): mixed
	{
		return $this->resolveValue($map, $regionId, $cultureId);
	}

	public function getSettings(BaseRegionalSettingMap $map): array
	{
		return $map->getMap();
	}

	private function resolveValue(BaseRegionalSettingMap $settingsMap, ?string $regionId, ?string $cultureId): mixed
	{
		$map = $settingsMap->getMap();

		$region = $this->normalizeId($regionId) ?? $this->resolveRegion();
		$culture = $this->normalizeId($cultureId) ?? $this->resolveCulture();
		$context = [
			self::DIMENSION_REGION => $region,
			self::DIMENSION_CULTURE => $culture,
		];

		foreach ($settingsMap->getFallbackOrder() as $dimensions)
		{
			$key = $this->buildKey($dimensions, $context);
			if ($key === null)
			{
				continue;
			}

			if (array_key_exists($key, $map))
			{
				return $map[$key];
			}
		}

		return null;
	}

	private function normalizeId(?string $value): ?string
	{
		if ($value === null)
		{
			return null;
		}

		$value = trim($value);
		if ($value === '')
		{
			return null;
		}

		return mb_strtolower($value);
	}

	private function resolveRegion(): ?string
	{
		if ($this->defaultRegion === null)
		{
			$this->defaultRegion = $this->normalizeId(
				Application::getInstance()->getLicense()->getRegion(),
			);
		}

		return $this->defaultRegion;
	}

	private function resolveCulture(): ?string
	{
		if ($this->defaultCulture === null)
		{
			$this->defaultCulture = $this->normalizeId(
				Context::getCurrent()?->getCulture()?->getCode() ?? LANGUAGE_ID,
			);
		}

		return $this->defaultCulture;
	}

	/**
	 * @param string[] $dimensions
	 * @param array<string, string|null> $context
	 */
	private function buildKey(array $dimensions, array $context): ?string
	{
		if (empty($dimensions) || $dimensions === ['*'])
		{
			return self::DEFAULT_KEY;
		}

		foreach ($dimensions as $dimension)
		{
			if (!in_array($dimension, self::ALLOWED_DIMENSIONS, true))
			{
				return null;
			}
		}

		$parts = [];
		foreach (self::ALLOWED_DIMENSIONS as $dimension)
		{
			if (!in_array($dimension, $dimensions, true))
			{
				continue;
			}

			if (!array_key_exists($dimension, $context) || $context[$dimension] === null)
			{
				return null;
			}

			$parts[] = $dimension . self::VALUE_SEPARATOR . $context[$dimension];
		}

		if (empty($parts))
		{
			return null;
		}

		return implode(self::DIMENSION_SEPARATOR, $parts);
	}
}
