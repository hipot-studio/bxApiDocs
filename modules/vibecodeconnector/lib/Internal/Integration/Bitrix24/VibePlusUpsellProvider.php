<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24;

use Bitrix\Bitrix24\Public\Service\VibePlus\UpsellProjectionProvider;
use Bitrix\Bitrix24\Public\ValueObject\VibePlusUpsellProjection;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Throwable;

class VibePlusUpsellProvider
{
	public function getForUser(int $userId): ?VibePlusUpsellProjection
	{
		try
		{
			if ($userId <= 0)
			{
				return null;
			}

			return $this->resolveProvider()?->getProjectionForUser($userId);
		}
		catch (Throwable)
		{
			return null;
		}
	}

	protected function resolveProvider(): ?UpsellProjectionProvider
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return null;
		}

		$serviceLocator = ServiceLocator::getInstance();
		if (!$serviceLocator->has(UpsellProjectionProvider::class))
		{
			return null;
		}

		$provider = $serviceLocator->get(UpsellProjectionProvider::class);

		return $provider instanceof UpsellProjectionProvider ? $provider : null;
	}
}
