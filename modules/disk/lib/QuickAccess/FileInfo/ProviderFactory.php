<?php

declare(strict_types=1);

namespace Bitrix\Disk\QuickAccess\FileInfo;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\BaseObject;

class ProviderFactory
{
	/**
	 * FileInfoProviderInterface[] - registered providers for file info
	 */
	private array $registered = [];

	/**
	 * @param string $className
	 * @return void
	 */
	public function register(string $className): void
	{
		if (!is_subclass_of($className, ProviderInterface::class))
		{
			return;
		}

		if (!in_array($className, $this->registered, true))
		{
			$this->registered[] = $className;
		}
	}

	/**
	 * @param AttachedObject|BaseObject|int $file
	 * @return ProviderInterface|null
	 */
	public function createProvider(mixed $file): ?ProviderInterface
	{
		foreach ($this->registered as $className)
		{
			$provider = $className::create($file);

			if ($provider !== null)
			{
				return $provider;
			}
		}

		return null;
	}
}