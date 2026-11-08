<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\HumanResources;

use Bitrix\Main\Application;
use Bitrix\Main\Data\ManagedCache;
use Bitrix\Main\Security\Random;
use Bitrix\Vibecodeconnector\Internal\Config\ModuleOptions;

class DepartmentCodesCache
{
	private const TTL = 300;
	private const TABLE_ID = 'vibecodeconnector_department_codes_v2';
	private const CACHE_ID = 'vibecodeconnector_department_codes_v2';
	private const GENERATION_OPTION = 'department_codes_cache_generation';

	private readonly ManagedCache $managedCache;

	public function __construct(
		?ManagedCache $managedCache = null,
		private readonly ModuleOptions $moduleOptions = new ModuleOptions(),
	)
	{
		$this->managedCache = $managedCache ?? Application::getInstance()->getManagedCache();
	}

	public function remember(callable $compute): array
	{
		$cacheId = $this->currentCacheId();
		if ($this->managedCache->read(self::TTL, $cacheId, self::TABLE_ID))
		{
			$cached = $this->managedCache->get($cacheId);
			if ($this->isCandidateList($cached))
			{
				return $cached;
			}
		}

		$value = $compute();
		$this->managedCache->set($cacheId, $value);

		return $value;
	}

	private function isCandidateList(mixed $value): bool
	{
		if (!is_array($value) || !array_is_list($value))
		{
			return false;
		}

		foreach ($value as $code)
		{
			if (!is_string($code) || preg_match('/^DR?[1-9][0-9]*$/D', $code) !== 1)
			{
				return false;
			}
		}

		return true;
	}

	public function forget(): void
	{
		$staleCacheId = $this->currentCacheId();
		$this->moduleOptions->set(self::GENERATION_OPTION, Random::getString(16));
		$this->managedCache->clean($staleCacheId, self::TABLE_ID);
	}

	private function currentCacheId(): string
	{
		$generation = $this->moduleOptions->get(self::GENERATION_OPTION);

		return $generation === '' ? self::CACHE_ID : self::CACHE_ID . '_' . $generation;
	}
}
