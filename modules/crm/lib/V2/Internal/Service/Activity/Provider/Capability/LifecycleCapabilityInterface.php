<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityChangeSet;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext;
use Bitrix\Main\Result;

/**
 * What a provider does after an activity of its kind has been written, deleted, binned or restored.
 *
 * THESE HOOKS DO NOT VETO. Whatever they answer, the operation has already happened: the void ones are
 * announcements, and even the check before a completion cannot stop it - legacy called it and dropped the
 * result, and a refusal there would turn a rule of the provider into a right, which belongs to
 * {@see PermissionCapabilityInterface} instead.
 *
 * TWO OF THEM DECIDE MORE THAN THEY REPORT, and that is why they return a result. When
 * {@see self::processMovingToRecycleBin()} says the row is already gone - the data key `isDeleted` of legacy,
 * used by the two providers of tasks, which let the module of tasks remove the projection itself - the delete
 * stops right there instead of running its remaining steps. When
 * {@see self::processRestorationFromRecycleBin()} names an identifier - the data key `entityId` - the
 * controller of the recycle bin does not create the activity itself, because the provider already has. Losing
 * either key does not fail loudly: it duplicates a row or deletes one twice.
 *
 * THE CONTEXT IS PASSED WHERE LEGACY PASSED NOTHING. An update and a delete announced themselves with the
 * fields alone, and an implementation over a legacy provider therefore hands no options on: the argument is
 * room for the providers that will be written natively, not a change of what the existing ones see.
 *
 * Normative shape: TPL-03. The hooks, their call sites and who overrides them: LBS-2 sections 2.4 and 4.2,
 * invariants 7 and 8 of section 7, checklist items C3 and C4.
 *
 * @internal
 */
interface LifecycleCapabilityInterface
{
	/**
	 * THE STORAGE COLUMNS OF THE STATE OF THE RUN TRAVEL WITH THE ACTIVITY, because the model holds none of them and
	 * the input of the operation holds only what the caller named: an owner a step derived from the bindings, or the
	 * storage type it defaulted, is in the run alone - and legacy hands its hook the field array those values were
	 * written into (crm_activity.php:104-109, :467). Optional, so that a provider with no interest in it declares two.
	 *
	 * @param StorageFieldTransit|null $transit the storage columns of the state of the run after the write, or null
	 *        where the caller of the hook holds no such state.
	 */
	public function onAfterAdd(
		Activity $activity,
		ActivityOperationContext $context,
		?StorageFieldTransit $transit = null,
	): void;

	/**
	 * Announced with the activity as well as with what changed: the set of changes names ten fields and the
	 * activity is not one of them, so it carries neither the identifier a reacting provider needs nor the
	 * state being written. One of them resynchronizes a task of the module of tasks by both sides of the
	 * change, and the legacy call passed the identifier separately for that reason.
	 *
	 * FOUR ARGUMENTS BECAUSE AN UPDATE HAS TWO STATES OF ITS OWN. The first one is the state of the run, which
	 * past the re-read of an update is the row as it now stands; the last one is the state the operation WROTE,
	 * and no other argument can answer for it - a re-read model marks no field as changed, so a projection over
	 * it cannot tell a field the update moved from a column it never touched. Legacy announces exactly that
	 * difference, handing its providers the array it wrote next to the two snapshots
	 * (crm_activity.php:1216-1221), and the subscribers of the event below the hook are handed that same array
	 * ({@see \Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update\PublishOnActivityUpdate}).
	 *
	 * The argument is optional so that a provider with no interest in it declares three.
	 *
	 * THE STATE BEFORE THE UPDATE TRAVELS NEXT TO IT, for the same reason legacy hands it over as a snapshot of
	 * its own: the set of changes holds only what moved, and a reader deciding by a value the update left alone -
	 * whether a call had its record already - finds nothing there. It is the model and the storage columns the
	 * update loaded before its write, files included; both are optional for the same reason as the input.
	 *
	 * THE STORAGE COLUMNS OF THE STATE OF THE RUN TRAVEL TOO, because the model of the first argument holds none
	 * of them: they are the columns as the run holds them after its write - the ones a step of the run resolved,
	 * the owner derived from the bindings among them, and the re-read row for the rest - which is what legacy
	 * reads off the snapshot it re-read. Optional for the same reason as the others.
	 *
	 * @param Activity|null $inputActivity the activity as the operation named and wrote it, or null where the
	 *        caller of the hook holds no such state.
	 * @param Activity|null $previousActivity the activity as it was stored before the update, or null where the
	 *        caller of the hook holds no such state.
	 * @param StorageFieldTransit|null $previousTransit the storage columns of that same state.
	 * @param StorageFieldTransit|null $transit the storage columns of the state of the run after the write, or
	 *        null where the caller of the hook holds no such state.
	 */
	public function onAfterUpdate(
		Activity $activity,
		ActivityChangeSet $changes,
		ActivityOperationContext $context,
		?Activity $inputActivity = null,
		?Activity $previousActivity = null,
		?StorageFieldTransit $previousTransit = null,
		?StorageFieldTransit $transit = null,
	): void;

	public function onAfterDelete(Activity $activity, ActivityOperationContext $context): void;

	/**
	 * Called before an activity is completed, and it has no say in whether it will be. Skipped when the
	 * caller asked for it to be.
	 */
	public function onBeforeComplete(Activity $activity, ActivityOperationContext $context): void;

	/**
	 * @return Result its data may carry `isDeleted`, which ends the delete at once.
	 */
	public function processMovingToRecycleBin(Activity $activity, ActivityOperationContext $context): Result;

	/**
	 * @return Result its data may carry `entityId` of an activity the provider has restored on its own, and
	 *                then the recycle bin creates nothing.
	 */
	public function processRestorationFromRecycleBin(Activity $activity, ActivityOperationContext $context): Result;

	public function onAfterRecycleBinErase(Activity $activity, ActivityOperationContext $context): void;
}
