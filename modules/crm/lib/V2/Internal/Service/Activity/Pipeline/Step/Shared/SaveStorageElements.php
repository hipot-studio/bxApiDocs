<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared;

use Bitrix\Crm\Integration\StorageType;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityStorageElement;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityStorageElementRepository;
use Bitrix\Crm\V2\Internal\Service\Activity\ActivityLifecycleService;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CCrmActivity;

/**
 * Stores which files are attached to the activity: step 14 of the owner table of an add
 * (crm_activity.php:237), step 16 of the owner table of an update (:715-718).
 *
 * TWO PLACES HOLD ONE SET, and keeping them in agreement belongs to the storage layer
 * ({@see ActivityStorageElementRepository}): the rows of the reverse index "file to activities" and the
 * serialized list in the column of the activity itself. No file is touched here or there - whether a file may be
 * removed at all depends on the storage it lives in and on who else attached it, and that question belongs to the
 * pipeline of a delete.
 *
 * THE STORAGE IS ONE PER ACTIVITY AND IT IS NOT A FIELD OF THE MODEL, so it travels as a column of the transit
 * and is read from there. On an add the step above has already filled it in - a caller who named no storage gets
 * the default of the portal (row 2 of the owner table) - so the fallback below is unreachable on this path and is
 * there because the transit answers "no such column" with an exception rather than with a value.
 *
 * ONE CLASS, TWO OPERATIONS, AND THE STORAGE IS WHERE THEY DIFFER. The gate reads the same in both pipelines - a
 * set of files that was given, which a typed model answers with anything other than null - but the answer to
 * "which storage" does not. An add has one source and it is the transit. An update has three, tried in order
 * (:702-706): the storage the caller named, then the storage the activity is stored under, and only for an
 * activity that has none the default of the portal - and unlike the add, the default it falls back to is the
 * personal one of the user (`CCrmActivity::GetDefaultStorageTypeID()`), which on the path of an add is
 * unreachable. Without that chain the rows of an update would go under a storage of zero, which is why the mode
 * is a parameter of the step rather than something derived from the run.
 *
 * @internal
 */
final class SaveStorageElements implements PipelineStepInterface
{
	/**
	 * The run of an update reached this step without the state the activity was in, which only happens when the
	 * step reading that state is missing from the declaration or has failed. Which storage the files of the
	 * activity live in is a question about that state, so there is nothing to write without it.
	 */
	public const ERROR_PREVIOUS_STATE_NOT_LOADED = 'CRM_ACTIVITY_PREVIOUS_STATE_NOT_LOADED';

	private const COLUMN_STORAGE_TYPE_ID = 'STORAGE_TYPE_ID';

	private const OPERATIONS = [
		ActivityLifecycleService::OPERATION_ADD,
		ActivityLifecycleService::OPERATION_UPDATE,
	];

	private readonly ActivityStorageElementRepository $repository;

	/**
	 * @param string $operation which of the pipelines this instance belongs to, named by whoever declares it.
	 * @param ActivityStorageElementRepository|null $repository the storage boundary of the elements; the one
	 *        seam of the step.
	 * @throws ArgumentException on an operation this step has no storage of its own for yet.
	 */
	public function __construct(
		private readonly string $operation,
		?ActivityStorageElementRepository $repository = null,
	)
	{
		if (!in_array($operation, self::OPERATIONS, true))
		{
			throw new ArgumentException(
				"The '{$operation}' operation over an activity has no write of the storage elements of its own"
				. ' yet',
				'operation',
			);
		}

		$this->repository = $repository ?? new ActivityStorageElementRepository();
	}

	public function getName(): string
	{
		return 'SaveStorageElements';
	}

	/**
	 * Only where the caller said something about the files, and only for an activity whose rows can name it.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		if ($context->getActivityId() <= 0)
		{
			return false;
		}

		return $context->getActivity()->getFiles() !== null;
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		$storageTypeId = $this->storageTypeOf($context);
		if ($storageTypeId === null)
		{
			return $result->addError(new Error(
				'The previous state of the activity was not loaded.',
				self::ERROR_PREVIOUS_STATE_NOT_LOADED,
			));
		}

		$this->repository->save(
			$context->getActivityId(),
			$storageTypeId,
			self::elementIdsOf($context),
		);

		return $result;
	}

	/**
	 * Files that could not be stored do not end an add: legacy checks the answer of that write nowhere.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	private function isUpdate(): bool
	{
		return $this->operation === ActivityLifecycleService::OPERATION_UPDATE;
	}

	/**
	 * @return int|null the storage the elements are written under, or null when an update cannot tell - which is
	 *         a broken declaration rather than an answer, and nothing is written for it.
	 */
	private function storageTypeOf(PipelineContext $context): ?int
	{
		$named = self::storageTypeIn($context->getTransit());

		if (!$this->isUpdate())
		{
			return $named ?? StorageType::Undefined;
		}

		// An update names a storage the way legacy does, and a storage of "undefined" is the same as none: the
		// two are one branch there (:703).
		if ($named !== null && $named !== StorageType::Undefined)
		{
			return $named;
		}

		if (!$context->hasPreviousState())
		{
			return null;
		}

		$stored = self::storageTypeIn($context->getPreviousTransit());

		// A stored "undefined" is read here as no storage at all, and legacy tells the two apart: its re-read is a
		// plain fetch, so a column holding NULL has no key and falls back to the default while a column holding a
		// zero is taken as a zero (:705). The typed boundary cannot reproduce that - an integer column of the ORM
		// hands a NULL back as a zero - and of the two spellings of "this activity has no storage" the one legacy
		// really produces is NULL: its own add path fills the column in for everybody who did not name it. So the
		// zero follows the NULL rather than the other way round, and the elements of an activity without a storage
		// are written under the default instead of under a storage nothing can read a file from.
		if ($stored === null || $stored === StorageType::Undefined)
		{
			return (int)CCrmActivity::GetDefaultStorageTypeID();
		}

		return $stored;
	}

	/**
	 * @return int|null the column, or null where it is not carried at all or carried as null - which is what
	 *         legacy asks it with `isset()`.
	 */
	private static function storageTypeIn(StorageFieldTransit $transit): ?int
	{
		if (!$transit->has(self::COLUMN_STORAGE_TYPE_ID))
		{
			return null;
		}

		$value = $transit->get(self::COLUMN_STORAGE_TYPE_ID);

		return $value === null ? null : (int)$value;
	}

	/**
	 * @return int[]
	 */
	private static function elementIdsOf(PipelineContext $context): array
	{
		return array_map(
			static fn(ActivityStorageElement $element): int => $element->getElementId(),
			$context->getActivity()->getFiles() ?? [],
		);
	}
}
