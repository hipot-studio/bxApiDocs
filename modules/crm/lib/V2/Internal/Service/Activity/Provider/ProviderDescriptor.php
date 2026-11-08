<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Provider;

use Bitrix\Main\ArgumentException;
use Closure;

/**
 * What the registry knows about one provider without building it.
 *
 * METADATA, NOT BEHAVIOUR. A descriptor states that a provider exists, under which conditions, and which
 * capabilities it takes part in; the code of every capability lives on the provider object. The separation is
 * what lets the composition of the registry be answered - and compared with the legacy lists element by
 * element - without instantiating three dozen providers, and it is also the only workable way to tell which
 * capabilities a wrapped legacy provider really has: one adapter class serves them all, so asking it with
 * instanceof would answer the same for every one of them.
 *
 * TWO CONDITIONS, BECAUSE LEGACY HAD TWO. Availability is what the "including the disabled ones" lookup
 * bypasses: the provider of a visit and the provider of a conference disappear from the ordinary list on
 * portals where they do not apply, yet an activity written by them earlier still has to resolve. The other
 * condition is not bypassable by anything: the provider of repeat sales is behind a feature flag, and with the
 * flag off it is absent from both lists - including the one that was meant to be complete. That asymmetry is
 * reproduced rather than tidied up.
 *
 * TWO NAMES FOR ONE PROVIDER. The identifier is the value of the PROVIDER_ID column, and the class name is
 * what legacy code compares literally and passes to class_exists. Both are kept because both are load-bearing,
 * and a provider rewritten natively keeps the legacy class name here: the factory of legacy instances and the
 * lists that legacy consumers read must go on receiving exactly what they received before.
 *
 * THE TIMELINE IS DECLARED APART from the other capabilities, by a set of its own. The composition of the two
 * timeline lists has to be answerable without building any provider, and a capability named in the list above
 * only says that the provider object honours a contract - for a wrapped legacy provider that is true of all of
 * them at once, so it could not tell which of them belongs to which list.
 *
 * A MEMBER MAY HAVE NO OBJECT AT ALL. One provider of legacy takes part in the timeline lists while never
 * having been registered as a provider: nothing ever asked it to check fields or to react to an activity
 * created, and asking legacy for it answered nothing. It is a member here so that the lists built from the
 * registry are the lists legacy had, and it is marked as belonging to them alone - see
 * {@see self::isMembershipOnly()}.
 *
 * @internal
 */
final class ProviderDescriptor
{
	/**
	 * The capabilities once they are known: the list itself when the declaration was a list, the answer of the
	 * closure once it was called. Null means nobody has asked yet, and the closure has not run.
	 *
	 * @var string[]|null
	 */
	private ?array $resolvedCapabilities = null;

	/**
	 * @param string $id The value of the PROVIDER_ID column. Unique across the registry.
	 * @param string $className The class name legacy consumers see for this provider. For a provider still
	 *        served by legacy code it is that legacy class; for a native one it stays the legacy class as
	 *        long as one exists, and is the class of the provider itself otherwise.
	 * @param Closure|string[] $capabilities The capabilities the provider takes part in, named by the interface
	 *        of each. A capability absent from here is one the provider has no rule of its own for. A closure
	 *        `function (): string[]` in place of the list is called at most once, the first time somebody asks
	 *        what the provider takes part in - see {@see self::getCapabilities()}.
	 * @param Closure|null $availableWhen `function (): bool` - the condition of the ordinary lists, bypassed
	 *        by a lookup that includes the disabled providers. Null means always available.
	 * @param Closure|null $registeredWhen `function (): bool` - the condition of belonging to the registry at
	 *        all, bypassed by nothing. Null means always registered.
	 * @param Closure|null $factory `function (): ActivityProviderInterface` - builds the provider object.
	 *        Null means the object is an adapter over the legacy class named above, which the registry builds.
	 * @param string|null $entityClassName The class of the domain entity behind activities of this provider,
	 *        for the consumers that instantiate one. Null when the provider has none.
	 * @param bool $isRelatedFilterProvider Whether the provider takes part in the filter over related
	 *        activities.
	 * @param bool $providesLegacyInstance Whether legacy consumers may ask for an instance of the legacy
	 *        class of this provider.
	 * @param TimelineCapabilitySet $timelineCapabilities Which of the three timeline questions the provider
	 *        answers affirmatively. The default declares all three negative, which is the answer of a provider
	 *        that takes no part in the timeline.
	 * @param bool $isMembershipOnly Whether the provider belongs to the registry for the sake of the lists
	 *        built from it and for nothing else: it has no object and no rule of its own to run.
	 *
	 * @throws ArgumentException on an empty identifier or class name, on a provider declared as belonging to the
	 *         lists alone while bringing something to run, or on an empty capability of a list given as a list.
	 *         A list arriving as a closure is checked where it is resolved and not here.
	 */
	public function __construct(
		private readonly string $id,
		private readonly string $className,
		private readonly Closure|array $capabilities = [],
		private readonly ?Closure $availableWhen = null,
		private readonly ?Closure $registeredWhen = null,
		private readonly ?Closure $factory = null,
		private readonly ?string $entityClassName = null,
		private readonly bool $isRelatedFilterProvider = false,
		private readonly bool $providesLegacyInstance = false,
		private readonly TimelineCapabilitySet $timelineCapabilities = new TimelineCapabilitySet(),
		private readonly bool $isMembershipOnly = false,
	)
	{
		if ($this->id === '')
		{
			throw new ArgumentException('An activity provider descriptor needs an identifier');
		}

		if ($this->className === '')
		{
			throw new ArgumentException("The descriptor of the activity provider '{$this->id}' needs a class name");
		}

		if (is_array($this->capabilities))
		{
			$this->resolvedCapabilities = $this->assertedCapabilities($this->capabilities);
		}

		if ($this->isMembershipOnly && ($this->capabilities !== [] || $this->factory !== null))
		{
			throw new ArgumentException(
				"The activity provider '{$this->id}' belongs to the lists of the registry alone, so it can"
				. ' neither declare a capability nor bring an object'
			);
		}
	}

	/**
	 * @param array $capabilities
	 * @return string[] the same list, once every name in it is a name.
	 * @throws ArgumentException on a name that is not a non-empty string.
	 */
	private function assertedCapabilities(array $capabilities): array
	{
		foreach ($capabilities as $capability)
		{
			if (!is_string($capability) || $capability === '')
			{
				throw new ArgumentException(
					"The capabilities declared by the activity provider '{$this->id}' must be named by strings"
				);
			}
		}

		return $capabilities;
	}

	public function getId(): string
	{
		return $this->id;
	}

	/**
	 * The class name legacy consumers see for this provider.
	 */
	public function getClassName(): string
	{
		return $this->className;
	}

	public function isAvailable(): bool
	{
		return $this->availableWhen === null || ($this->availableWhen)() === true;
	}

	public function isRegistered(): bool
	{
		return $this->registeredWhen === null || ($this->registeredWhen)() === true;
	}

	/**
	 * The capabilities the provider takes part in, computed on first demand when the declaration is a closure.
	 *
	 * WHY THE ANSWER IS LAZY. Composing the registry builds every descriptor, and almost nobody who composes it
	 * asks this question: the lists of the legacy consumers, the resolution of one provider, the lookups of the
	 * timeline all read other fields. The capabilities of a wrapped legacy provider are the expensive answer of
	 * the three dozen - {@see LegacyProviderAdapter::getDeclaredCapabilities()} reads them off the class with
	 * reflection - so the descriptors of those declare a closure and pay for it only where a step really asks
	 * which providers take part in what.
	 *
	 * @return string[]
	 * @throws ArgumentException on an empty capability, or on a closure answering something other than a list.
	 */
	public function getCapabilities(): array
	{
		if ($this->resolvedCapabilities !== null)
		{
			return $this->resolvedCapabilities;
		}

		$declared = ($this->capabilities)();
		if (!is_array($declared))
		{
			throw new ArgumentException(
				"The capabilities of the activity provider '{$this->id}' were not answered as a list"
			);
		}

		return $this->resolvedCapabilities = $this->assertedCapabilities($declared);
	}

	public function hasCapability(string $capability): bool
	{
		return in_array($capability, $this->getCapabilities(), true);
	}

	/**
	 * What the provider declares about its part in the timeline. Never null: a provider taking no part in it
	 * declares three negative answers.
	 */
	public function getTimelineCapabilities(): TimelineCapabilitySet
	{
		return $this->timelineCapabilities;
	}

	/**
	 * Whether the provider belongs to the registry for the sake of the lists built from it and for nothing else.
	 *
	 * Such a provider has no object: legacy never registered it, so nothing ever asked it to check the fields of
	 * an activity or to react to one created, and whoever asks for it gets the same nothing as before - the
	 * behaviour of an activity whose provider is absent. It is no member of the list of providers either. What
	 * it is a member of is the timeline: its activities are shown there, and reproducing those lists is the
	 * whole reason it is here.
	 */
	public function isMembershipOnly(): bool
	{
		return $this->isMembershipOnly;
	}

	/**
	 * The provider object when the descriptor brings one of its own, and null when the object is an adapter
	 * over the legacy class - building that one is the business of the registry.
	 *
	 * @throws ArgumentException when the factory of the descriptor returns something else.
	 */
	public function createProvider(): ?ActivityProviderInterface
	{
		if ($this->factory === null)
		{
			return null;
		}

		$provider = ($this->factory)();
		if (!($provider instanceof ActivityProviderInterface))
		{
			throw new ArgumentException(
				"The factory of the activity provider '{$this->id}' did not return a provider"
			);
		}

		return $provider;
	}

	public function getEntityClassName(): ?string
	{
		return $this->entityClassName;
	}

	public function isRelatedFilterProvider(): bool
	{
		return $this->isRelatedFilterProvider;
	}

	/**
	 * An instance of the legacy class of this provider for the legacy consumers that need one, or null when
	 * the provider offers none. The class is the legacy one even for a provider rewritten natively: what those
	 * consumers get does not change.
	 */
	public function createLegacyInstance(): ?object
	{
		if (!$this->providesLegacyInstance)
		{
			return null;
		}

		return new ($this->className)();
	}
}
