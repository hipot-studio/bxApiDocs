<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityChangeSet;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext;
use Bitrix\Crm\V2\Public\ItemId;

/**
 * What a provider does to the row in another subsystem that an activity of its kind is a projection of: a
 * task of the module of tasks, a comment of one, a conference, a generated document.
 *
 * NONE OF THE THREE CAN REFUSE. Legacy declared two of them returning a result and dropped it at every call
 * site - one provider even returns a plain false instead of a result and nobody notices - so a result here
 * would invent a veto the write path never had. The projection is kept in step with the activity, or it is
 * not; the activity is written either way.
 *
 * WHETHER TO ASK AT ALL IS NOT THE PROVIDER'S BUSINESS. Three conditions are checked before: the caller has
 * not asked for the associated entity to be skipped, a provider resolves from the state being written, and
 * the identifier of the associated entity is above zero. A provider is never handed a zero identifier and
 * never has to guard against one.
 *
 * THE RAW USER OF THE CALLER MUST SURVIVE THE TRIP. The provider of tasks picks who performs the task by a
 * chain of three - the named executor, then the value the caller actually passed, then the person
 * responsible - so {@see ActivityOperationContext::getExecutorId()} and
 * {@see ActivityOperationContext::getRawCurrentUser()} are what {@see self::updateAssociatedEntity()} is
 * really given the context for. Collapsing the raw value into the effective one makes the second link of
 * that chain always match and quietly reassigns tasks.
 *
 * AND SO MUST EVERYTHING ELSE THE CALLER SAID. Legacy hands the whole option array of the caller over here and a
 * legacy provider reads keys of its own out of it - what to leave alone about a task, whether the projection of a
 * document goes at all - so the context carries that array as well
 * ({@see ActivityOperationContext::getLegacyOptions()}) and the wrapper of a legacy provider hands it on.
 *
 * THE OWNER OF A REBIND IS AN ITEM, THE OWNER OF A SCOPE OPERATION IS NOT, and the difference is not
 * cosmetic. A rebind happens only when two records are merged, and only leads, deals, contacts, companies,
 * quotes and smart processes can be merged - so an item identifier holds every case, and legacy even passes
 * the same owner type on both sides. The scope operations of
 * {@see ScopeOperationsCapabilityInterface} address owners that no item identifier can express.
 *
 * Normative shape: TPL-07. The legacy methods, their call sites and who overrides them: LBS-2 section 2.6
 * and section 4.3, item 10 of section 7, checklist item C7.
 *
 * @internal
 */
interface AssociatedEntityCapabilityInterface
{
	/**
	 * Carries a write of the activity over to its projection.
	 *
	 * TOLD WHAT THE ACTIVITY NOW IS, AND WHAT CHANGED BESIDE IT. Legacy hands over the row it re-read after the
	 * write (crm_activity.php:1018) and the providers that react decide by the WHOLE of it: the one of tasks
	 * compares the title, the description, both ends of the interval and the person responsible of the activity
	 * with the task it keeps, and a value the operation did not touch is a value it must still see - handed only
	 * what changed, it refuses an activity whose responsible was not part of the write and never gets to the rest.
	 * So the state comes first, as the state the run left: the typed model of the re-read row and the storage
	 * columns beside it, which is where the row of the other subsystem and the owner of the activity live. The set
	 * of changes travels along because deciding by both sides is legitimate and a native provider has no other way
	 * to ask "what moved".
	 */
	public function updateAssociatedEntity(
		int $associatedEntityId,
		Activity $activity,
		StorageFieldTransit $transit,
		ActivityChangeSet $changes,
		ActivityOperationContext $context
	): void;

	/**
	 * Disposes of the projection of an activity being deleted.
	 *
	 * Told what the activity was, because a provider decides by its fields whether the projection goes at
	 * all: the one of generated documents removes the document only while the recycle bin is being emptied.
	 */
	public function deleteAssociatedEntity(
		int $associatedEntityId,
		Activity $activity,
		ActivityOperationContext $context
	): void;

	/**
	 * Points the projection at the record the activity has been merged into.
	 */
	public function rebindAssociatedEntity(int $associatedEntityId, ItemId $from, ItemId $to): void;
}
