<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Application\AppInstallRequest;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\Internal\Entity\Application\AppInstallRequest\AppInstallRequest;
use Bitrix\Rest\Internal\Entity\Application\AppInstallRequest\AppInstallRequestStatus;
use Bitrix\Rest\Internal\Repository\Application\AppInstallRequest\AppInstallRequestRepository;
use Bitrix\Rest\Internal\Integration\Im\AppInstallRequestNotifier;
use Bitrix\Rest\Internal\Repository\Application\AppRepository;
use Bitrix\Rest\Internal\Exception\Application\AppInstallRequest\AppInstallRequestAlreadyExistsException;
use Bitrix\Rest\Internal\Exception\Application\AppInstallRequest\AppInstallRequestAlreadyInstalledException;
use Bitrix\Rest\Internal\Exception\Application\AppInstallRequest\AppInstallRequestApplicationNotFoundException;
use Bitrix\Rest\Internal\Exception\Application\AppInstallRequest\AppInstallRequestNotAllowedException;
use Bitrix\Rest\Marketplace\Client;

final class AppInstallRequestManager
{
	public function __construct(
		private readonly AppInstallRequestRepository $repository = new AppInstallRequestRepository(),
		private readonly AppInstallRequestNotifier $notifier = new AppInstallRequestNotifier(),
		private readonly AppRepository $appRepository = new AppRepository(),
	)
	{
	}

	/**
	 * @throws AppInstallRequestAlreadyExistsException
	 * @throws AppInstallRequestAlreadyInstalledException
	 * @throws AppInstallRequestApplicationNotFoundException
	 * @throws AppInstallRequestNotAllowedException
	 * @throws \Throwable
	 */
	public function create(
		string $appCode,
		string $appVersion,
		int $userId,
		?string $checkHash = null,
		?string $installHash = null,
	): AppInstallRequest
	{
		$appVersion = ($appVersion !== '') ? $appVersion : '1';

		$existing = $this->repository->findActivePendingByUser($appCode, $appVersion, $userId);
		if ($existing !== null)
		{
			throw new AppInstallRequestAlreadyExistsException($existing->getId());
		}

		if ($this->appRepository->isVersionInstalledByCode($appCode, $appVersion))
		{
			throw new AppInstallRequestAlreadyInstalledException();
		}

		$appDetailInfo = Client::getInstall($appCode, $appVersion, $checkHash ?? false, $installHash ?? false);
		$appDetailInfo = $appDetailInfo['ITEMS'] ?? false;

		if (!$appDetailInfo)
		{
			throw new AppInstallRequestApplicationNotFoundException();
		}

		if (\CRestUtil::canInstallApplication($appDetailInfo, $userId))
		{
			throw new AppInstallRequestNotAllowedException();
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$request = new AppInstallRequest(
				appCode: $appCode,
				userId: $userId,
				status: AppInstallRequestStatus::Pending,
				appVersion: $appVersion,
				checkHash: $checkHash,
				installHash: $installHash,
				appName: isset($appDetailInfo['NAME']) ? (string)$appDetailInfo['NAME'] : null,
				appIconUrl: isset($appDetailInfo['ICON']) ? (string)$appDetailInfo['ICON'] : null,
			);

			$this->repository->save($request);

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			throw $e;
		}

		try
		{
			$this->notifier->notifyCreated($request);
		}
		catch (\Throwable $e)
		{
			try
			{
				$this->repository->deleteById($request->getId());
			}
			catch (\Throwable)
			{
			}

			throw $e;
		}

		return $request;
	}

	/**
	 * @throws \Throwable
	 */
	public function reject(int $requestId, int $adminId): bool
	{
		$request = $this->repository->getById($requestId);
		if ($request === null)
		{
			return false;
		}

		if ($request->getStatus() !== AppInstallRequestStatus::Pending)
		{
			return true;
		}

		$group = $this->repository->findPendingGroup($request->getAppCode(), $request->getAppVersion());

		if (empty($group))
		{
			return true;
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			foreach ($group as $pendingRequest)
			{
				$this->resolveRequest($pendingRequest, AppInstallRequestStatus::Rejected, $adminId);
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			throw $e;
		}

		try
		{
			$this->notifier->notifyGroupRejected($group, $adminId);
		}
		catch (\Throwable)
		{
		}

		return true;
	}

	/**
	 * @throws \Throwable
	 */
	public function resolveInstalled(string $appCode, string $appVersion = '1', ?int $installerId = null): void
	{
		$appVersion = ($appVersion !== '') ? $appVersion : '1';

		$group = $this->repository->findPendingGroup($appCode, $appVersion);

		if (empty($group))
		{
			return;
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			foreach ($group as $pendingRequest)
			{
				$this->resolveRequest($pendingRequest, AppInstallRequestStatus::Installed, $installerId);
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			throw $e;
		}

		try
		{
			$this->notifier->notifyGroupInstalled($group);
		}
		catch (\Throwable)
		{
		}
	}

	public function dismissOtherAdmins(int $requestId, int $adminId): void
	{
		$request = $this->repository->getById($requestId);
		if ($request === null || $request->getStatus() !== AppInstallRequestStatus::Pending)
		{
			return;
		}

		$group = $this->repository->findPendingGroup($request->getAppCode(), $request->getAppVersion());

		$this->notifier->dismissOtherAdmins($group, $adminId);
	}

	private function resolveRequest(
		AppInstallRequest $request,
		AppInstallRequestStatus $status,
		?int $resolvedById,
	): void
	{
		$request
			->setStatus($status)
			->setResolvedById($resolvedById)
			->setDateResolve(new DateTime())
		;

		$this->repository->save($request);
	}
}
