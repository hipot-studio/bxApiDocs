<?php

namespace Bitrix\Crm\Activity;

use Bitrix\Crm\Activity\Provider\Base;
use Bitrix\Crm\Activity\Provider\ToDo\ToDo;
use Bitrix\Crm\Service\Communication\Channel\Provider\Dummy;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\ProviderRegistry;
use Bitrix\Main\DI\ServiceLocator;

final class ProviderFactory
{
	/**
	 * An instance of the legacy class of a provider, for the callers that need an object rather than a class
	 * name. Which providers offer one is answered by the registry now, and the class stays the legacy one even
	 * for a provider rewritten natively: what these callers get does not change.
	 */
	public static function getProviderInstance(string $id): ?Base
	{
		$registry = ServiceLocator::getInstance()->get(ProviderRegistry::SERVICE_CODE);
		$instance = $registry->getDescriptor($id, true)?->createLegacyInstance();

		return $instance instanceof Base ? $instance : null;
	}

	public static function getProviderInstanceByChannelCode(string $code): ?Base
	{
		if ($code === Dummy::getCode())
		{
			return new ToDo();
		}

		return null;
	}
}
