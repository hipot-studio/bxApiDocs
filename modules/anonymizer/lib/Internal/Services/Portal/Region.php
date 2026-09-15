<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Internal\Services\Portal;

use Bitrix\Main\Application;

/**
 * Portal license region (for proxy server list keys, CIS checks).
 *
 */
class Region
{
	private string $region;

	/**
	 * License region as returned by the portal (no normalization).
	 */
	public function getRegion(): string
	{
		if (!isset($this->region))
		{
			$this->region = Application::getInstance()->getLicense()->getRegion() ?? 'xx';
		}

		return $this->region;
	}

	/**
	 * Default bucket for config lists: CIS → ru, otherwise en.
	 */
	public function getDefaultRegion(): string
	{
		return $this->isCis() ? 'ru' : 'en';
	}

	public function isCis(): bool
	{
		return in_array($this->getRegion(), ['ru', 'by', 'kz', 'uz'], true);
	}

	/**
	 * Resolves a value from a keyed map: first {@see getRegion()}, then {@see getDefaultRegion()}.
	 *
	 * @param array<string, mixed> $list
	 */
	public function resolveListByRegion(array $list): mixed
	{
		$region = $this->getRegion();
		if (array_key_exists($region, $list))
		{
			return $list[$region];
		}

		$defaultRegion = $this->getDefaultRegion();

		return $list[$defaultRegion] ?? null;
	}
}
