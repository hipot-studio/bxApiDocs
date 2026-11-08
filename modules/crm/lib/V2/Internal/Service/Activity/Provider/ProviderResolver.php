<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Provider;

use Bitrix\Crm\Activity\Provider;
use Bitrix\Main\DI\ServiceLocator;
use CCrmActivityType;

/**
 * Finds the provider that owns an activity.
 *
 * THE ORDER IS THE ORDER OF LEGACY, and it has to be: the stored data is what it is. First the value of the
 * PROVIDER_ID column, which is the answer for every activity written by a provider. Then, only if that names
 * nobody, the legacy type of the activity - a meeting, a call, a task, an e-mail - because activities written
 * before providers existed carry a type and no provider identifier at all, and they still have to behave like
 * meetings and calls.
 *
 * THE FALLBACK BY TYPE IGNORES AVAILABILITY, as the hardcoded switch of legacy did. It maps onto four
 * providers that belong to the registry unconditionally, so the two readings of the registry cannot differ for
 * it - which is the whole reason the fallback may be expressed as an ordinary lookup here.
 *
 * NOT FINDING A PROVIDER IS AN ANSWER. An activity whose provider is unknown, or whose provider does not apply
 * to this portal, has none - and every caller has its own way of dealing with that: some refuse the operation,
 * some skip a step of it, some fall back on a rule of the core. None of them may be turned into a different
 * one here.
 *
 * @internal
 */
final class ProviderResolver
{
	private readonly ProviderRegistryInterface $registry;

	public function __construct(?ProviderRegistryInterface $registry = null)
	{
		$this->registry = $registry ?? ServiceLocator::getInstance()->get(ProviderRegistry::SERVICE_CODE);
	}

	/**
	 * @param string|null $providerId The value of the PROVIDER_ID column of the activity.
	 * @param int|null $typeId The value of its TYPE_ID column, needed only for the activities that carry no
	 *        provider identifier.
	 * @param bool $includeDisabled Whether a provider that does not apply to this portal still answers for its
	 *        activities. The callers that read stored rows rather than act on them need it that way.
	 */
	public function resolve(
		?string $providerId,
		?int $typeId = null,
		bool $includeDisabled = false
	): ?ActivityProviderInterface
	{
		if ($providerId !== null && $providerId !== '')
		{
			$provider = $this->registry->get($providerId, $includeDisabled);
			if ($provider !== null)
			{
				return $provider;
			}
		}

		$providerIdOfType = $this->providerIdOfLegacyType($typeId);

		return $providerIdOfType === null
			? null
			: $this->registry->get($providerIdOfType, $includeDisabled)
		;
	}

	private function providerIdOfLegacyType(?int $typeId): ?string
	{
		return match ($typeId)
		{
			CCrmActivityType::Meeting => Provider\Meeting::getId(),
			CCrmActivityType::Call => Provider\Call::getId(),
			CCrmActivityType::Task => Provider\Task::getId(),
			CCrmActivityType::Email => Provider\Email::getId(),
			default => null,
		};
	}
}
