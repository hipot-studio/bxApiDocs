<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Command\Rights;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Internal\Enum\ObjectRightsChangeMode;
use Bitrix\Disk\Internal\Service\Audit\AuditActionContextResolver;
use Bitrix\Disk\Internal\Service\Audit\AuditCaptureService;
use Bitrix\Disk\Internal\Service\Audit\AuditOperationContext;
use Bitrix\Disk\Internal\Service\Audit\AuditRightsChangeAuditService;
use Bitrix\Disk\Internal\Service\Audit\AuditTransaction;
use Bitrix\Disk\Internal\Service\Audit\AuditTransactionOutcome;
use Bitrix\Disk\Internal\Service\Rights\RightsRevokeSessionTerminator;
use Bitrix\Disk\RightsManager;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

/**
 * The command works with one kind of a row only: a granting right of the base domain, the very row the
 * popup "Access" writes. A prohibiting row (NEGATIVE = 1) and a row of another domain - the one a sharing
 * keeps - belong to other machinery, and the command leaves them where they are, reporting the code as
 * skipped instead of taking a right of theirs apart. A revoke deletes the rows it names and nothing else,
 * so a row written while the command was running stays where it is.
 */
class ChangeObjectRightsCommandHandler
{
	/**
	 * Both a grant and a revoke read the rights of the object and write them back, and RightsManager
	 * rewrites the whole set of an object on a write, so two runs of the activity on one object would
	 * overwrite one another. The lock below serializes the runs of this command on one object - and only
	 * them: the popup "Access" and a rebuilt sharing write through RightsManager without taking it, so a
	 * write of theirs still races with a write of ours. Serializing every write of rights of the module
	 * would mean a lock inside RightsManager itself.
	 */
	private const LOCK_NAME_PREFIX = 'disk_object_rights_';
	private const LOCK_TIMEOUT = 10;

	public function __construct(
		private readonly RightsManager $rightsManager,
		private readonly RightsRevokeSessionTerminator $sessionTerminator,
	)
	{
	}

	public function __invoke(ChangeObjectRightsCommand $command): Result
	{
		$result = new Result();

		try
		{
			$object = $this->loadObject($command->objectId);
			if (!$object)
			{
				return $result->addError(new Error(
					'Could not find the object of Disk to change the rights of.',
					ChangeObjectRightsCommand::ERROR_OBJECT_NOT_FOUND,
				));
			}

			if (!$this->canChangeRights($object, $command->operatorId))
			{
				return $result->addError(new Error(
					'The operator may not change the rights of the object.',
					ChangeObjectRightsCommand::ERROR_ACCESS_DENIED,
				));
			}

			// A right written on an object of the trash can would come back with it and reach nobody meanwhile.
			if ($object->isDeleted())
			{
				return $result->addError(new Error(
					'The object of Disk is in the trash can.',
					ChangeObjectRightsCommand::ERROR_OBJECT_IN_TRASH,
				));
			}

			if (!$object->getStorage()?->isUseInternalRights())
			{
				return $result->addError(new Error(
					'The storage of the object does not keep rights of its own.',
					ChangeObjectRightsCommand::ERROR_NO_INTERNAL_RIGHTS,
				));
			}

			if (!$this->isAllowedTaskName($command->taskName))
			{
				return $result->addError(new Error(
					'The access level given is not one of those the command hands out.',
					ChangeObjectRightsCommand::ERROR_UNKNOWN_TASK,
				));
			}

			$taskId = (int)$this->rightsManager->getTaskIdByName($command->taskName);
			$accessCodes = $this->normalizeAccessCodes($command->accessCodes);

			return $this->underObjectLock(
				(int)$object->getId(),
				fn(): Result => $this->changeRights($object, $taskId, $accessCodes, $command),
			);
		}
		catch (Throwable $exception)
		{
			return $result->addError(new Error(
				$exception->getMessage(),
				ChangeObjectRightsCommand::ERROR_COULD_NOT_CHANGE,
			));
		}
	}

	private function changeRights(
		BaseObject $object,
		int $taskId,
		array $accessCodes,
		ChangeObjectRightsCommand $command,
	): Result
	{
		$result = new Result();
		$mutation = function (AuditTransaction $owner) use (
			$object, $taskId, $accessCodes, $command, &$result,
		): AuditTransactionOutcome {
			$audit = new AuditRightsChangeAuditService();
			$before = $audit->capture($object);
			$context = null;
			AuditCaptureService::runFailOpen(function () use ($command, &$context): Result {
				$context = AuditOperationContext::create(actionContext:
					(new AuditActionContextResolver())->resolveForBizproc($command->operatorId, [
						'type' => 'bizproc', 'entityType' => 'workflow',
						'entityId' => $command->workflowContext['workflowId'] ?? null,
						'entityName' => $command->workflowContext['templateName'] ?? null,
					]));

				return new Result();
			});
			$result = $command->mode === ObjectRightsChangeMode::Grant
				? $this->grant($object, $taskId, $accessCodes, $owner)
				: $this->revoke($object, $taskId, $accessCodes, $command->operatorId, $owner);
			if ($result->isSuccess() && $context !== null)
			{
				$audit->schedule($object, $before, $context, $owner);
			}

			return $result->isSuccess()
				? AuditTransactionOutcome::success($result)
				: AuditTransactionOutcome::failure($result);
		};
		AuditTransaction::run(static fn(AuditTransaction $owner): bool => $owner->join($mutation)->isSuccess());

		return $result;
	}

	/**
	 * A name of a task the portal knows is not enough: the popup "Access" offers four levels, and only
	 * those may be handed out from a template, where the name comes from a value on the run.
	 */
	private function isAllowedTaskName(string $taskName): bool
	{
		return in_array($taskName, ChangeObjectRightsCommand::ALLOWED_TASK_NAMES, true)
			&& $this->rightsManager->isValidTaskName($taskName)
		;
	}

	/**
	 * A prohibiting right is not lifted here: RightsManager drops a pair of opposite rows on the same code
	 * and task, so a grant over a prohibition standing on the object would take the prohibition away and
	 * write nothing in its place, and a prohibition standing on a parent folder would be annihilated by the
	 * row written below it. The code is reported as skipped, and what has really been written is read back
	 * from the object.
	 *
	 * @param string[] $accessCodes
	 */
	private function grant(
		BaseObject $object,
		int $taskId,
		array $accessCodes,
		AuditTransaction $owner,
	): Result
	{
		$result = new Result();
		$specificRights = $this->rightsManager->getSpecificRights($object);
		$rightsOfTheWholePath = array_merge($specificRights, $this->rightsManager->getParentsRights($object->getId()));

		$applied = [];
		$requested = [];
		$skipped = [];
		foreach ($accessCodes as $accessCode)
		{
			if ($this->hasProhibitingRight($rightsOfTheWholePath, $accessCode, $taskId))
			{
				$skipped[$accessCode] = ChangeObjectRightsCommand::SKIP_REASON_PROHIBITED;
			}
			elseif ($this->hasOwnRight($specificRights, $accessCode, $taskId))
			{
				// The very row the grant would write stands there already: writing it once more would
				// rewrite the rights of the object and rebuild the subtree for no change at all.
				$applied[] = $accessCode;
			}
			elseif ($this->hasRightOfOtherDomain($specificRights, $accessCode, $taskId))
			{
				// Disk keeps one row per code and task whichever domain it carries, and the row standing
				// there wins: nothing of the grant would reach the object.
				$skipped[$accessCode] = ChangeObjectRightsCommand::SKIP_REASON_NOT_APPLIED;
			}
			else
			{
				$requested[] = $accessCode;
			}
		}

		if (!$requested)
		{
			return $result->setData(['applied' => $applied, 'skipped' => $skipped]);
		}

		$rights = array_map(
			static fn(string $accessCode): array => [
				'ACCESS_CODE' => $accessCode,
				'TASK_ID' => $taskId,
				'NEGATIVE' => 0,
			],
			$requested,
		);

		if (!$this->rightsManager->append($object, $rights, $owner))
		{
			return $this->buildFailure();
		}

		$rightsAfterChange = $this->rightsManager->getSpecificRights($object);

		foreach ($requested as $accessCode)
		{
			if ($this->hasOwnRight($rightsAfterChange, $accessCode, $taskId))
			{
				$applied[] = $accessCode;
			}
			else
			{
				$skipped[$accessCode] = ChangeObjectRightsCommand::SKIP_REASON_NOT_APPLIED;
			}
		}

		return $result->setData(['applied' => $applied, 'skipped' => $skipped]);
	}

	/**
	 * Only a granting right standing on the object itself in the base domain can be taken back, and only
	 * that row is deleted. A recipient who reaches the object by a right of a parent folder keeps reaching
	 * it, and their code is reported as skipped instead. A right of another domain is left standing as
	 * well: it serves a sharing that would write it back, and the code is reported as skipped so that the
	 * journal does not read as a full revoke.
	 *
	 * A recipient whose right is gone may still hold a session of editing opened before, so the sessions left
	 * without a right are ended afterwards, as the popup "Access" does it.
	 *
	 * @param string[] $accessCodes
	 */
	private function revoke(
		BaseObject $object,
		int $taskId,
		array $accessCodes,
		int $operatorId,
		AuditTransaction $owner,
	): Result
	{
		$result = new Result();
		$specificRights = $this->rightsManager->getSpecificRights($object);

		$applied = [];
		$skipped = [];
		foreach ($accessCodes as $accessCode)
		{
			$hasOwnRight = $this->hasOwnRight($specificRights, $accessCode, $taskId);
			$hasRightOfOtherDomain = $this->hasRightOfOtherDomain($specificRights, $accessCode, $taskId);

			if (!$hasOwnRight)
			{
				$skipped[$accessCode] = $hasRightOfOtherDomain
					? ChangeObjectRightsCommand::SKIP_REASON_OTHER_DOMAIN
					: ChangeObjectRightsCommand::SKIP_REASON_INHERITED
				;

				continue;
			}

			$applied[] = $accessCode;

			if ($hasRightOfOtherDomain)
			{
				$skipped[$accessCode] = ChangeObjectRightsCommand::SKIP_REASON_OTHER_DOMAIN;
			}
		}

		if (!$applied)
		{
			return $result->setData(['applied' => [], 'skipped' => $skipped]);
		}

		if (!$this->rightsManager->deleteBaseDomainRights($object, $applied, $taskId, $owner))
		{
			return $this->buildFailure();
		}

		$this->sessionTerminator->scheduleFor($object, $operatorId);

		return $result->setData(['applied' => $applied, 'skipped' => $skipped]);
	}

	private function hasOwnRight(array $rights, string $accessCode, int $taskId): bool
	{
		foreach ($rights as $right)
		{
			if ($this->isOwnRight($right, [$accessCode], $taskId))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * A prohibition of any domain annihilates a granting right on the same code, whether it stands on the
	 * object or on a folder above it. The task of the prohibition need not be the one handed out either:
	 * a prohibition of reading leaves nothing of a right to edit, which holds reading inside it.
	 */
	private function hasProhibitingRight(
		array $rights,
		string $accessCode,
		int $taskId,
	): bool
	{
		foreach ($rights as $right)
		{
			if (
				(string)$right['ACCESS_CODE'] === $accessCode
				&& $this->isNegative($right)
				&& $this->shareOperations((int)$right['TASK_ID'], $taskId)
			)
			{
				return true;
			}
		}

		return false;
	}

	private function shareOperations(int $taskId, int $otherTaskId): bool
	{
		if ($taskId === $otherTaskId)
		{
			return true;
		}

		return (bool)array_intersect(
			(array)$this->rightsManager->getOperationsByTask($taskId),
			(array)$this->rightsManager->getOperationsByTask($otherTaskId),
		);
	}

	private function hasRightOfOtherDomain(array $rights, string $accessCode, int $taskId): bool
	{
		foreach ($rights as $right)
		{
			if (
				$this->matchesPair($right, [$accessCode], $taskId)
				&& !$this->isNegative($right)
				&& !$this->isBaseDomain($right)
			)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string[] $accessCodes
	 */
	private function isOwnRight(array $right, array $accessCodes, int $taskId): bool
	{
		return $this->matchesPair($right, $accessCodes, $taskId)
			&& !$this->isNegative($right)
			&& $this->isBaseDomain($right)
		;
	}

	/**
	 * @param string[] $accessCodes
	 */
	private function matchesPair(array $right, array $accessCodes, int $taskId): bool
	{
		return (int)$right['TASK_ID'] === $taskId
			&& in_array((string)$right['ACCESS_CODE'], $accessCodes, true)
		;
	}

	private function isNegative(array $right): bool
	{
		return (int)($right['NEGATIVE'] ?? 0) === 1;
	}

	private function isBaseDomain(array $right): bool
	{
		return (string)($right['DOMAIN'] ?? '') === '';
	}

	/**
	 * @param string[] $accessCodes
	 * @return string[]
	 */
	private function normalizeAccessCodes(array $accessCodes): array
	{
		$normalized = [];
		foreach ($accessCodes as $accessCode)
		{
			$accessCode = trim((string)$accessCode);
			if ($accessCode !== '')
			{
				$normalized[$accessCode] = $accessCode;
			}
		}

		return array_values($normalized);
	}

	private function loadObject(int $objectId): ?BaseObject
	{
		$object = BaseObject::loadById($objectId, ['STORAGE']);

		return $object?->getRealObject();
	}

	private function canChangeRights(BaseObject $object, int $operatorId): bool
	{
		$storage = $object->getStorage();
		if (!$storage)
		{
			return false;
		}

		return $object->canChangeRights($storage->getSecurityContext($operatorId));
	}

	/**
	 * @param callable(): Result $action
	 */
	private function underObjectLock(int $objectId, callable $action): Result
	{
		$connection = Application::getInstance()->getConnection();
		$lockName = self::LOCK_NAME_PREFIX . $objectId;

		if (!$connection->lock($lockName, self::LOCK_TIMEOUT))
		{
			return (new Result())->addError(new Error(
				'Another change of the rights of the object is running.',
				ChangeObjectRightsCommand::ERROR_COULD_NOT_CHANGE,
			));
		}

		try
		{
			return $action();
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	private function buildFailure(): Result
	{
		$result = new Result();
		$errors = $this->rightsManager->getErrors();

		return $errors
			? $result->addErrors($errors)
			: $result->addError(new Error(
				'Could not change the rights of the object.',
				ChangeObjectRightsCommand::ERROR_COULD_NOT_CHANGE,
			))
		;
	}
}
