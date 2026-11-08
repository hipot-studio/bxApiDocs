<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\AiAgentGrid;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Internal\AiAgent\Lifecycle\Service\SystemAiAgentLifecycleService;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\AiAgent\ManagedAgentInstance;
use Bitrix\Bizproc\Internal\Repository\AiAgent\ManagedAgentInstanceRepositoryInterface;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\AiAgentRepository;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\UserTable;
use CBPWorkflowTemplateUser;

/**
 * Hands a launched AI-agent over to another employee.
 *
 * Ownership of an agent is the ACTIVATED_BY field of its launched copy, and this service is the only
 * writer of it: the controller asks the transfer predicate first to answer with a precise error code, but
 * the right is checked here as well, so a second caller - a mass transfer, a console scenario - can never
 * get a transfer without one.
 *
 * A managed copy carries a second record of its owner, the managed instance, and its configuration
 * signature is derived from the owner too. Both are reconciled by the lifecycle service under its own
 * named lock, which is taken before the transaction opens: a lock released while writes are still
 * uncommitted would let a background pass of the same instance in before the commit.
 *
 * Everything the transfer changes is written in one transaction, so a refusal at any step leaves the copy
 * with the owner it had. A transfer that went through leaves no journal record of its own; only a refusal by
 * an unforeseen cause writes the class of that cause, the way the lifecycle layer does.
 */
final class OwnershipTransferService
{
	/**
	 * Stable error codes of the operation. accessDenied and templateStale keep the meaning the section
	 * already gave them - a real denial of the right against data that is out of date - so the frontend
	 * handlers of the grid do not learn a second vocabulary for the same two answers.
	 */
	public const ERROR_CODE_ACCESS_DENIED = 'accessDenied';

	public const ERROR_CODE_TEMPLATE_STALE = 'templateStale';

	public const ERROR_CODE_INVALID_RECIPIENT = 'invalidRecipient';

	/** The recipient chosen in the selector already owns the agent; a refusal of its own (Q-1). */
	public const ERROR_CODE_ALREADY_OWNER = 'alreadyOwner';

	/** The transfer could not be carried out at all: no reconciliation path or a failed write. */
	public const ERROR_CODE_TRANSFER_FAILED = 'transferFailed';

	private const LOG_MESSAGE = 'AI agent ownership transfer';

	private readonly ?ManagedAgentInstanceRepositoryInterface $instanceRepository;

	private readonly ?SystemAiAgentLifecycleService $lifecycleService;

	public function __construct(
		private readonly AiAgentRepository $aiAgentRepository = new AiAgentRepository(),
		?ManagedAgentInstanceRepositoryInterface $instanceRepository = null,
		?SystemAiAgentLifecycleService $lifecycleService = null,
	)
	{
		$locator = ServiceLocator::getInstance();

		$this->instanceRepository = $instanceRepository ?? Container::getManagedAgentInstanceRepository();
		$this->lifecycleService = $lifecycleService ?? (
			$locator->has(SystemAiAgentLifecycleService::SERVICE_CODE)
				? $locator->get(SystemAiAgentLifecycleService::SERVICE_CODE)
				: null
		);
	}

	/**
	 * Transfers the launched copy $templateId from $expectedOwnerId to $newOwnerId.
	 *
	 * @param int $expectedOwnerId Owner the caller saw. A transfer of a copy that has another owner by
	 *   now is refused as out of date and never silently applied to whoever owns it at the moment.
	 * @param CBPWorkflowTemplateUser $initiator Actor of the operation, resolved server-side.
	 */
	public function transfer(
		int $templateId,
		int $expectedOwnerId,
		int $newOwnerId,
		CBPWorkflowTemplateUser $initiator,
	): Result
	{
		$result = new Result();

		if (!$this->canInitiatorTransfer($templateId, $initiator))
		{
			// A caller whose expectation of the owner is already out of date is told so instead of being
			// told it has no right: the copy did change hands, and the right it lacks is the right of the
			// owner it no longer sees. The refusal of the right itself is left for a copy whose owner is
			// still the one the caller saw.
			return $result->addError($this->denialError($templateId, $expectedOwnerId));
		}

		// Asked before the eligibility of the recipient: an employee who already owns the agent is a
		// perfectly usable one, and the answer the caller needs is that this is not a transfer at all.
		//
		// The expected owner is a condition of the write and not a statement of fact, so the refusal is
		// decided against the owner the copy really carries: a caller whose expectation is out of date
		// gets the out of date answer of the conditional write instead of being told that the recipient
		// already owns an agent it does not own. The owner is read only when the two ids coincide, so the
		// transfer path of the grid pays nothing for it.
		if ($newOwnerId === $expectedOwnerId && $this->aiAgentRepository->getActivatedBy($templateId) === $newOwnerId)
		{
			return $result->addError(
				ErrorMessage::AI_AGENT_TRANSFER_ALREADY_OWNER->getError([], self::ERROR_CODE_ALREADY_OWNER),
			);
		}

		// The choice made in the selector is not a permission: whether the recipient may own an agent is
		// decided here, at the moment of the operation.
		if (!$this->isUsableRecipient($newOwnerId))
		{
			return $result->addError(
				ErrorMessage::AI_AGENT_TRANSFER_INVALID_RECIPIENT->getError(
					[],
					self::ERROR_CODE_INVALID_RECIPIENT,
				),
			);
		}

		if ($this->instanceRepository === null)
		{
			// Whether the copy is a managed one is unknown without the repository of instances, and an
			// unrecognized managed copy is exactly the one that must not be transferred by halves.
			return $result->addError(
				ErrorMessage::AI_AGENT_TRANSFER_ERROR->getError([], self::ERROR_CODE_TRANSFER_FAILED),
			);
		}

		$instance = $this->instanceRepository->findByTemplateId($templateId);
		if ($instance === null)
		{
			// An ordinary copy carries no managed instance and has nothing to reconcile, so it is handed
			// over even when the lifecycle service is not there at all.
			return $this->apply($templateId, $expectedOwnerId, $newOwnerId, null, $initiator);
		}

		if ($this->lifecycleService === null)
		{
			// Without the managed lifecycle a managed copy would be transferred by halves: the copy would
			// get a new owner while its instance and configuration signature kept the previous one.
			return $result->addError(
				ErrorMessage::AI_AGENT_TRANSFER_ERROR->getError([], self::ERROR_CODE_TRANSFER_FAILED),
			);
		}

		return $this->lifecycleService->runOwnerTransferUnderLock(
			$instance,
			fn(): Result => $this->apply($templateId, $expectedOwnerId, $newOwnerId, $instance, $initiator),
		);
	}

	/**
	 * The transactional part of the transfer.
	 *
	 * The right of the initiator is asked again here, against the row locked by this transaction: the
	 * answer given before it was an answer about a state another transfer of the same copy may have moved
	 * since. Only then does the conditional update run, which doubles as the actuality check, so nothing is
	 * written into the managed instance before the copy itself has changed hands. A partial result is not
	 * allowed: any refusal takes the whole transaction back.
	 */
	private function apply(
		int $templateId,
		int $expectedOwnerId,
		int $newOwnerId,
		?ManagedAgentInstance $instance,
		CBPWorkflowTemplateUser $initiator,
	): Result
	{
		$result = new Result();
		$connection = Application::getConnection();

		// Taking back a transaction that was never opened - a failed start - or one the commit has already
		// closed would take the transaction of the caller instead of this one, so the rollback of an
		// unforeseen cause is asked for only while the transaction of the transfer is really open.
		$transactionOpen = false;

		try
		{
			$connection->startTransaction();
			$transactionOpen = true;

			$lockedOwner = $this->aiAgentRepository->lockStartedLaunchedCopyOwner($templateId);
			if ($lockedOwner !== $expectedOwnerId)
			{
				self::rollbackQuietly($connection);

				return $result->addError(
					ErrorMessage::TEMPLATE_NOT_FOUND->getError(
						['#ID#' => $templateId],
						self::ERROR_CODE_TEMPLATE_STALE,
					),
				);
			}

			if (!$this->canInitiatorTransfer($templateId, $initiator))
			{
				// The right held when the operation started and is gone now belonged to an owner this copy
				// no longer has: a concurrent transfer moved it while this one was on its way. Refusing
				// here is what keeps a transfer irreversible for the owner who has just given the agent
				// away, whatever expectation the request carries.
				self::rollbackQuietly($connection);

				return $result->addError(
					ErrorMessage::ACCESS_DENIED->getError([], self::ERROR_CODE_ACCESS_DENIED),
				);
			}

			if (!$this->aiAgentRepository->changeOwnerIfCurrent($templateId, $expectedOwnerId, $newOwnerId))
			{
				self::rollbackQuietly($connection);

				return $result->addError(
					ErrorMessage::TEMPLATE_NOT_FOUND->getError(
						['#ID#' => $templateId],
						self::ERROR_CODE_TEMPLATE_STALE,
					),
				);
			}

			if ($instance !== null)
			{
				$reconciled = $this->lifecycleService->transferOwner($instance, $newOwnerId);
				if (!$reconciled->isSuccess())
				{
					self::rollbackQuietly($connection);

					return $result->addErrors($reconciled->getErrors());
				}
			}

			$transactionOpen = false;
			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			// A failure of the write itself - the commit included - leaves the operation with the stable
			// refusal of the transfer and nothing half written: an exception must not leave the transaction
			// open for the lock scope above to close, because the scope only releases the lock and would
			// leave the copy half transferred.
			if ($transactionOpen)
			{
				self::rollbackQuietly($connection);
			}

			self::journalCause($templateId, $exception);

			return $result->addError(
				ErrorMessage::AI_AGENT_TRANSFER_ERROR->getError([], self::ERROR_CODE_TRANSFER_FAILED),
			);
		}

		return $result;
	}

	/**
	 * Writes the class of the cause a transfer was refused with, the same way the lifecycle layer writes
	 * one: only the name of the code reaches the journal and no value of it, and the record goes into the
	 * channel of the same agent. What leaves the operation is unchanged - "try again" and transferFailed -
	 * so an infrastructure refusal stays traceable while the caller learns nothing about the internals.
	 */
	private static function journalCause(int $templateId, \Throwable $exception): void
	{
		$logger = (new LoggerFactory(alwaysReturnLogger: false))
			->createById(SystemAiAgentLifecycleService::LOGGER_ID)
		;

		$logger?->warning(self::LOG_MESSAGE, [
			'operation' => SystemAiAgentLifecycleService::OPERATION_TRANSFER_OWNER,
			'templateId' => $templateId,
			'cause' => $exception::class,
		]);
	}

	/**
	 * Whether $initiator may hand this copy over: its owner, an administrator or a holder of the edit
	 * right on it. Asked both before the transaction, to refuse early and cheaply, and inside it, where
	 * the row is locked and the answer is the one the write is made against.
	 */
	private function canInitiatorTransfer(int $templateId, CBPWorkflowTemplateUser $initiator): bool
	{
		return $this->aiAgentRepository->canTransferOwnership(
			$templateId,
			(int)$initiator->getId(),
			$initiator->isAdmin(),
		);
	}

	/**
	 * Tells a refusal of the right apart from data that is out of date (ERR-002). A copy that is no longer
	 * owned by $expectedOwnerId - handed over meanwhile, or gone as a started launched copy - is out of
	 * date, and saying so is what makes the grid reload the row instead of showing a denial for an agent
	 * the caller no longer holds.
	 */
	private function denialError(int $templateId, int $expectedOwnerId): Error
	{
		// The same existence-only predicate the section already answers ERR-002 with: owner aside, is this
		// still a started launched copy at all.
		$isStartedLaunchedCopy = $this->aiAgentRepository->canManageLaunchedTemplate(
			$templateId,
			0,
			isUserAdmin: true,
			requireStarted: true,
		);

		if (!$isStartedLaunchedCopy || $this->aiAgentRepository->getActivatedBy($templateId) !== $expectedOwnerId)
		{
			return ErrorMessage::TEMPLATE_NOT_FOUND->getError(
				['#ID#' => $templateId],
				self::ERROR_CODE_TEMPLATE_STALE,
			);
		}

		return ErrorMessage::ACCESS_DENIED->getError([], self::ERROR_CODE_ACCESS_DENIED);
	}

	/**
	 * Takes the writes of a refused transfer back and lets nothing of the rollback itself leave.
	 *
	 * A caller is free to run the transfer inside a transaction of its own, and a rollback of a nested
	 * transaction returns the rows to its savepoint and throws afterwards. That throw is swallowed on
	 * purpose: what leaves this method has to be the refusal being reported, and answering the throw with
	 * a second rollback would close the transaction of the caller instead of this one.
	 */
	private static function rollbackQuietly(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (\Throwable)
		{
		}
	}

	/**
	 * Whether $userId may own an AI-agent: an active employee of the portal who is neither an external
	 * participant nor a bot.
	 */
	private function isUsableRecipient(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		$row = UserTable::query()
			->setSelect(['ID'])
			->where('ID', $userId)
			->where('ACTIVE', 'Y')
			// Rejects a bot and every other technical account in one condition, by the very list the
			// kernel keeps of them.
			->where('IS_REAL_USER', 'Y')
			->setLimit(1)
			->fetch()
		;

		if (!is_array($row))
		{
			return false;
		}

		// An agent belongs to an employee: an extranet participant sees neither the grid nor the agent.
		// A portal without the intranet module has no employees to tell apart at all.
		return !Loader::includeModule('intranet') || \Bitrix\Intranet\Util::isIntranetUser($userId);
	}
}
