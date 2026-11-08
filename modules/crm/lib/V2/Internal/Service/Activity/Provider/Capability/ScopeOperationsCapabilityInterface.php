<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext;
use Bitrix\Crm\V2\Public\OwnerType;

/**
 * What a provider wants to hear about regardless of whose activity it is: every activity created, and every
 * owner whose activities move somewhere else or go away.
 *
 * THIS REPLACES A LOOP OVER EVERYBODY WITH A SUBSCRIPTION. Legacy asked all thirty-odd providers on every
 * single write, and two of the three questions were answered by exactly one of them. Implementing this
 * interface is how a provider gets asked; not implementing it is how it stops being asked. The observable
 * result does not change: an implementation over a legacy provider subscribes every one of them, because
 * until they are migrated one cannot tell from the outside which of them would have answered.
 *
 * TODAY ONLY THE PROVIDER OF CALL LISTS REACTS to the two owner operations, and nothing at all reacts to a
 * creation - the one legacy override of it belongs to a provider that was never registered, so the loop over
 * everybody has always been empty. The creation hook is kept because the write path still asks it and it is
 * the point where a native provider will hook in; the set of reactions is not widened by moving the question
 * here.
 *
 * AN OWNER HERE IS NOT AN ITEM. Both owner operations are called from the recycle bin alone, and it addresses
 * activities by the suspended counterpart of a type - a suspended lead, a suspended deal, a suspended smart
 * process - which is by definition not an item type. So the owner is expressed the way a binding of an
 * activity expresses it: a type of any CRM object plus its identifier. Narrowing it to an item identifier
 * would throw on every call the recycle bin makes.
 *
 * Normative shape: TPL-09, with the type of an owner widened for the reason just given. The legacy methods,
 * their call sites and who overrides them: LBS-2 sections 2.6 and 5, checklist items C7 and C16.
 *
 * @internal
 */
interface ScopeOperationsCapabilityInterface
{
	/**
	 * Announced after any activity has been created, whoever its provider is.
	 */
	public function onActivityCreated(Activity $activity, ActivityOperationContext $context): void;

	/**
	 * Moves whatever the provider keeps of its own about the activities of one owner over to another owner.
	 *
	 * The recycle bin calls it twice per record: once from the live owner to its suspended counterpart, and
	 * once back again.
	 */
	public function transferOwnership(
		OwnerType $fromOwnerType,
		int $fromOwnerId,
		OwnerType $toOwnerType,
		int $toOwnerId
	): void;

	/**
	 * Disposes of whatever the provider keeps of its own about the activities of an owner being erased.
	 */
	public function deleteByOwner(OwnerType $ownerType, int $ownerId): void;
}
