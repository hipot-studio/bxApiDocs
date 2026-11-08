<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete;

use Bitrix\Crm\Activity\LastCommunication\SyncLastCommunication;
use Bitrix\Crm\Activity\Provider\ProviderManager;
use Bitrix\Crm\Integration\StorageType;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Timeline\Monitor;
use Bitrix\Crm\V2\Internal\Access\Activity\ActivityAccess;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Integration\Disk\ActivityStorageElements;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityBindingRepository;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityCommunicationRepository;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepository;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityStorageElementRepository;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CCrmActivity;
use CCrmEntityHelper;
use Closure;
use Throwable;

/**
 * Erases the activity: row 8 of the owner table STEP-DELETE (crm_activity.php:1362-1365, `InnerDelete` :1532-1578).
 *
 * THE ORDER INSIDE THE ROW IS THE ORDER OF LEGACY, and it is one row because legacy has one method: what the reader
 * of an activity remembers is forgotten first, then the bindings go, then the communications, then the row itself.
 * Each of the three writes is refused by its own writer and the refusal short-circuits the rest, exactly as the chain
 * of the legacy booleans does - a set of bindings that could not be dropped leaves the row where it was rather than
 * leaving an activity with no bindings.
 *
 * THE ONE ROW OF THE LOWER HALF THAT INTERRUPTS. Legacy answers false and performs none of the fourteen rows below,
 * so a delete that could not erase the row is no delete: no history, no statistics, no hook of the provider, no event
 * of a deleted activity. This is the third and last interrupting row of the pipeline of a delete.
 *
 * THE FILES ARE SKIPPED WHERE THE ACTIVITY WENT TO THE RECYCLE BIN, AND ERASED WHERE IT DID NOT. The bin stores the
 * files in the slot it would restore the activity from and clears the rows listing them itself, so erasing them after
 * a transfer would destroy what a restoration is made of - which is why legacy forces the skip there
 * (crm_activity.php:1363-1366, :1859). That force is the one half; the other is the demand of the caller
 * ({@see \Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext::shouldSkipFiles()}), and neither of them
 * covers the other. The registry row RECYCLE-ALWAYS-ON says the setting of the module cannot switch the bin off; it
 * says nothing about a portal WITHOUT the module of the bin and nothing about a transfer that FAILED, and on both of
 * those the activity is erased while nobody owns its files - so the branch is reproduced rather than forced away.
 *
 * THE FILES AND THE ROWS LISTING THEM ARE ERASED BY V2, EACH BY WHOEVER OWNS THEM. What may be removed from a storage
 * at all - which storage it is, who else attached the file, the module of the disk being installed - belongs to the
 * integration layer of the disk ({@see ActivityStorageElements::deleteElements()}), and `b_crm_act_elem` is a table of
 * CRM, so the writer of its rows takes them away ({@see ActivityStorageElementRepository::deleteByActivityId()}).
 * Legacy performs both from one method (`DeleteStorageElements()`, crm_activity.php:2582-2619, which ends in
 * `DoDeleteElementIDs()`), which is why the second statement it writes right below the call (:1865-1868) removes
 * nothing that is still there.
 *
 * WHICH FILES ARE ERASED IS WHAT THE STATE OF THE RUN SAYS. Legacy reads the two storage columns off the row it was
 * handed and off the storage where it was handed none (:2591-2613). The storage type is the same answer in both cases
 * without asking twice - row 2 puts a handed-over row into the transit and reads the stored one where there is none -
 * and the identifiers are taken the same way round: from the row of the caller, or from the writer of the rows of
 * `b_crm_act_elem`, which is the reader row 5 asks for this very list. The rows and the serialized column legacy reads
 * are written together and never apart, and legacy rebuilds the column out of the rows wherever it holds anything but
 * a list (`PrepareStorageElementIDs()`, :4610-4633).
 *
 * THE REMOVAL OF THE FILES HAS NO REFUSAL OF ITS OWN. Legacy answers false there in two states: a non-positive id,
 * which this step refuses above with {@see self::ERROR_NO_ID}, and "nothing is stored under that id while the caller
 * handed no row over", which row 2 refuses the whole run over. Neither is reachable this far down - row 5 is the only
 * row between them that can take the stored row away, and it raises the skip below - so what is left of a failure is
 * a throw, answered like every other throw of this row.
 *
 * WHAT DEPENDS ON THE SET OF BINDINGS IS RECOMPUTED BY THE LISTENER OF THE WRITE, not by this step: the storage
 * boundary reports the removal and the effects legacy performs from inside its own `DeleteBindings` - the badges of
 * the objects, the monitor of the timeline, the index of the last communication - are performed there. They are the
 * removing half of the listener of the step writing a set ({@see
 * \Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\SaveBindings}), because a deletion has no adding
 * half.
 *
 * AND WHAT WAS DECIDED ABOUT THE ACTIVITY IS FORGOTTEN ONCE ITS ROW IS GONE (open question Q-4). The inputs of a
 * decision about rights are the row and the set of bindings, and an erasure takes away both at once, so every
 * decision of this hit about this activity - and the model of the row those decisions were read from - describes
 * an activity that no longer exists. The step writing the set and the write boundary of an update say the same
 * thing about the halves they move; this is the erasure saying it about the whole. Nothing asks about the rights
 * of a deleted activity today, which is why this is an invariant of the memory rather than an observable effect:
 * the memory forgets what changed, without a caller having to know which change it was.
 *
 * THE STEP HAS NO SEAM, and that follows from the listener exactly as it does for the step writing a set: a storage
 * boundary handed in from outside would arrive without the one thing that makes the removal observable. What is
 * erased and what is recomputed is visible in the storage itself, which is where the expectations look.
 *
 * @internal
 */
final class InnerDelete implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. Legacy
	 * registers "Invalid arguments are supplied." for the same state and answers false.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	/**
	 * The activity could not be erased. The reason travels in the custom data under
	 * {@see self::ERROR_DETAIL_REASON} - out of the way of whoever shows an error to a user, at hand for whoever
	 * reports the failure.
	 */
	public const ERROR_NOT_DELETED = 'CRM_ACTIVITY_NOT_DELETED';

	public const ERROR_DETAIL_REASON = 'reason';

	private const COLUMN_STORAGE_TYPE_ID = 'STORAGE_TYPE_ID';
	private const COLUMN_STORAGE_ELEMENT_IDS = 'STORAGE_ELEMENT_IDS';

	public function getName(): string
	{
		return 'InnerDelete';
	}

	/**
	 * Every delete that got this far erases its activity. Legacy has no path around this.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return $result->addError(new Error('The activity has no id.', self::ERROR_NO_ID));
		}

		CCrmEntityHelper::RemoveCached(CCrmActivity::CACHE_NAME, $activityId);

		$operationContext = $context->getOperationContext();

		try
		{
			if (!$operationContext->shouldSkipBindings())
			{
				$this->repositoryOfTheBindings()->deleteByActivityId($activityId);
			}

			if (!$operationContext->shouldSkipCommunications())
			{
				(new ActivityCommunicationRepository())->deleteByActivityId($activityId);
			}

			if (!self::skipsTheFiles($context))
			{
				$this->eraseTheFilesOf($context, $activityId);
			}

			(new ActivityRepository())->deleteById($activityId);
		}
		catch (Throwable $error)
		{
			return $result->addError(new Error(
				"Could not delete CrmActivity(ID = {$activityId})",
				self::ERROR_NOT_DELETED,
				[self::ERROR_DETAIL_REASON => $error->getMessage()],
			));
		}

		$this->forgetWhatWasDecidedAboutTheActivity($activityId);

		return $result;
	}

	/**
	 * A delete that could not erase the activity performs nothing below. See the note of the class.
	 */
	public function isAbortingOnFailure(): bool
	{
		return true;
	}

	/**
	 * Whether the files of the activity survive this delete: because it went to the recycle bin, which owns them from
	 * now on, or because the caller asked for it. See the note of the class.
	 */
	private static function skipsTheFiles(PipelineContext $context): bool
	{
		return $context->hasGoneToTheRecycleBin() || $context->getOperationContext()->shouldSkipFiles();
	}

	/**
	 * Takes away the files of the activity and the rows listing them, each through whoever owns it. See the note of
	 * the class.
	 */
	private function eraseTheFilesOf(PipelineContext $context, int $activityId): void
	{
		$rowsOfTheFiles = new ActivityStorageElementRepository();

		(new ActivityStorageElements())->deleteElements(
			self::storageTypeOf($context),
			self::elementIdsOf($context, $activityId, $rowsOfTheFiles),
		);

		$rowsOfTheFiles->deleteByActivityId($activityId);
	}

	/**
	 * The storage the files of the activity live in. A state naming none names the undefined storage, which is what
	 * legacy reads of a field array without the column, and no file is removed from it.
	 */
	private static function storageTypeOf(PipelineContext $context): int
	{
		$transit = $context->getTransit();

		return $transit->has(self::COLUMN_STORAGE_TYPE_ID)
			? (int)$transit->get(self::COLUMN_STORAGE_TYPE_ID)
			: StorageType::Undefined
		;
	}

	/**
	 * The files of the activity: the ones the row of the caller names, or the ones their writer holds. See the note
	 * of the class.
	 *
	 * @return int[]
	 */
	private static function elementIdsOf(
		PipelineContext $context,
		int $activityId,
		ActivityStorageElementRepository $rowsOfTheFiles,
	): array
	{
		$givenRow = $context->getOperationContext()->getActualItem();
		if ($givenRow === null)
		{
			return $rowsOfTheFiles->getElementIds($activityId);
		}

		// A column of a legacy row carrying anything but a list is answered out of the rows, the way legacy
		// rebuilds it out of them - the one part of `PrepareStorageElementIDs()` left out being the column it
		// writes back, which the row of the activity does not outlive this step to keep. Two shapes legacy does
		// not rebuild are answered differently; see the note below.
		return self::elementIdsNamedBy($givenRow) ?? $rowsOfTheFiles->getElementIds($activityId);
	}

	/**
	 * The files a legacy row names, read under the two keys legacy reads them under and in its order of preference:
	 * a list already unpacked, the raw column, the column of the caller. Null means the row names no list of its
	 * own and the rows of the activity are asked instead.
	 *
	 * TWO SHAPES ARE ANSWERED DIFFERENTLY FROM LEGACY, and a stored row carries neither: the column holds a
	 * serialized list, and legacy rebuilds it out of the rows wherever it holds anything else. A number in the
	 * column is an identifier of an ACTIVITY to legacy and not of a file (`PrepareStorageElementIDs()`,
	 * crm_activity.php:4618-4641): a positive one makes it read the elements of THAT activity and write the
	 * rebuilt column back onto it, so it answers with the files of somebody else's activity and edits that
	 * activity on the way; a non-positive one falls back to the identifier of the row itself, which is what
	 * happens here for every number - the list is always the one of the current activity. A string that is no
	 * serialization leaves legacy with a false it carries down to an argument typed as an array, and here it is
	 * an empty list.
	 *
	 * @param array<string, mixed> $row
	 * @return int[]|null
	 */
	private static function elementIdsNamedBy(array $row): ?array
	{
		$named = $row[self::COLUMN_STORAGE_ELEMENT_IDS] ?? null;
		if (!is_array($named))
		{
			$named = $row['~' . self::COLUMN_STORAGE_ELEMENT_IDS] ?? $named;
		}

		if (is_array($named))
		{
			return array_map('intval', $named);
		}

		if (is_numeric($named))
		{
			return null;
		}

		if (is_string($named) && $named !== '')
		{
			$unpacked = unserialize($named, ['allowed_classes' => false]);

			return is_array($unpacked) ? array_map('intval', $unpacked) : [];
		}

		return [];
	}

	/**
	 * Drops every decision of this hit about the activity, together with the row and the bindings those decisions
	 * were derived from (open question Q-4). See the note of the class.
	 *
	 * The access model of the module, named rather than reached for through a getter alone: the guard of the
	 * rollout of the new rights ([P3.T6]) lists the files referring to it by name.
	 */
	private function forgetWhatWasDecidedAboutTheActivity(int $activityId): void
	{
		$this->accessModel()->forgetActivity($activityId);
	}

	/**
	 * The one instance of the hit is what has to forget - it is the same object the rest of the hit asks.
	 */
	private function accessModel(): ActivityAccess
	{
		return Container::getInstance()->getActivityAccess();
	}

	private function repositoryOfTheBindings(): ActivityBindingRepository
	{
		return new ActivityBindingRepository(onBindingsChanged: self::listenerOfTheRemoval());
	}

	/**
	 * `function (int $activityId, ActivityBinding[] $added, ActivityBinding[] $removed): void` - called by the
	 * storage boundary once per removal. Nothing is ever added by a deletion, which is why the added subset is not
	 * read.
	 */
	private static function listenerOfTheRemoval(): Closure
	{
		return static function (int $activityId, array $added, array $removed): void {
			ProviderManager::syncBadgesOnBindingsChange($activityId, [], self::legacyFormOf($removed));

			$monitor = Monitor::getInstance();
			$lastCommunication = new SyncLastCommunication();

			foreach ($removed as $binding)
			{
				$identifier = self::identifierOf($binding);
				if ($identifier === null)
				{
					continue;
				}

				$monitor->onActivityRemoveIfSuitable($identifier, $activityId);
				$lastCommunication->onActivityRemoveIfSuitable($identifier, $activityId);
			}
		};
	}

	/**
	 * The shape every legacy consumer of a binding reads: the pair of owner columns of a row.
	 *
	 * @param ActivityBinding[] $bindings
	 * @return array<int, array<string, int>>
	 */
	private static function legacyFormOf(array $bindings): array
	{
		$rows = [];
		foreach ($bindings as $binding)
		{
			$rows[] = [
				'OWNER_TYPE_ID' => $binding->getOwnerType()->getId(),
				'OWNER_ID' => $binding->getOwnerId(),
			];
		}

		return $rows;
	}

	/**
	 * The owner of a binding as an item identifier, or null for an owner no identifier can be made of. Rows of
	 * `b_crm_act_bind` carry owner types no longer defined, and legacy skips exactly those bindings here rather
	 * than failing over them (`ItemIdentifier::createFromArray()`, mysql/crm_activity.php:146-168). Failing over
	 * one here would be worse than on a write: the bindings and the communications are already gone by the time
	 * this listener runs and the row of the activity is not, so the run would end with an activity bound to
	 * nothing - a state legacy never leaves behind.
	 */
	private static function identifierOf(ActivityBinding $binding): ?ItemIdentifier
	{
		return ItemIdentifier::createByParams($binding->getOwnerType()->getId(), $binding->getOwnerId());
	}
}
