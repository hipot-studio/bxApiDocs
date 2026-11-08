<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Provider;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ProviderPayload;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\PermissionCapabilityInterface;
use Bitrix\Main\Result;

/**
 * What every activity provider must be able to answer: who it is, which types it offers, and whether the
 * fields of an activity of its own kind are acceptable.
 *
 * THIS IS THE WHOLE OF THE MANDATORY PART. Everything else a provider may take part in - the calendar, the
 * hooks of the lifecycle, the badges, the reminders, the rights ({@see PermissionCapabilityInterface}) - is a
 * separate interface, and not implementing one is the way to say "no rule of my own here". Legacy expressed
 * the same thing with about sixty static methods on one base class, where a default answer and an opinion
 * were indistinguishable, so a provider that had nothing to say still said something.
 *
 * IDENTITY IS THE VALUE OF A COLUMN. {@see self::getId()} returns what the PROVIDER_ID column of an activity
 * holds, and those values do not change: rows written years ago are resolved by them. The other identity of
 * legacy - the name of the class, compared literally by existing code - belongs to the descriptor of the
 * registry ({@see ProviderDescriptor::getClassName()}), not here.
 *
 * BEING AVAILABLE AND BEING ACTIVE ARE TWO DIFFERENT FACTS, and they were already two in legacy.
 * Availability is the condition of membership: an unavailable provider is not in the list at all, which is
 * why an activity of its kind resolves to no provider. Being active is narrower - the provider is in the
 * list, but its feature is switched off on this portal, and that shows up as a filter over the lists of
 * types and as an "unavailable" card in the timeline. Neither answer may be derived from the other.
 *
 * INSTANCES, NOT CLASS NAMES. A provider is an object, so collaborators are given to it rather than looked
 * up from static state, and a test may replace one wholesale.
 *
 * @internal
 */
interface ActivityProviderInterface
{
	/**
	 * The value the PROVIDER_ID column of an activity of this kind holds.
	 */
	public function getId(): string;

	public function getName(): string;

	/**
	 * Whether the provider exists on this portal at all. This is the condition of membership in the registry
	 * of providers, and the registry decides membership by the declaration of a descriptor rather than by
	 * building every provider to ask it - the two must therefore agree.
	 */
	public function isAvailable(): bool;

	/**
	 * Whether the feature behind the provider is switched on. A provider may be a member of the registry and
	 * still be inactive; it keeps resolving activities of its kind, and only the lists of types and the
	 * timeline take the answer into account.
	 */
	public function isActive(): bool;

	/**
	 * The kinds of activity this provider offers, in the order they are shown.
	 *
	 * @return array<int, array{name: string, providerTypeId: string, directions: array<int, string>}>
	 *         `directions` is indexed by the direction of an activity and holds the name to show for it; a
	 *         provider whose kind has no direction leaves it empty.
	 */
	public function getTypes(): array;

	/**
	 * The value of PROVIDER_TYPE_ID to store when the caller named none, or null when the provider has no
	 * default of its own. Asked after {@see self::validate()} has run and only for an empty value: a type the
	 * validation has already derived is never overwritten.
	 */
	public function getDefaultTypeId(): ?string;

	/**
	 * Checks and normalizes the fields of an activity of this kind before they are stored.
	 *
	 * NORMALIZATION HAPPENS BY MUTATION - the provider changes the activity, the payload and the columns
	 * carried by the context, and those changes are what gets written. This is the typed replacement of the
	 * by-reference field array of legacy, and the deadline is the reason it cannot be read-only: a deadline
	 * is not a field of the caller but a value derived by the provider from the start or the end of an
	 * activity, and there is no other point in an operation where it may be derived.
	 *
	 * A FAILING RESULT REFUSES THE WHOLE OPERATION, as an error of the check did in legacy. The errors are
	 * the ones shown to whoever asked, so they are messages about the fields and not about the provider.
	 */
	public function validate(Activity $activity, ProviderPayload $payload, ValidationContext $context): Result;
}
