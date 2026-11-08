<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Provider;

/**
 * The reading of the registry the three timeline maps are built from.
 *
 * IT IS A READING OF ITS OWN BECAUSE THE MAPS ARE NOT THE LIST OF PROVIDERS. Legacy stated them apart - the
 * list of providers was assembled under its conditions, and the maps were literal arrays of the timeline that
 * no condition touched. So membership in a map follows the declaration of a provider about the timeline and
 * nothing else: neither availability nor registration takes a provider out of it. The provider of repeat sales
 * is the member that shows the difference - its feature keeps it out of the list of providers in both readings
 * of that list, while the literal arrays held it whatever the feature said, and an activity of it written on a
 * portal that later switched the feature off goes on having its card.
 *
 * IT IS APART FROM {@see ProviderRegistryInterface} for the same reason: whoever asks the registry which
 * providers exist asks a different question than whoever renders the timeline, and the answers differ.
 *
 * @internal
 */
interface TimelineParticipationInterface
{
	/**
	 * The descriptors of the providers declaring a part in the timeline - the members of at least one of the
	 * three maps, by identifier, in the order the providers were declared. Answering it builds no providers.
	 *
	 * @return array<string, ProviderDescriptor>
	 */
	public function getTimelineParticipants(): array;

	/**
	 * The provider object of a timeline participant, and null when no provider of this identifier takes part in
	 * the timeline or when it is a member of the maps and nothing else
	 * ({@see ProviderDescriptor::isMembershipOnly()}).
	 */
	public function getTimelineParticipant(string $providerId): ?ActivityProviderInterface;
}
