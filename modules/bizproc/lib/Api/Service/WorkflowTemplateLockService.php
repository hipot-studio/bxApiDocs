<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Service;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Api\Request\WorkflowTemplateLockService\AcquireLockRequest;
use Bitrix\Bizproc\Api\Request\WorkflowTemplateLockService\GetLockRequest;
use Bitrix\Bizproc\Api\Request\WorkflowTemplateLockService\ReleaseLockRequest;
use Bitrix\Bizproc\Api\Response\WorkflowTemplateLockService\AcquireLockResponse;
use Bitrix\Bizproc\Api\Response\WorkflowTemplateLockService\GetLockResponse;
use Bitrix\Bizproc\Api\Response\WorkflowTemplateLockService\LockOwnerDto;
use Bitrix\Bizproc\Api\Response\WorkflowTemplateLockService\ReleaseLockResponse;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateLockTable;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Type\DateTime;

/**
 * The editing lease of a template: who is allowed to write to it right now.
 *
 * The service checks no rights at all — the caller must have checked the edit right before it asks for a
 * lease. Today the callers are the editor controller and the agent service; a third one would have to move
 * the check inside.
 *
 * An unavailable lease storage is answered with a failed response instead of an exception: the caller
 * decides per write path whether it closes the write or passes it through.
 */
class WorkflowTemplateLockService
{
	public const LEASE_TTL_SECONDS = 600;

	/**
	 * Grants the lease of the template to the user, or extends the one it already holds. The lease of
	 * another user that is still alive is left alone and reported as is.
	 *
	 * A living lease of the same user is extended only on its second half: the autosave of the editor asks
	 * for the lease far more often than the lease has to be written.
	 */
	public function acquireOrRenew(AcquireLockRequest $request): AcquireLockResponse
	{
		$response = new AcquireLockResponse();
		$now = $this->getCurrentTime();

		try
		{
			$lock = $this->getLockRow($request->templateId);

			if ($lock !== null && $this->isLive($lock, $now) && (int)$lock['USER_ID'] === $request->userId)
			{
				if ($this->getRemainingSeconds($lock, $now) >= self::LEASE_TTL_SECONDS / 2)
				{
					return $this->respondWithState($response, $lock, $request->userId, $now);
				}

				// The extension opens a new window of the full term from the current moment, so the moment the
				// window was taken moves with it: the client reads the term of the lease as the distance
				// between the two, and leaving the old one behind would make that term grow with every
				// extension.
				WorkflowTemplateLockTable::updateByFilter(
					['=TEMPLATE_ID' => $request->templateId, '=USER_ID' => $request->userId],
					[
						'ACQUIRED_AT' => $now,
						'EXPIRES_AT' => $this->getExpirationTime($now),
					],
				);

				return $this->respondWithReadState($response, $request->templateId, $request->userId, $now);
			}

			if ($lock !== null && $this->isLive($lock, $now))
			{
				return $this->respondWithState($response, $lock, $request->userId, $now);
			}

			if ($lock === null && $this->insertLock($request->templateId, $request->userId, $now))
			{
				return $this->respondWithReadState($response, $request->templateId, $request->userId, $now);
			}

			// Taking an expired lease over is a single statement: splitting it into a delete and an insert
			// would open a window for a second writer to slip in between them.
			WorkflowTemplateLockTable::updateByFilter(
				['=TEMPLATE_ID' => $request->templateId, '<EXPIRES_AT' => $now],
				[
					'USER_ID' => $request->userId,
					'ACQUIRED_AT' => $now,
					'EXPIRES_AT' => $this->getExpirationTime($now),
				],
			);

			return $this->respondWithReadState($response, $request->templateId, $request->userId, $now);
		}
		catch (SqlQueryException)
		{
			$response->addError(ErrorMessage::UNKNOWN_ERROR->getCodedError());

			return $response;
		}
	}

	/**
	 * An ordinary user releases nothing but its own lease: without the condition on the owner the ban would
	 * be walked around in two steps — release the lease of another user, then take one.
	 */
	public function release(ReleaseLockRequest $request): ReleaseLockResponse
	{
		$response = new ReleaseLockResponse();

		$filter = ['=TEMPLATE_ID' => $request->templateId];
		if (!$request->isPortalAdmin)
		{
			$filter['=USER_ID'] = $request->userId;
		}

		try
		{
			$deleted = WorkflowTemplateLockTable::deleteByFilterAndCount($filter);
		}
		catch (SqlQueryException)
		{
			$response->addError(ErrorMessage::UNKNOWN_ERROR->getCodedError());

			return $response;
		}

		return $response->setReleased($deleted > 0);
	}

	/**
	 * Who edits the template right now. An expired lease has no owner: its row survives until somebody takes
	 * the template over, and reporting it would show the editor an owner that stopped being one.
	 */
	public function getLock(GetLockRequest $request): GetLockResponse
	{
		$response = new GetLockResponse();
		$now = $this->getCurrentTime();

		try
		{
			$lock = $this->getLockRow($request->templateId);
		}
		catch (SqlQueryException)
		{
			$response->addError(ErrorMessage::UNKNOWN_ERROR->getCodedError());

			return $response;
		}

		if ($lock === null || !$this->isLive($lock, $now))
		{
			return $response;
		}

		return $response->setLock(LockOwnerDto::fromRow($lock, $request->userId));
	}

	protected function getCurrentTime(): DateTime
	{
		return new DateTime();
	}

	/**
	 * @return bool false when the row was taken by another writer first: the caller falls through to the
	 * takeover of an expired lease
	 */
	private function insertLock(int $templateId, int $userId, DateTime $now): bool
	{
		try
		{
			$this->addLockRow([
				'TEMPLATE_ID' => $templateId,
				'USER_ID' => $userId,
				'ACQUIRED_AT' => $now,
				'EXPIRES_AT' => $this->getExpirationTime($now),
			]);
		}
		catch (SqlQueryException $exception)
		{
			if ($this->getLockRow($templateId) !== null)
			{
				return false;
			}

			throw $exception;
		}

		return true;
	}

	protected function addLockRow(array $fields): void
	{
		WorkflowTemplateLockTable::add($fields);
	}

	/**
	 * The outcome of a write is read from the lease it left behind and never from the number of rows
	 * written: writing the same values changes no row at all, and counting that as a refusal would deny the
	 * owner its own lease.
	 */
	private function respondWithReadState(
		AcquireLockResponse $response,
		int $templateId,
		int $userId,
		DateTime $now,
	): AcquireLockResponse
	{
		return $this->respondWithState($response, $this->getLockRow($templateId), $userId, $now);
	}

	private function respondWithState(
		AcquireLockResponse $response,
		?array $lock,
		int $userId,
		DateTime $now,
	): AcquireLockResponse
	{
		if ($lock === null)
		{
			return $response->setHeld(false);
		}

		return $response
			->setLock(LockOwnerDto::fromRow($lock, $userId))
			->setHeld((int)$lock['USER_ID'] === $userId && $this->isLive($lock, $now))
		;
	}

	/**
	 * @return array<string, mixed>|null the lease of the template, read by its unique key
	 */
	protected function getLockRow(int $templateId): ?array
	{
		$row = WorkflowTemplateLockTable::query()
			->setSelect(['USER_ID', 'ACQUIRED_AT', 'EXPIRES_AT'])
			->where('TEMPLATE_ID', $templateId)
			->fetch()
		;

		return $row ?: null;
	}

	private function isLive(array $lock, DateTime $now): bool
	{
		return $this->getRemainingSeconds($lock, $now) >= 0;
	}

	private function getRemainingSeconds(array $lock, DateTime $now): int
	{
		return $lock['EXPIRES_AT']->getTimestamp() - $now->getTimestamp();
	}

	private function getExpirationTime(DateTime $now): DateTime
	{
		return (clone $now)->add(self::LEASE_TTL_SECONDS . ' seconds');
	}
}
