<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Repository\RepositoryInterface;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\Internal\Entity\Application\AppScopeRequest;
use Bitrix\Rest\Internal\Entity\Application\AppScopeRequestState;
use Bitrix\Rest\Internal\Entity\Application\AppScopeRequestStatus;
use Bitrix\Rest\Internal\Model\AppScopeRequestStateTable;
use Bitrix\Rest\Internal\Model\AppScopeRequestTable;
use Bitrix\Rest\Internal\Repository\Mapper\AppScopeRequestMapper;
use Bitrix\Rest\Internal\Repository\Mapper\AppScopeRequestStateMapper;
use Bitrix\Rest\V3\CacheManager;

final class AppScopeRequestRepository implements RepositoryInterface
{
	public function __construct(
		private readonly AppScopeRequestMapper $requestMapper = new AppScopeRequestMapper(),
		private readonly AppScopeRequestStateMapper $requestStateMapper = new AppScopeRequestStateMapper(),
	)
	{
	}

	/**
	 * @throws PersistenceException
	 */
	public function save(EntityInterface $entity): void
	{
		/** @var AppScopeRequest $entity */
		$initialState = $entity->getCurrentState();
		if ($initialState === null)
		{
			throw new PersistenceException('AppScopeRequest must have an initial state before saving');
		}

		$connection = Application::getConnection();

		try
		{
			$connection->startTransaction();

			$scopeRequestResult = AppScopeRequestTable::add([
				'APP_ID' => $entity->getAppId(),
				'SCOPE' => $entity->getScopes(),
				'STATE_ID' => null,
				'DATE_CREATE' => new DateTime(),
			]);

			if (!$scopeRequestResult->isSuccess())
			{
				throw new PersistenceException(implode(', ', $scopeRequestResult->getErrorMessages()));
			}

			$requestId = $scopeRequestResult->getId();
			$entity->setId($requestId);

			$persistedState = $this->persistNewState(
				$requestId,
				$initialState->getStatus(),
				$initialState->getComment(),
			);

			$connection->commitTransaction();

			$entity->setStateId($persistedState->getId());
			$entity->setCurrentState($persistedState);
		}
		catch (PersistenceException $e)
		{
			$connection->rollbackTransaction();
			throw $e;
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();
			throw new PersistenceException($e->getMessage(), previous: $e);
		}
	}

	/**
	 * @throws PersistenceException
	 */
	public function delete(mixed $id): void
	{
		$entity = $this->getById((int)$id);
		if ($entity === null)
		{
			throw new PersistenceException("AppScopeRequest #{$id} not found");
		}

		$connection = Application::getConnection();

		try
		{
			$connection->startTransaction();

			$result = AppScopeRequestTable::delete($id);
			if (!$result->isSuccess())
			{
				throw new PersistenceException('Unable to delete AppScopeRequest #' . $id);
			}

			$connection->commitTransaction();
		}
		catch (PersistenceException $e)
		{
			$connection->rollbackTransaction();
			throw $e;
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();
			throw new PersistenceException($e->getMessage(), previous: $e);
		}
	}


	/**
	 * @throws PersistenceException
	 */
	public function addState(int $requestId, AppScopeRequestStatus $status, string $comment = ''): AppScopeRequest
	{
		$entity = $this->getById($requestId);
		if ($entity === null)
		{
			throw new PersistenceException("AppScopeRequest #{$requestId} not found");
		}

		$currentState = $entity->getCurrentState();

		$connection = Application::getConnection();

		try
		{
			$connection->startTransaction();

			$state = $this->persistNewState($requestId, $status, $comment);

			$connection->commitTransaction();
		}
		catch (PersistenceException $e)
		{
			$connection->rollbackTransaction();
			throw $e;
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();
			throw new PersistenceException($e->getMessage(), previous: $e);
		}

		if (
			$status === AppScopeRequestStatus::Approved // any -> approved
			||
			($currentState && $currentState->getStatus() === AppScopeRequestStatus::Approved) // approved -> any
		)
		{
			$this->clearApprovedScopesCache($entity->getAppId());
		}

		$entity->setStateId($state->getId());
		$entity->setCurrentState($state);

		return $entity;
	}

	/**
	 * @throws PersistenceException
	 */
	private function persistNewState(int $requestId, AppScopeRequestStatus $status, string $comment): AppScopeRequestState
	{
		$dateCreate = new DateTime();

		$stateResult = AppScopeRequestStateTable::add([
			'REQUEST_ID' => $requestId,
			'STATUS' => $status->value,
			'COMMENT' => $comment,
			'DATE_CREATE' => $dateCreate,
		]);

		if (!$stateResult->isSuccess())
		{
			throw new PersistenceException(implode(', ', $stateResult->getErrorMessages()));
		}

		$stateId = $stateResult->getId();

		$updateResult = AppScopeRequestTable::update($requestId, ['STATE_ID' => $stateId]);

		if (!$updateResult->isSuccess())
		{
			throw new PersistenceException(implode(', ', $updateResult->getErrorMessages()));
		}

		$state = new AppScopeRequestState($requestId, $status, $comment);
		$state->setId($stateId);
		$state->setDateCreate($dateCreate);

		return $state;
	}

	public function getById(int $id): ?AppScopeRequest
	{
		$row = AppScopeRequestTable::query()
			->setSelect($this->getRequestSelectWithState())
			->where('ID', $id)
			->fetch();

		if (!$row)
		{
			return null;
		}

		return $this->requestMapper->requestFromRawData($row, $this->extractStateRow($row));
	}

	public function getByIdWithHistory(int $id): ?AppScopeRequest
	{
		$entity = $this->getById($id);
		if ($entity === null)
		{
			return null;
		}

		$rows = AppScopeRequestStateTable::query()
			->setSelect(['ID', 'REQUEST_ID', 'STATUS', 'COMMENT', 'DATE_CREATE'])
			->where('REQUEST_ID', $id)
			->addOrder('DATE_CREATE', 'ASC')
			->fetchAll();

		$history = array_map(
			fn(array $row) => $this->requestStateMapper->stateFromRawData($row),
			$rows,
		);

		$entity->setStateHistory($history);

		return $entity;
	}

	/**
	 * @return AppScopeRequest[]
	 */
	public function getListByAppId(int $appId, ?AppScopeRequestStatus $statusFilter = null): array
	{
		$query = AppScopeRequestTable::query()
			->setSelect($this->getRequestSelectWithState())
			->where('APP_ID', $appId);

		if ($statusFilter !== null)
		{
			$query->where('CURRENT_STATE.STATUS', $statusFilter->value);
		}

		return array_map(
			fn(array $row): AppScopeRequest => $this->requestMapper->requestFromRawData($row, $this->extractStateRow($row)),
			$query->fetchAll(),
		);
	}

	/**
	 * @return AppScopeRequest[]
	 */
	public function getListByStatus(int $appId, AppScopeRequestStatus|array $statuses): array
	{
		$statuses = is_array($statuses) ? $statuses : [$statuses];
		$statusValues = array_map(
			static fn(AppScopeRequestStatus $status) => $status->value,
			$statuses,
		);

		$rows = AppScopeRequestTable::query()
			->setSelect($this->getRequestSelectWithState())
			->where('APP_ID', $appId)
			->whereIn('CURRENT_STATE.STATUS', $statusValues)
			->fetchAll();

		return array_map(
			fn(array $row): AppScopeRequest => $this->requestMapper->requestFromRawData($row, $this->extractStateRow($row)),
			$rows,
		);
	}

	/**
	 * Builds the SELECT list for AppScopeRequestTable joined with CURRENT_STATE
	 * (the ORM reference declared in the table map). State columns are aliased
	 * with the STATE_ROW_ prefix to avoid collision with the request's own
	 * DATE_CREATE / ID and to give the mapper a stable contract.
	 *
	 * @return array<int|string, string>
	 */
	private function getRequestSelectWithState(): array
	{
		return [
			'ID',
			'APP_ID',
			'SCOPE',
			'STATE_ID',
			'DATE_CREATE',
			'STATE_ROW_ID' => 'CURRENT_STATE.ID',
			'STATE_ROW_REQUEST_ID' => 'CURRENT_STATE.REQUEST_ID',
			'STATE_ROW_STATUS' => 'CURRENT_STATE.STATUS',
			'STATE_ROW_COMMENT' => 'CURRENT_STATE.COMMENT',
			'STATE_ROW_DATE_CREATE' => 'CURRENT_STATE.DATE_CREATE',
		];
	}

	/**
	 * Reconstructs a state-row in the shape AppScopeRequestStateMapper expects,
	 * pulling values from the CURRENT_STATE.* aliases of a joined fetch row.
	 * Returns null when there is no state attached (LEFT JOIN miss).
	 */
	private function extractStateRow(array $row): ?array
	{
		if (($row['STATE_ROW_ID'] ?? null) === null)
		{
			return null;
		}

		return [
			'ID' => $row['STATE_ROW_ID'],
			'REQUEST_ID' => $row['STATE_ROW_REQUEST_ID'],
			'STATUS' => $row['STATE_ROW_STATUS'],
			'COMMENT' => $row['STATE_ROW_COMMENT'],
			'DATE_CREATE' => $row['STATE_ROW_DATE_CREATE'],
		];
	}

	/**
	 * @return AppScopeRequest[]
	 */
	public function getApprovedList(int $appId): array
	{
		$cached = CacheManager::get($this->getApprovedListCacheKey($appId));
		if ($cached !== null)
		{
			return $cached;
		}

		$approvedRequests = $this->getListByStatus($appId, AppScopeRequestStatus::Approved);

		CacheManager::set($this->getApprovedListCacheKey($appId), $approvedRequests);

		return $approvedRequests;
	}

	private function clearApprovedScopesCache(int $appId): void
	{
		CacheManager::set($this->getApprovedListCacheKey($appId), null);
	}

	private function getApprovedListCacheKey(int $appId): string
	{
		return 'approved_scope_requests_' . $appId;
	}
}
