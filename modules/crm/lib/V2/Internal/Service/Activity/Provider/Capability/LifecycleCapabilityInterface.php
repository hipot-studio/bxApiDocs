<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityChangeSet;
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
	public function onAfterAdd(Activity $activity, ActivityOperationContext $context): void;

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
	 * @param Activity|null $inputActivity the activity as the operation named and wrote it, or null where the
	 *        caller of the hook holds no such state.
	 */
	public function onAfterUpdate(
		Activity $activity,
		ActivityChangeSet $changes,
		ActivityOperationContext $context,
		?Activity $inputActivity = null
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
