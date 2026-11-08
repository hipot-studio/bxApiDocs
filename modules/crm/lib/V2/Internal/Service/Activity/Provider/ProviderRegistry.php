<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Provider;

use Bitrix\Crm\Activity\Entity;
use Bitrix\Crm\Activity\Provider;
use Bitrix\Crm\Feature;
use Bitrix\Main\ArgumentException;
use Closure;

/**
 * The registry of activity providers.
 *
 * THE COMPOSITION REPRODUCES THE LEGACY LIST ELEMENT BY ELEMENT, order included, in both of its readings.
 * That is a hard requirement rather than a nicety: the list decides which stored activities resolve to a
 * provider at all, and which of them a grid filter, a planner or the timeline shows. The two conditional
 * shapes are kept apart exactly as legacy had them - the providers of a visit and of a conference are absent
 * from the ordinary list and present in the complete one, while the provider of repeat sales is absent from
 * both when its feature is off. The second one looks like an oversight of legacy and is reproduced anyway:
 * "the complete list" is defined by what the callers of it see today. NEITHER CONDITION REACHES THE TIMELINE
 * MAPS, which legacy stated as literal arrays of the timeline itself - see below.
 *
 * THE COMPOSITION IS ANSWERED ONCE PER HIT. Whoever asks twice gets the same set, as with the static caches of
 * legacy; a condition that reads a licence or loads a module would otherwise be able to answer differently
 * halfway through an operation.
 *
 * ONE INSTANCE PER HIT IS THE POINT. The registry is a service, and registering a provider from another module
 * only means anything if everybody asks the same instance. Building a second one by hand gives a registry that
 * knows the built-in providers and nothing else.
 *
 * PROVIDERS ARE BUILT ON DEMAND AND ONCE. Answering the composition builds none of them, and asking for one
 * twice returns the same object; a provider carries no state of an activity, so sharing it is safe.
 *
 * ONE MEMBER IS ALREADY WRITTEN AGAINST THE CONTRACT OF V2 - the provider of to-do items, the pilot of the
 * migration ({@see Native\ToDoProvider}). Its descriptor brings the object; every other member is still a legacy
 * class and gets the wrapper. What a legacy consumer sees does not depend on that difference: the descriptor of
 * the migrated provider answers the same class name and the same instance of it as before.
 *
 * IT IS ALSO THE SOURCE OF THE THREE TIMELINE MAPS. Which providers the timeline shows, which of them its
 * controller serves and which build a card of their own used to be three literal arrays in the timeline itself;
 * they are declarations of a provider, so they are stated here, by the three lists below and by
 * {@see ProviderDescriptor::getTimelineCapabilities()}. Those arrays were subject to no condition at all, so
 * the maps are answered by a reading of their own that honours none either
 * ({@see TimelineParticipationInterface}): asking them through the composition of the registry would have let
 * the feature behind a provider decide what the timeline shows, which it never did.
 *
 * ONE MEMBER HAS NO OBJECT. The provider of a wait was never registered by legacy and never ran anything, yet
 * its activities appear in the timeline; it is a member here so that the timeline lists can be built from the
 * registry, and asking for its object answers nothing - see {@see ProviderDescriptor::isMembershipOnly()}.
 *
 * @internal
 */
final class ProviderRegistry implements ProviderRegistryInterface, TimelineParticipationInterface
{
	public const SERVICE_CODE = 'crm.service.activity.provider.registry';

	/**
	 * The providers whose activities belong in the timeline at all - map Nr6, fixed by DTO-03.
	 *
	 * THE THREE LISTS BELOW ARE THE MAPS THEMSELVES, in the order the literal arrays they replace had. Each of
	 * them is one subject, so each is read whole rather than assembled from thirty declarations scattered over
	 * the descriptors; and a provider absent from a list declares the negative answer, which is the answer of
	 * the quarter of the providers taking no part in the timeline at all.
	 *
	 * THE DISAGREEMENT OF Nr6 AND Nr7 IS THE POINT AND NOT A DEFECT TO FIX. Six providers belong to the first and
	 * not to the second, five to the second and not to the first; whether that was ever intended nobody can say
	 * from the outside, so it is conserved. Levelling the two lists changes what the timeline shows.
	 */
	private const PROVIDERS_OF_THE_TIMELINE = [
		Provider\WebForm::class,
		Provider\Wait::class,
		Provider\Request::class,
		Provider\OpenLine::class,
		Provider\RestApp::class,
		Provider\Delivery::class,
		Provider\Zoom::class,
		Provider\CallTracker::class,
		Provider\StoreDocument::class,
		Provider\SignDocument::class,
		Provider\SignB2eDocument::class,
		Provider\ToDo\ToDo::class,
		Provider\Payment::class,
		Provider\ConfigurableRestApp::class,
		Provider\CalendarSharing::class,
		Provider\Tasks\Comment::class,
		Provider\Tasks\Task::class,
		Provider\Bizproc\Workflow::class,
		Provider\Bizproc\Comment::class,
		Provider\Bizproc\Task::class,
		Provider\Booking\Booking::class,
		Provider\Booking\WaitListItem::class,
		Provider\RepeatSale::class,
		Provider\EntityExclusion::class,
		Provider\Sync::class,
	];

	/** The providers the timeline controller serves - map Nr7, fixed by DTO-03. */
	private const PROVIDERS_SERVED_BY_THE_TIMELINE_CONTROLLER = [
		Provider\WebForm::class,
		Provider\Wait::class,
		Provider\Request::class,
		Provider\OpenLine::class,
		Provider\Sms::class,
		Provider\WhatsApp::class,
		Provider\Telegram::class,
		Provider\Notification::class,
		Provider\RestApp::class,
		Provider\Visit::class,
		Provider\Zoom::class,
		Provider\SignDocument::class,
		Provider\SignB2eDocument::class,
		Provider\ToDo\ToDo::class,
		Provider\ConfigurableRestApp::class,
		Provider\CalendarSharing::class,
		Provider\Tasks\Comment::class,
		Provider\Tasks\Task::class,
		Provider\Bizproc\Workflow::class,
		Provider\Bizproc\Comment::class,
		Provider\Bizproc\Task::class,
		Provider\RepeatSale::class,
		Provider\EntityExclusion::class,
		Provider\Sync::class,
	];

	/**
	 * The providers building a card of their own instead of leaving an activity to the generic ones - map Nr8,
	 * fixed by DTO-03. Which card each of them builds is the business of the provider object, and for a legacy
	 * class that of {@see Timeline\LegacyTimelineItemFactory}.
	 */
	private const PROVIDERS_BUILDING_THEIR_OWN_TIMELINE_ITEM = [
		Provider\OpenLine::class,
		Provider\SignDocument::class,
		Provider\SignB2eDocument::class,
		Provider\ToDo\ToDo::class,
		Provider\StoreDocument::class,
		Provider\Payment::class,
		Provider\Delivery::class,
		Provider\Sms::class,
		Provider\Telegram::class,
		Provider\WhatsApp::class,
		Provider\Notification::class,
		Provider\CalendarSharing::class,
		Provider\ConfigurableRestApp::class,
		Provider\Tasks\Comment::class,
		Provider\Tasks\Task::class,
		Provider\Visit::class,
		Provider\Booking\Booking::class,
		Provider\Booking\WaitListItem::class,
		Provider\Zoom::class,
		Provider\Bizproc\Workflow::class,
		Provider\Bizproc\Comment::class,
		Provider\Bizproc\Task::class,
		Provider\RepeatSale::class,
		Provider\EntityExclusion::class,
		Provider\Sync::class,
	];

	private readonly Closure $providerFactory;

	/** @var array<string, ProviderDescriptor>|null */
	private ?array $descriptors = null;

	/** @var array<int, array<string, ProviderDescriptor>> */
	private array $composition = [];

	/** @var array<string, ProviderDescriptor>|null */
	private ?array $timelineParticipants = null;

	/** @var array<string, ActivityProviderInterface> */
	private array $providers = [];

	/**
	 * @param Closure|null $providerFactory
	 *        `function (ProviderDescriptor $descriptor): ActivityProviderInterface` - turns one descriptor into
	 *        the object of its provider, and the one place any provider object is built. The default asks the
	 *        descriptor for the object it brings - which is what a provider rewritten natively brings - and
	 *        wraps the legacy class of every other member into the contract of a provider
	 *        ({@see LegacyProviderAdapter}). The whole descriptor is handed over rather than the class name
	 *        alone because two of the answers of a wrapped provider are declared by its descriptor and cannot
	 *        be read off a legacy class: whether its activities belong in the timeline, and whether the
	 *        timeline controller serves them. Replacing the seam decides for every provider alike, which is
	 *        what a test putting a probe behind the provider of one activity needs.
	 */
	public function __construct(?Closure $providerFactory = null)
	{
		$this->providerFactory = $providerFactory ?? static fn(
			ProviderDescriptor $descriptor
		): ActivityProviderInterface => $descriptor->createProvider() ?? new LegacyProviderAdapter(
			$descriptor->getClassName(),
			$descriptor->getTimelineCapabilities(),
		);
	}

	public function getAll(bool $includeDisabled = false): array
	{
		$reading = (int)$includeDisabled;
		if (!isset($this->composition[$reading]))
		{
			$this->composition[$reading] = $this->compose($includeDisabled);
		}

		return $this->composition[$reading];
	}

	public function getDescriptor(string $providerId, bool $includeDisabled = false): ?ProviderDescriptor
	{
		return $this->getAll($includeDisabled)[$providerId] ?? null;
	}

	public function get(string $providerId, bool $includeDisabled = false): ?ActivityProviderInterface
	{
		$descriptor = $this->getDescriptor($providerId, $includeDisabled);
		if ($descriptor === null || $descriptor->isMembershipOnly())
		{
			return null;
		}

		return $this->instantiate($descriptor);
	}

	public function getByCapability(string $capability, bool $includeDisabled = false): array
	{
		$providers = [];
		foreach ($this->getAll($includeDisabled) as $providerId => $descriptor)
		{
			if ($descriptor->hasCapability($capability))
			{
				$providers[$providerId] = $this->instantiate($descriptor);
			}
		}

		return $providers;
	}

	/**
	 * @throws ArgumentException when the identifier of the descriptor is already taken.
	 */
	public function register(ProviderDescriptor $descriptor): void
	{
		$providerId = $descriptor->getId();
		$registered = $this->descriptors();
		if (isset($registered[$providerId]))
		{
			throw new ArgumentException(
				"The activity provider identifier '{$providerId}' is already taken by"
				. " '{$registered[$providerId]->getClassName()}', so '{$descriptor->getClassName()}' cannot"
				. ' claim it'
			);
		}

		$this->descriptors[$providerId] = $descriptor;
		$this->composition = [];
		$this->timelineParticipants = null;
	}

	public function getTimelineParticipants(): array
	{
		if ($this->timelineParticipants === null)
		{
			$participants = [];
			foreach ($this->descriptors() as $providerId => $descriptor)
			{
				if (self::declaresTimelineParticipation($descriptor))
				{
					$participants[$providerId] = $descriptor;
				}
			}

			$this->timelineParticipants = $participants;
		}

		return $this->timelineParticipants;
	}

	public function getTimelineParticipant(string $providerId): ?ActivityProviderInterface
	{
		$descriptor = $this->getTimelineParticipants()[$providerId] ?? null;
		if ($descriptor === null || $descriptor->isMembershipOnly())
		{
			return null;
		}

		return $this->instantiate($descriptor);
	}

	/**
	 * @return array<string, ProviderDescriptor>
	 */
	private function compose(bool $includeDisabled): array
	{
		$composition = [];
		foreach ($this->descriptors() as $providerId => $descriptor)
		{
			if (!$descriptor->isRegistered())
			{
				continue;
			}

			if (!$includeDisabled && !$descriptor->isAvailable())
			{
				continue;
			}

			$composition[$providerId] = $descriptor;
		}

		return $composition;
	}

	private function instantiate(ProviderDescriptor $descriptor): ActivityProviderInterface
	{
		$providerId = $descriptor->getId();
		if (!isset($this->providers[$providerId]))
		{
			$this->providers[$providerId] = $this->buildProvider($descriptor);
		}

		return $this->providers[$providerId];
	}

	/**
	 * @throws ArgumentException when building returns something that is not a provider.
	 */
	private function buildProvider(ProviderDescriptor $descriptor): ActivityProviderInterface
	{
		$provider = ($this->providerFactory)($descriptor);
		if (!($provider instanceof ActivityProviderInterface))
		{
			throw new ArgumentException(
				"Building the activity provider '{$descriptor->getId()}' did not return a provider"
			);
		}

		return $provider;
	}

	/**
	 * @return array<string, ProviderDescriptor>
	 */
	private function descriptors(): array
	{
		if ($this->descriptors === null)
		{
			$this->descriptors = $this->builtInDescriptors();
		}

		return $this->descriptors;
	}

	/**
	 * @return array<string, ProviderDescriptor>
	 */
	private function builtInDescriptors(): array
	{
		$descriptors = [
			$this->nativeToDoDescriptor(),
			$this->legacyDescriptor(Provider\Meeting::class),
			$this->legacyDescriptor(Provider\Task::class),
			$this->legacyDescriptor(className: Provider\Call::class, isRelatedFilterProvider: true),
			$this->legacyDescriptor(Provider\CallList::class),
			$this->legacyDescriptor(Provider\Email::class),
			$this->legacyDescriptor(Provider\Sms::class),
			$this->legacyDescriptor(Provider\WhatsApp::class),
			$this->legacyDescriptor(Provider\Telegram::class),
			$this->legacyDescriptor(Provider\Notification::class),
			$this->legacyDescriptor(className: Provider\OpenLine::class, providesLegacyInstance: true),
			$this->legacyDescriptor(Provider\WebForm::class),
			$this->legacyDescriptor(Provider\Livefeed::class),
			$this->legacyDescriptor(Provider\ExternalChannel::class),
			$this->legacyDescriptor(Provider\Request::class),
			$this->legacyDescriptor(Provider\RestApp::class),
			$this->legacyDescriptor(Provider\Delivery::class),
			$this->legacyDescriptor(Provider\CallTracker::class),
			$this->legacyDescriptor(Provider\StoreDocument::class),
			$this->legacyDescriptor(Provider\SignDocument::class),
			$this->legacyDescriptor(Provider\SignB2eDocument::class),
			$this->legacyDescriptor(Provider\Payment::class),
			$this->legacyDescriptor(Provider\ConfigurableRestApp::class),
			$this->legacyDescriptor(Provider\CalendarSharing::class),
			$this->legacyDescriptor(Provider\Tasks\Comment::class),
			$this->legacyDescriptor(Provider\Tasks\Task::class),
			$this->legacyDescriptor(Provider\Booking\Booking::class),
			$this->legacyDescriptor(Provider\Booking\WaitListItem::class),
			$this->legacyDescriptor(Provider\Bizproc\Workflow::class),
			$this->legacyDescriptor(Provider\Bizproc\Comment::class),
			$this->legacyDescriptor(
				className: Provider\Bizproc\Task::class,
				entityClassName: Entity\Task::class,
			),
			$this->legacyDescriptor(Provider\EntityExclusion::class),
			$this->legacyDescriptor(
				className: Provider\Visit::class,
				availableWhen: static fn(): bool => Provider\Visit::isAvailable(),
			),
			$this->legacyDescriptor(
				className: Provider\Zoom::class,
				availableWhen: static fn(): bool => Provider\Zoom::isAvailable(),
			),
			$this->legacyDescriptor(
				className: Provider\RepeatSale::class,
				registeredWhen: static fn(): bool => Feature::enabled(Feature\RepeatSale::class),
			),
			$this->legacyDescriptor(
				className: Provider\Sync::class,
				availableWhen: static fn(): bool => Provider\Sync::isAvailable(),
			),
			// Declared after the providers of the legacy list because it is no member of that list: the wait
			// belongs to the timeline and to nothing else - both of its lists, and no card of its own.
			$this->membershipOnlyDescriptor(Provider\Wait::class),
		];

		$byId = [];
		foreach ($descriptors as $descriptor)
		{
			$byId[$descriptor->getId()] = $descriptor;
		}

		return $byId;
	}

	/**
	 * The descriptor of the one provider already written against the contract of V2.
	 *
	 * ITS CLASS NAME STAYS THE LEGACY ONE. The factory of legacy instances, the lists the legacy consumers read
	 * and the comparisons existing code makes literally all go on receiving the legacy class: being native
	 * decides who executes the rules of the lifecycle, not what a legacy reader is handed.
	 *
	 * ITS CAPABILITIES ARE DECLARED, NOT COMPUTED. A native provider says what it takes part in by implementing
	 * the interfaces, and the list below names exactly those. The subscription to the scope operations is absent
	 * on purpose: it is the one capability every WRAPPED provider declares, because nothing can be told about a
	 * legacy class nobody has migrated yet, while a native provider subscribes where it has a rule of its own -
	 * and a to-do has none, its legacy class never having overridden one.
	 */
	private function nativeToDoDescriptor(): ProviderDescriptor
	{
		return new ProviderDescriptor(
			id: Native\ToDoProvider::ID,
			className: Provider\ToDo\ToDo::class,
			capabilities: [
				Capability\BadgeCapabilityInterface::class,
				Capability\CalendarCapabilityInterface::class,
				Capability\CompletionCapabilityInterface::class,
				Capability\PingCapabilityInterface::class,
			],
			factory: static fn(): ActivityProviderInterface => new Native\ToDoProvider(),
			entityClassName: Entity\ToDo::class,
			isRelatedFilterProvider: true,
			providesLegacyInstance: true,
			timelineCapabilities: self::timelineCapabilitiesOf(Provider\ToDo\ToDo::class),
		);
	}

	/**
	 * The descriptor of a provider still written as a legacy class.
	 *
	 * Its identifier and its capabilities are both read off that class: the value of the PROVIDER_ID column is
	 * the one the class has always answered, and which capabilities it takes part in is decided by which
	 * methods it overrides - a rule that belongs to the wrapper of a legacy provider and is asked of it here
	 * ({@see LegacyProviderAdapter::getDeclaredCapabilities()}).
	 *
	 * THE CAPABILITIES ARE DECLARED AS A CLOSURE, not as a list. Reading which methods a legacy class overrides
	 * is reflection over every method of every capability, and composing the registry - which happens on any hit
	 * that touches the list of providers, the flag of the delegation notwithstanding - asks nothing about
	 * capabilities: only the two steps that pick the providers of one capability do. The answer is computed on
	 * first demand and kept ({@see ProviderDescriptor::getCapabilities()}).
	 *
	 * @param string $className A subclass of `Provider\Base`.
	 */
	private function legacyDescriptor(
		string $className,
		?Closure $availableWhen = null,
		?Closure $registeredWhen = null,
		?string $entityClassName = null,
		bool $isRelatedFilterProvider = false,
		bool $providesLegacyInstance = false,
	): ProviderDescriptor
	{
		return new ProviderDescriptor(
			id: $className::getId(),
			className: $className,
			capabilities: static fn(): array => LegacyProviderAdapter::getDeclaredCapabilities($className),
			availableWhen: $availableWhen,
			registeredWhen: $registeredWhen,
			entityClassName: $entityClassName,
			isRelatedFilterProvider: $isRelatedFilterProvider,
			providesLegacyInstance: $providesLegacyInstance,
			timelineCapabilities: self::timelineCapabilitiesOf($className),
		);
	}

	/**
	 * The descriptor of a legacy provider that belongs to the registry for the sake of the lists built from it
	 * and for nothing else. It declares no capability on purpose: the methods its class carries were never
	 * called, because legacy never registered it as a provider at all. Its part in the timeline is a
	 * declaration and not a method, so that one it does state.
	 *
	 * @param string $className A subclass of `Provider\Base`.
	 */
	private function membershipOnlyDescriptor(string $className): ProviderDescriptor
	{
		return new ProviderDescriptor(
			id: $className::getId(),
			className: $className,
			timelineCapabilities: self::timelineCapabilitiesOf($className),
			isMembershipOnly: true,
		);
	}

	/**
	 * Whether the provider belongs to any of the three maps at all, which is what makes it a participant of the
	 * timeline. Roughly a quarter of the providers belong to none.
	 */
	private static function declaresTimelineParticipation(ProviderDescriptor $descriptor): bool
	{
		$capabilities = $descriptor->getTimelineCapabilities();

		return $capabilities->isTimelineSupported()
			|| $capabilities->isSupportedByTimelineController()
			|| $capabilities->providesTimelineItem();
	}

	/**
	 * What the provider of this legacy class declares about its part in the timeline: its membership in each of
	 * the three maps above.
	 */
	private static function timelineCapabilitiesOf(string $className): TimelineCapabilitySet
	{
		return new TimelineCapabilitySet(
			isTimelineSupported: in_array($className, self::PROVIDERS_OF_THE_TIMELINE, true),
			isSupportedByTimelineController: in_array(
				$className,
				self::PROVIDERS_SERVED_BY_THE_TIMELINE_CONTROLLER,
				true,
			),
			providesTimelineItem: in_array(
				$className,
				self::PROVIDERS_BUILDING_THEIR_OWN_TIMELINE_ITEM,
				true,
			),
		);
	}
}
