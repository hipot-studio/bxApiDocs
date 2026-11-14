<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Activity;

use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\PermissionCapabilityInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\LegacyProviderAdapter;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\ProviderRegistryInterface;

/**
 * The providers that may have an opinion about reading an activity, as the registry of the hit composes them: a
 * reader of a list asks their verdict over the batch, and an activity of any other provider is decided by the core
 * alone.
 *
 * NOT WRITTEN DOWN. The list is read from the registry every time, so a provider another module registers is asked,
 * and one switched off is not ({@see ProviderRegistryInterface::getByCapability()} leaves it out). A legacy provider
 * is wrapped with the permission capability whatever it overrides, so it is taken only when it has a read rule of
 * its own ({@see LegacyProviderAdapter::hasOwnReadPermissionRule()}); any other provider with the capability is taken
 * always, its opinion being known only by asking.
 *
 * @internal
 */
final class ReadOpinionProviders
{
	public function __construct(private readonly ProviderRegistryInterface $registry)
	{
	}

	/**
	 * @return string[] the identifiers of the providers, the values of the PROVIDER_ID column of their activities
	 */
	public function getProviderIds(): array
	{
		$providerIds = [];
		foreach ($this->registry->getByCapability(PermissionCapabilityInterface::class) as $providerId => $provider)
		{
			if (!$provider instanceof LegacyProviderAdapter || $provider->hasOwnReadPermissionRule())
			{
				$providerIds[] = (string)$providerId;
			}
		}

		return $providerIds;
	}
}
