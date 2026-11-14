<?php

declare(strict_types=1);

namespace Bitrix\Disk\Destination;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Folder;
use Bitrix\Main\Application;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Runs the chosen operation through the object model.
 *
 * The verdict of the pre-check is not a permission: rights, tariff and free space are resolved again here,
 * because the action is an open surface and the state may have changed since the popup asked.
 */
class OperationExecutor
{
	public const ERROR_NAME_CONFLICT = 'name_conflict';
	public const ERROR_OPERATION_FAILED = 'operation_failed';

	/**
	 * The object model has no way to carry this object into the chosen folder
	 * (@see VerdictResolver::isOutOfReachAsLink). Shares the value with the code the controller gives for an
	 * object it could not load at all: both say the operation has nothing to work on, and the client draws
	 * one card for both.
	 */
	public const ERROR_OBJECT_GONE = 'object_gone';

	private const LOGGER_ID = 'disk.destination';

	/**
	 * Only a disagreement is written down, not every refusal. The two kinds are counted apart: a
	 * disagreement means the rules of the pre-check and of the execution went out of sync, a failed
	 * rollback means the operation was left unfinished and the object stayed in two places.
	 */
	private const DIAGNOSTIC_DISCREPANCY = 'Destination pre-check allowed the pair, the model refused it.';
	private const DIAGNOSTIC_ROLLBACK_FAILED = 'Copy left by the failed move was not removed.';

	/**
	 * How many refusals of a selection are asked about once more for their reason. The host names this many
	 * objects with a reason; every refusal past them is reported as a failed operation, unasked.
	 */
	public const FAILURE_REASONS_CHECKED = 5;

	private readonly VerdictResolver $verdictResolver;

	public function __construct(
		private readonly int $userId,
		?VerdictResolver $verdictResolver = null,
	)
	{
		$this->verdictResolver = $verdictResolver ?? new VerdictResolver($userId);
	}

	/**
	 * Data of a successful result holds the object in its new location under the key `object`.
	 * A denial carries a single error, its code is the reason the caller reports to the client.
	 *
	 * A link the model cannot carry is refused here rather than left to fail inside moveTo()/copyTo():
	 * a broken link throws on the way to its real object, and an unfinished operation is worse than a
	 * refusal given before it starts.
	 */
	public function execute(
		BaseObject $object,
		Folder $targetFolder,
		DestinationOperation $operation,
		NameConflictPolicy $conflictPolicy,
	): Result
	{
		$result = new Result();

		$verdict = $this->verdictResolver->resolveVerdict($object, $targetFolder, $operation);
		if (!$verdict->allowed)
		{
			return $result->addError(new Error('', (string)$verdict->reason));
		}

		//asked after the verdict: a folder the verdict has already refused is named by the reason of its own
		if ($this->verdictResolver->isOutOfReachAsLink($object, $targetFolder, $operation))
		{
			return $result->addError(new Error('', self::ERROR_OBJECT_GONE));
		}

		if ($verdict->nameConflict && $conflictPolicy === NameConflictPolicy::Report)
		{
			return $result->addError(new Error('', self::ERROR_NAME_CONFLICT));
		}

		$resultObject = $this->runOperation(
			$object,
			$targetFolder,
			$operation,
			$conflictPolicy === NameConflictPolicy::KeepBoth,
		);
		if ($resultObject === null)
		{
			$this->writeFailureDiagnostic($object, $targetFolder, $operation, $verdict->toArray());

			return $result->addError(new Error('', self::ERROR_OPERATION_FAILED));
		}

		return $result->setData(['object' => $resultObject]);
	}

	/**
	 * The verdict is asked once for the whole selection before anything is written: a denial, or a taken name
	 * under the policy that reports it, runs nothing and names the object it belongs to in the custom data
	 * under `objectId` (null for a refusal of the folder).
	 *
	 * The objects then run one by one in the order of the selection. An object the model refuses is skipped
	 * and the rest go on: what is done stays done, nothing is taken back. Data of the result holds the
	 * objects in their new location under `objects` and the refused ones under `failures`, both in the order
	 * of the selection. Only a selection nothing of which was done is an error - the error of its first
	 * refusal - so an error always means that nothing has changed.
	 *
	 * An object the model throws on is refused as well, as a failed operation without asking for a reason:
	 * by then the objects before it may already be done, and an exception would hide that from the caller.
	 */
	public function executeSelection(
		ObjectSelection $selection,
		Folder $targetFolder,
		DestinationOperation $operation,
		NameConflictPolicy $conflictPolicy,
	): Result
	{
		$result = new Result();

		$verdict = $this->verdictResolver->resolveSelectionVerdict($selection, $targetFolder, $operation);
		if (!$verdict->allowed)
		{
			return $result->addError(
				new Error('', (string)$verdict->reason, ['objectId' => $verdict->blockingObjectId]),
			);
		}

		if ($verdict->nameConflict && $conflictPolicy === NameConflictPolicy::Report)
		{
			return $result->addError(new Error('', self::ERROR_NAME_CONFLICT));
		}

		$objects = [];
		/** @var list<array{object: BaseObject, code: string}> $failures */
		$failures = [];
		foreach ($selection as $position => $object)
		{
			$selectionContext = [
				'selection' => $selection->ids(),
				'step' => $position,
			];

			try
			{
				$resultObject = $this->runOperation(
					$object,
					$targetFolder,
					$operation,
					$conflictPolicy === NameConflictPolicy::KeepBoth,
				);
			}
			catch (\Throwable $exception)
			{
				$this->writeException($exception);
				//the class only: the text of the exception may carry names of files
				$this->writeFailureDiagnostic($object, $targetFolder, $operation, $verdict->toSelectionArray(), [
					...$selectionContext,
					'exception' => $exception::class,
				]);

				$failures[] = [
					'object' => $object,
					'code' => self::ERROR_OPERATION_FAILED,
				];

				continue;
			}

			if ($resultObject !== null)
			{
				$objects[] = $resultObject;

				continue;
			}

			$this->writeFailureDiagnostic(
				$object,
				$targetFolder,
				$operation,
				$verdict->toSelectionArray(),
				$selectionContext,
			);

			$failures[] = [
				'object' => $object,
				'code' => count($failures) < self::FAILURE_REASONS_CHECKED
					? $this->resolveFailureReason($object, $targetFolder, $operation)
					: self::ERROR_OPERATION_FAILED
				,
			];
		}

		if ($objects === [])
		{
			return $result->addError(
				new Error('', $failures[0]['code'], ['objectId' => (int)$failures[0]['object']->getId()]),
			);
		}

		return $result->setData([
			'objects' => $objects,
			'failures' => $failures,
		]);
	}

	/**
	 * The model refuses without a reason the person could read, so the object is asked about once more by
	 * the single verdict, counted anew: the state that made the model refuse is the state after the verdict
	 * of the selection. A verdict that still allows the object leaves the refusal unexplained.
	 */
	protected function resolveFailureReason(
		BaseObject $object,
		Folder $targetFolder,
		DestinationOperation $operation,
	): string
	{
		$verdict = $this->verdictResolver->resolveVerdict($object, $targetFolder, $operation);

		return $verdict->allowed ? self::ERROR_OPERATION_FAILED : (string)$verdict->reason;
	}

	protected function runOperation(
		BaseObject $object,
		Folder $targetFolder,
		DestinationOperation $operation,
		bool $generateUniqueName,
	): ?BaseObject
	{
		if ($operation === DestinationOperation::Move)
		{
			return $object->moveTo($targetFolder, $this->userId, $generateUniqueName);
		}

		return $object->copyTo($targetFolder, $this->userId, $generateUniqueName);
	}

	protected function writeDiagnostic(string $message, array $context): void
	{
		(new LoggerFactory())
			->createById(self::LOGGER_ID)
			?->warning($message, $context)
		;
	}

	protected function writeException(\Throwable $exception): void
	{
		Application::getInstance()->getExceptionHandler()->writeToLog($exception);
	}

	/**
	 * The model knows nothing about the journal: it reports a failed rollback as an error of its own,
	 * and the record is made here.
	 */
	private function writeFailureDiagnostic(
		BaseObject $object,
		Folder $targetFolder,
		DestinationOperation $operation,
		array $verdict,
		array $selectionContext = [],
	): void
	{
		$context = [
			'operation' => $operation->value,
			'objectId' => (int)$object->getId(),
			'folderId' => (int)$targetFolder->getId(),
			'verdict' => $verdict,
			'failureReason' => self::ERROR_OPERATION_FAILED,
			'modelErrorCodes' => $this->collectErrorCodes($object),
			...$selectionContext,
		];

		$this->writeDiagnostic(self::DIAGNOSTIC_DISCREPANCY, $context);

		$rollbackError = $object->getErrorByCode(BaseObject::ERROR_MOVE_ROLLBACK_FAILED);
		if ($rollbackError instanceof Error)
		{
			$this->writeDiagnostic(self::DIAGNOSTIC_ROLLBACK_FAILED, [
				'operation' => $operation->value,
				'objectId' => $context['objectId'],
				'folderId' => $context['folderId'],
				'leftoverCopy' => $rollbackError->getCustomData(),
			]);
		}
	}

	/**
	 * @return string[]
	 */
	private function collectErrorCodes(BaseObject $object): array
	{
		return array_values(array_unique(array_map(
			static fn(Error $error): string => (string)$error->getCode(),
			$object->getErrors(),
		)));
	}
}
