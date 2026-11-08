<?php

namespace Bitrix\BIConnector\Public\Command\ShareControl;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardShare;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardShareRepository;
use Bitrix\BIConnector\Internal\Services\Share\ShareAuthorPullService;
use Bitrix\BIConnector\Internal\Services\Share\ShareNotificationService;
use Bitrix\BIConnector\Superset\Dashboard\ShareExpireAgent;
use Bitrix\BIConnector\Superset\Dashboard\SharePullService;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Switches off the chosen public links on behalf of the controller. Every link is switched off on its own,
 * so a failed one does not hold back the rest; a link already switched off by someone else is left as is.
 */
class DisableSharesCommand extends AbstractCommand
{
	/**
	 * @param int[] $shareIds
	 */
	public function __construct(
		public readonly array $shareIds,
		public readonly int $controllerId,
	)
	{
	}

	protected function execute(): Result
	{
		$result = new DisableSharesResult();

		$shareIds = array_values(array_unique(array_map('intval', $this->shareIds)));
		if (empty($shareIds))
		{
			return $result;
		}

		try
		{
			$repository = $this->getRepository();
			$shares = [];
			foreach ($repository->getByIds($shareIds) as $share)
			{
				$shares[$share->getId()] = $share;
			}
		}
		catch (\Exception $e)
		{
			return $result->addError(new Error($e->getMessage(), $e->getCode()));
		}

		$disabled = [];
		foreach ($shareIds as $shareId)
		{
			$share = $shares[$shareId] ?? null;
			if ($share === null || ($share->isActive() && $share->isExpired()))
			{
				$result->addFailed($shareId, DisableSharesResult::ERROR_SHARE_NOT_VALID);

				continue;
			}

			try
			{
				if ($repository->deactivateIfActive($shareId, $this->controllerId, new DateTime()))
				{
					$disabled[] = $share;
					$result->addDisabledId($shareId);
				}
			}
			catch (\Exception $e)
			{
				$result->addFailed($shareId, DisableSharesResult::ERROR_DISABLE_FAILED);
				Logger::logErrors([new Error($e->getMessage(), $e->getCode())], ['step' => 'disable', 'shareId' => $shareId]);
			}
		}

		$this->revoke($disabled);
		$this->updateOpenPopups($disabled);
		$this->notifyAuthors($disabled);

		return $result;
	}

	private function getRepository(): SupersetDashboardShareRepository
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
				->sendDisabledByAdmin($this->controllerId, $shares, false)
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

		$dashboardIdsByAuthor = [];
		foreach ($shares as $share)
		{
			$dashboardIdsByAuthor[$share->getCreatedById()][] = $share->getDashboardId();
		}

		try
		{
			ServiceLocator::getInstance()
				->get(ShareNotificationService::class)
				->notifyDisabled($this->controllerId, $dashboardIdsByAuthor)
			;
		}
		catch (\Exception $e)
		{
			Logger::logErrors([new Error($e->getMessage(), $e->getCode())], ['step' => 'notify-disabled']);
		}
	}
}
