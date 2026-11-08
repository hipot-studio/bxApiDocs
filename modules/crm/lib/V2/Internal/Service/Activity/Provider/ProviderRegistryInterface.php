<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Provider;

/**
 * The one place that knows which activity providers exist.
 *
 * ONE SOURCE INSTEAD OF FIVE. Legacy answered the same question from five separate literal maps - the list of
 * providers, the fallback by the legacy type of an activity, the factory of instances, the domain entity
 * behind a provider, the participation in the filter over related activities - and they drifted apart, so
 * which map you asked decided what you learned. Here the composition is asked once and the rest are readings
 * of the same descriptors.
 *
 * "INCLUDING THE DISABLED ONES" IS AN ARGUMENT, NOT ANOTHER METHOD. Legacy grew a second lookup for the
 * callers that must resolve an activity written by a provider which no longer applies. It is the same question
 * about the same registry, so it is the same method with a flag.
 *
 * THE COMPOSITION DOES NOT CHANGE WITHIN A HIT. Legacy held it in static caches that nothing invalidated, and
 * whoever asked twice got the same answer; that property is kept, because a condition evaluated twice - a
 * module loaded halfway through, a licence read again - would otherwise make one operation see two different
 * sets of providers.
 *
 * REGISTERING A DESCRIPTOR IS THE WAY IN FOR ANOTHER MODULE. The event legacy offered for this had no
 * subscribers and is not revived.
 *
 * @internal
 */
interface ProviderRegistryInterface
{
	/**
	 * The composition of the registry: the descriptor of every provider by its identifier, in the order the
	 * providers were declared. Answering it builds no providers.
	 *
	 * @return array<string, ProviderDescriptor>
	 */
	public function getAll(bool $includeDisabled = false): array;

	public function getDescriptor(string $providerId, bool $includeDisabled = false): ?ProviderDescriptor;

	/**
	 * The provider object, or null when no provider of this identifier belongs to the registry - which is what
	 * an activity of an unknown or of an unavailable provider resolves to.
	 */
	public function get(string $providerId, bool $includeDisabled = false): ?ActivityProviderInterface;

	/**
	 * The providers that take part in a capability, named by the interface of it. A provider that declares no
	 * rule of its own for the capability is not among them, and neither is an unavailable one unless asked for.
	 *
	 * @return array<string, ActivityProviderInterface>
	 */
	public function getByCapability(string $capability, bool $includeDisabled = false): array;

	/**
	 * Adds a provider to the registry. Belongs to the bootstrap of a module: the composition is expected to be
	 * settled before anything asks for it.
	 *
	 * A TAKEN IDENTIFIER IS AN ERROR AND IS REPORTED AS ONE. Two providers claiming one value of PROVIDER_ID
	 * means the rows of one of them would be resolved to the other, which is a broken integration rather than
	 * a situation to resolve silently in favour of somebody.
	 *
	 * THE LEGACY ENUMERATION OF THE PROVIDERS IS NARROWER THAN THE REGISTRY, so a registered descriptor is not
	 * necessarily in it. A descriptor whose class name is no descendant of `Bitrix\Crm\Activity\Provider\Base`
	 * is a full member here - it resolves an activity, it answers its capabilities - and is still left out of
	 * `ProviderManager::getProviders()`, of `::getAllProviders()` and of whatever reads them, because those
	 * readers call the STATIC hooks of the legacy contract on the class name they are handed. A provider that
	 * has to appear in those lists too brings a class of that lineage; the event legacy offered for this is not
	 * revived, so there is no other way in.
	 */
	public function register(ProviderDescriptor $descriptor): void;
}
