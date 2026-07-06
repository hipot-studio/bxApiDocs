<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Services;

use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Rest\Internal\Entity\Application\AppScopeRequest;
use Bitrix\Rest\Internal\Entity\Application\AppScopeRequestState;
use Bitrix\Rest\Internal\Entity\Application\AppScopeRequestStatus;
use Bitrix\Rest\Internal\Repository\Application\AppRepository;
use Bitrix\Rest\Internal\Repository\AppScopeRequestRepository;

final class AppScopeRequestService
{
	public function __construct(
		private readonly AppScopeRequestRepository $appScopeRequestRepository = new AppScopeRequestRepository(),
		private readonly AppRepository $appRepository = new AppRepository(),
	) {}

	/**
	 * @throws PersistenceException
	 */
	public function approve(int $scopeRequestId, string $comment = ''): AppScopeRequest
	{
		return $this->appScopeRequestRepository->addState($scopeRequestId, AppScopeRequestStatus::Approved, $comment);
	}

	/**
	 * @throws \InvalidArgumentException
	 * @throws PersistenceException
	 */
	public function reject(int $scopeRequestId, string $comment): AppScopeRequest
	{
		if (trim($comment) === '')
		{
			throw new \InvalidArgumentException('Rejection reason (comment) is required');
		}

		return $this->appScopeRequestRepository->addState($scopeRequestId, AppScopeRequestStatus::Rejected, $comment);
	}

	/**
	 * @return AppScopeRequest[]
	 */
	public function getList(int $appId, ?AppScopeRequestStatus $statusFilter = null): array
	{
		return $this->appScopeRequestRepository->getListByAppId($appId, $statusFilter);
	}

	public function getWithStateHistory(int $scopeRequestId): ?AppScopeRequest
	{
		return $this->appScopeRequestRepository->getByIdWithHistory($scopeRequestId);
	}

	/**
	 * @throws \InvalidArgumentException
	 * @throws PersistenceException
	 */
	public function requestScopes(int $appId, array $scopes, string $comment): AppScopeRequest
	{
		if (trim($comment) === '')
		{
			throw new \InvalidArgumentException('Developer comment is required');
		}

		$normalized = [];
		foreach ($scopes as $scope)
		{
			if (!is_string($scope))
			{
				continue;
			}

			$scope = trim($scope);
			if ($scope === '')
			{
				continue;
			}

			$normalized[$scope] = $scope;
		}
		$scopes = array_values($normalized);

		if (empty($scopes))
		{
			throw new \InvalidArgumentException('Scopes list cannot be empty');
		}

		$app = $this->appRepository->getById($appId);
		if ($app === null)
		{
			throw new \InvalidArgumentException("Application #{$appId} not found");
		}

		$allScopes = array_merge(
			$app->getScope(),
			$this->collectScopes(
				$this->appScopeRequestRepository->getListByStatus($appId, [
					AppScopeRequestStatus::Approved,
					AppScopeRequestStatus::Rejected,
					AppScopeRequestStatus::Pending,
				])
			)
		);

		$newScopes = array_values(array_diff($scopes, $allScopes));
		if (empty($newScopes))
		{
			throw new \InvalidArgumentException(
				'All requested scopes are already granted to the application'
			);
		}

		$appScopeRequest = new AppScopeRequest(appId: $appId, scopes: $newScopes);
		$appScopeRequest->setCurrentState(
			new AppScopeRequestState(0, AppScopeRequestStatus::Pending, $comment),
		);
		$this->appScopeRequestRepository->save($appScopeRequest);

		return $appScopeRequest;
	}

	/**
	 * @param AppScopeRequest[] $requests
	 * @return string[]
	 */
	private function collectScopes(array $requests): array
	{
		$scopes = [];
		foreach ($requests as $request)
		{
			foreach ($request->getScopes() as $scope)
			{
				$scopes[(string)$scope] = (string)$scope;
			}
		}

		return array_values($scopes);
	}

	/**
	 * @return string[]
	 */
	public function getApprovedScopes(int $appId): array
	{
		return $this->collectScopes($this->appScopeRequestRepository->getApprovedList($appId));
	}
}