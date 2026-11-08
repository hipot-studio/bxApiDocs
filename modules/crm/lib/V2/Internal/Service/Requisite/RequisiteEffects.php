<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Requisite;

use Bitrix\Crm\Integrity\DuplicateBankDetailCriterion;
use Bitrix\Crm\Integrity\DuplicateRequisiteCriterion;
use Bitrix\Crm\Integrity\DuplicateVolatileCriterion;
use Bitrix\Crm\Integrity\Volatile\FieldCategory;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\Event;

/**
 * What happens around a written requisite once the row itself is settled: the duplicate index of its
 * owner, the volatile criterion over the same fields, the duplicate index of the bank details when the
 * requisite changed hands, the tagged cache the selector reads its requisites from, and the event of the
 * domain - in that order, which is the order they have always run in.
 *
 * **The order is kept and so is the moment: right after the write.** Nothing wraps a write of a requisite
 * in a transaction, here or above ({@see RequisiteWriteService}), so there is no commit for these to run
 * behind. What the published operations changed is not when the effects run but whether they run at all:
 * they run for a write that went through and for no other, while legacy drops the tagged cache of the
 * selector whether the storage accepted the write or not ({@see self::dropSelectorCacheOfRequisite()}).
 * The one place they are held back at all is the compatibility line inside a write of an address, which
 * keeps them until its own transaction is over
 * ({@see RequisiteWriteService::addForLegacyFacade()}).
 *
 * **What is not here.** The nested addresses of a requisite, the user fields of the portal and the files
 * of a signature and a stamp are written and erased by the code that owns them, and that code publishes
 * its own events and drops its own caches where it always has. Moving those here would change the
 * behaviour of neighbouring areas, so they are left exactly where they are; the effects of this class are
 * the ones the write of a requisite owns.
 *
 * **None of it is undoable, and a refusal of any step is let out.** The criteria and the tagged cache are
 * no part of a write anything could undo, and the handlers of the event belong to whoever wrote them, so
 * there is nothing to roll back and nothing catches a throw: the caller of the write gets the exception
 * and the row stays as the write left it. That is what legacy does with the very same steps, and why the
 * published operations of {@see RequisiteWriteService} keep doing it rather than turning a written row
 * into a refusal.
 *
 * @internal
 */
final class RequisiteEffects
{
	private const EVENT_MODULE_ID = 'crm';

	public const EVENT_ADD = 'OnAfterRequisiteAdd';

	public const EVENT_UPDATE = 'OnAfterRequisiteUpdate';

	public const EVENT_DELETE = 'OnAfterRequisiteDelete';

	/**
	 * @param int $ownerTypeId the owner as the creation named it, which is not necessarily one a requisite
	 *        may belong to: legacy writes requisites of a lead and of a deal, and requisites with no owner
	 *        at all, and those get the event and nothing else - exactly as they do today.
	 * @param array<string, mixed> $eventFields the payload of the event, see {@see self::publish()}.
	 */
	public function afterAdd(int $ownerTypeId, int $ownerId, int $id, array $eventFields): void
	{
		if (self::isRequisiteOwner($ownerTypeId, $ownerId))
		{
			DuplicateRequisiteCriterion::registerByEntity($ownerTypeId, $ownerId);
			DuplicateVolatileCriterion::register($ownerTypeId, $ownerId, [FieldCategory::REQUISITE]);

			\CCrmEntitySelectorHelper::clearPrepareRequisiteDataCacheByEntity($ownerTypeId, $ownerId);
		}

		$this->publish(self::EVENT_ADD, ['id' => $id, 'fields' => $eventFields]);
	}

	/**
	 * The owner is taken twice, and the two are not the same thing. $ownerTypeId and $ownerId are the
	 * owner the row had before the write - read before it, because afterwards the row may name another. The two named ones are what the write itself said about the owner, and `null`
	 * means it said nothing: the published operations never let it, so on that line both are always
	 * `null`, while the facade line moves requisites between owners and states them.
	 *
	 * A move is what the bank details of the requisite are reindexed for, and the only observable
	 * difference between the two branches below: without one, the criterion of the bank details is not
	 * touched at all. Which branch runs depends on whether the write named the owner in full rather than
	 * on whether it changed it - naming the same owner again takes the first branch, and the criterion of
	 * the requisite ends up rebuilt from the owner either way, because the second branch reads the owner
	 * back out of the row it just wrote.
	 *
	 * @param array<string, mixed> $eventFields the payload of the event, see {@see self::publish()}.
	 */
	public function afterUpdate(
		int $id,
		int $ownerTypeId,
		int $ownerId,
		?int $namedOwnerTypeId,
		?int $namedOwnerId,
		array $eventFields,
	): void
	{
		$ownerKnown = \CCrmOwnerType::IsDefined($ownerTypeId) && $ownerId > 0;
		$movedTypeId = OwnerType::UNDEFINED;
		$movedId = 0;
		$typeMoved = false;
		$idMoved = false;

		if ($namedOwnerTypeId !== null)
		{
			$movedTypeId = $namedOwnerTypeId;
			if ($ownerKnown)
			{
				$typeMoved = $namedOwnerTypeId !== $ownerTypeId;
				$movedId = $ownerId;
			}
		}

		if ($namedOwnerId !== null)
		{
			$movedId = $namedOwnerId;
			if ($ownerKnown)
			{
				$idMoved = $namedOwnerId !== $ownerId;
				if (!$typeMoved)
				{
					$movedTypeId = $ownerTypeId;
				}
			}
		}

		$moved = $typeMoved || $idMoved;
		if ($moved)
		{
			DuplicateRequisiteCriterion::registerByEntity($ownerTypeId, $ownerId);
			DuplicateBankDetailCriterion::registerByEntity($ownerTypeId, $ownerId);
			DuplicateRequisiteCriterion::unregister($movedTypeId, $movedId);

			$bothCategories = [FieldCategory::REQUISITE, FieldCategory::BANK_DETAIL];
			DuplicateVolatileCriterion::register($ownerTypeId, $ownerId, $bothCategories);
			DuplicateVolatileCriterion::register($movedTypeId, $movedId, $bothCategories);
		}

		if ($namedOwnerTypeId !== null && $namedOwnerId !== null)
		{
			DuplicateRequisiteCriterion::registerByEntity($namedOwnerTypeId, $namedOwnerId);
			DuplicateVolatileCriterion::register($namedOwnerTypeId, $namedOwnerId, [FieldCategory::REQUISITE]);

			if ($moved)
			{
				DuplicateBankDetailCriterion::registerByEntity($namedOwnerTypeId, $namedOwnerId);
				DuplicateVolatileCriterion::register(
					$namedOwnerTypeId,
					$namedOwnerId,
					[FieldCategory::BANK_DETAIL],
				);
			}
		}
		else
		{
			DuplicateRequisiteCriterion::registerByRequisite($id);
			DuplicateVolatileCriterion::register(OwnerType::REQUISITE, $id, [FieldCategory::REQUISITE]);

			if ($moved)
			{
				DuplicateBankDetailCriterion::registerByParent(OwnerType::REQUISITE, $id);
				DuplicateVolatileCriterion::register(OwnerType::REQUISITE, $id, [FieldCategory::BANK_DETAIL]);
			}
		}

		$this->dropSelectorCacheOfRequisite($id);

		$this->publish(self::EVENT_UPDATE, ['id' => $id, 'fields' => $eventFields]);
	}

	/**
	 * The owner is stated rather than looked up: the criterion of the requisite resolves an owner by
	 * reading the row of the requisite ({@see DuplicateRequisiteCriterion::registerByRequisite()}), and
	 * after a deletion there is no row to read. So the owner is the one taken off the row before it went,
	 * and the deletion rebuilds the index of that owner from the requisites it has left.
	 *
	 * @param int|float|string $id the identifier as the caller of the deletion stated it. The published
	 *        operation states an integer; the compatibility line states whatever legacy was handed, which
	 *        casts nowhere and lets a fractional identifier through to the storage and into the payload of
	 *        the event.
	 */
	public function afterDelete(int|float|string $id, int $ownerTypeId, int $ownerId): void
	{
		if (self::isRequisiteOwner($ownerTypeId, $ownerId))
		{
			DuplicateRequisiteCriterion::registerByEntity($ownerTypeId, $ownerId);
			DuplicateVolatileCriterion::register($ownerTypeId, $ownerId, [FieldCategory::REQUISITE]);
		}

		$this->dropSelectorCacheOfRequisite((int)$id);

		$this->publish(self::EVENT_DELETE, ['id' => $id]);
	}

	/**
	 * Drops the tagged cache the selector reads the requisites of a record from, and nothing else.
	 *
	 * On its own rather than only as a step of an effect because of one thing the compatibility line has to
	 * keep doing: legacy drops this cache whether or not the storage accepted the write, which is a
	 * difference in the safe direction and one its consumers have been relying on for as long as they have
	 * existed. The published operations drop it after the write and only when it went through.
	 */
	public function dropSelectorCacheOfRequisite(int $id): void
	{
		\CCrmEntitySelectorHelper::clearPrepareRequisiteDataCacheByEntity(OwnerType::REQUISITE, $id);
	}

	/**
	 * The event of the domain, published last of the effects and only for a write that went through.
	 *
	 * Three events, none of them renamed and none of them carrying a parameter more or less than it
	 * carries today. Nothing in the repository subscribes to any of them; their one consumer is the old
	 * REST, which turns each into an outgoing webhook that carries the identifier alone. So the shape of
	 * `fields` is an internal contract with no reader - which is a reason to leave it exactly as it is,
	 * not a licence to change it.
	 *
	 * That is also why the payload is stated by the caller instead of being derived here from the set of
	 * fields that was written: the payload of today is the array the caller handed over, in the names of
	 * the columns, in the order it named them, and including keys the entity does not know at all. A typed
	 * set cannot hold the last of those, and the facade line has to keep sending them.
	 *
	 * @param array<string, mixed> $parameters
	 */
	private function publish(string $eventName, array $parameters): void
	{
		(new Event(self::EVENT_MODULE_ID, $eventName, $parameters))->send();
	}

	/**
	 * Whether the criteria and the cache of an owner apply at all: they are built per contact and per
	 * company, and a requisite of anything else - or of nothing - has no owner to rebuild them for.
	 */
	private static function isRequisiteOwner(int $ownerTypeId, int $ownerId): bool
	{
		return
			($ownerTypeId === OwnerType::CONTACT || $ownerTypeId === OwnerType::COMPANY)
			&& $ownerId > 0
		;
	}
}
