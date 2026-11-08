<?php

namespace Bitrix\BIConnector\Public\Command\ShareControl;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardShare;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardShareBanRepository;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardShareRepository;
use Bitrix\BIConnector\Internal\Services\Share\ShareAuthorPullService;
use Bitrix\BIConnector\Internal\Services\Share\ShareNotificationService;
use Bitrix\BIConnector\Superset\Dashboard\ShareExpireAgent;
use Bitrix\BIConnector\Superset\Dashboard\SharePullService;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Main\Application;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Forbids public links to the dashboard: the ban and the switch-off of every active link are one transaction,
 * so either both happen or neither does. A dashboard already banned is left as is.
 */
class BanDashboardCommand extends AbstractCommand
{
	public const ERROR_BAN_CONFLICT = 'ban_conflict';

	public function __construct(
		public readonly int $dashboardId,
		public readonly int $controllerId,
	)
	{
	}

	protected function execute(): Result
	{
		$result = new Result();
		$connection = Application::getConnection();

		$connection->startTransaction();
		try
		{
			$disabledShares = $this->banAndDisable();
		}
		catch (\Exception $e)
		{
			$this->rollback();

			return $result->addError(new Error($e->getMessage(), $e->getCode()));
		}

		if ($disabledShares === null)
		{
			$this->rollback();

			return $result->addError(new Error(
				'Some public links of the dashboard changed while it was being banned',
				self::ERROR_BAN_CONFLICT,
			));
		}

		$connection->commitTransaction();

		$this->revoke($disabledShares);
		$this->updateOpenPopups($disabledShares);
		$this->notifyAuthors($disabledShares);

		return $result->setData(['disabledCount' => count($disabledShares)]);
	}

	/**
	 * @return SupersetDashboardShare[]|null The switched-off links, null when not all of them could be switched off.
	 */
	private function banAndDisable(): ?array
	{
		if (!$this->getBanRepository()->add($this->dashboardId, $this->controllerId))
		{
			return [];
		}

		$repository = $this->getShareRepository();
		$now = new DateTime();
		$shares = $repository->lockActiveByDashboardId($this->dashboardId);
		if (!empty($shares))
		{
			$disabledCount = $repository->deactivateActive(
				array_map(static fn(SupersetDashboardShare $share) => $share->getId(), $shares),
				$this->controllerId,
				$now,
			);
			if ($disabledCount !== count($shares))
			{
				return null;
			}
		}

		// A link that appeared after the read would stay on under the ban and come back after the unban.
		if ($repository->deactivateActiveByDashboardId($this->dashboardId, $this->controllerId, $now) > 0)
		{
			return null;
		}

		return $shares;
	}

	private function rollback(): void
	{
		try
		{
			Application::getConnection()->rollbackTransaction();
		}
		// Inside an outer transaction the changes are already rolled back to the savepoint.
		catch (TransactionException)
		{
		}
	}

	private function getBanRepository(): SupersetDashboardShareBanRepository
	{
		return ServiceLocator::getInstance()->get('biconnector.repository.shareBan');
	}

	private function getShareRepository(): SupersetDashboardShareRepository
	{
		return ServiceLocator::getInstance()->get('biconnector.repository.share');
	}

	/**
	 * @param SupersetDashboardShare[] $shares
	 */
	private function revoke(array $shares): void
	{
		foreach ($shares as $share)
		{
			try
			{
				ShareExpireAgent::remove($share->getId());
				SharePullService::sendRevokeEvent($share->getToken());
			}
			catch (\Exception $e)
			{
				Logger::logErrors([new Error($e->getMessage(), $e->getCode())], ['step' => 'revoke', 'shareId' => $share->getId()]);
			}
		}
	}

	/**
	 * @param SupersetDashboardShare[] $shares
	 */
	private function updateOpenPopups(array $shares): void
	{
		try
		{
			ServiceLocator::getInstance()
				->get(ShareAuthorPullService::class)
				->sendDisabledByAdmin($this->controllerId, $shares, true)
			;
		}
		catch (\Exception $e)
		{
			Logger::logErrors([new Error($e->getMessage(), $e->getCode())], ['step' => 'pull-disabled']);
		}
	}

	/**
	 * @param SupersetDashboardShare[] $shares
	 */
	private function notifyAuthors(array $shares): void
	{
		if (empty($shares))
		{
			return;
		}

		$authorIds = array_values(array_unique(array_map(
			static fn(SupersetDashboardShare $share) => $share->getCreatedById(),
			$shares,
		)));

		try
		{
			ServiceLocator::getInstance()
				->get(ShareNotificationService::class)
				->notifyForbidden($this->controllerId, $this->dashboardId, $authorIds)
			;
		}
		catch (\Exception $e)
		{
			Logger::logErrors([new Error($e->getMessage(), $e->getCode())], ['step' => 'notify-forbidden']);
		}
	}
}
