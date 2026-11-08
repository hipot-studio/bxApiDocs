<?php

namespace Bitrix\BIConnector\Internal\Services\Share;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

/**
 * Tells link authors that their links were switched off. The controller who did it is never notified.
 */
class ShareNotificationService
{
	/**
	 * @param array<int, int[]> $dashboardIdsByAuthor
	 */
	public function notifyDisabled(int $controllerId, array $dashboardIdsByAuthor): void
	{
		if (!$this->isImAvailable())
		{
			return;
		}

		unset($dashboardIdsByAuthor[$controllerId]);
		if (empty($dashboardIdsByAuthor))
		{
			return;
		}

		$allDashboardIds = array_merge(...array_values($dashboardIdsByAuthor));
		$links = $this->getDashboardLinks($allDashboardIds);
		if (empty($links))
		{
			return;
		}

		foreach ($dashboardIdsByAuthor as $authorId => $dashboardIds)
		{
			$authorLinks = array_values(array_intersect_key($links, array_flip($dashboardIds)));
			if (empty($authorLinks))
			{
				continue;
			}

			$messageCallback = count($authorLinks) === 1
				? $this->getSingleDisabledMessage($authorLinks[0])
				: $this->getManyDisabledMessage($authorLinks)
			;

			$this->sendToUser((int)$authorId, $controllerId, $messageCallback);
		}
	}

	/**
	 * @param int[] $authorIds
	 */
	public function notifyForbidden(int $controllerId, int $dashboardId, array $authorIds): void
	{
		if (!$this->isImAvailable())
		{
			return;
		}

		$authorIds = array_diff(array_unique(array_map('intval', $authorIds)), [$controllerId]);
		if (empty($authorIds))
		{
			return;
		}

		$link = $this->getDashboardLinks([$dashboardId])[$dashboardId] ?? null;
		if ($link === null)
		{
			return;
		}

		$messageCallback = $this->getSingleDisabledMessage($link);

		foreach ($authorIds as $authorId)
		{
			$this->sendToUser($authorId, $controllerId, $messageCallback);
		}
	}

	private function getSingleDisabledMessage(string $link): \Closure
	{
		return static fn(?string $languageId = null) => Loc::getMessage(
			'BICONNECTOR_SHARE_NOTIFICATION_DISABLED',
			['#DASHBOARD#' => $link],
			$languageId,
		);
	}

	/**
	 * @param string[] $links
	 */
	private function getManyDisabledMessage(array $links): \Closure
	{
		return static function (?string $languageId = null) use ($links) {
			$items = array_map(
				static fn(string $link) => Loc::getMessage(
					'BICONNECTOR_SHARE_NOTIFICATION_DASHBOARD_ITEM',
					['#DASHBOARD#' => $link],
					$languageId,
				) ?? $link,
				$links,
			);

			return Loc::getMessage(
				'BICONNECTOR_SHARE_NOTIFICATION_DISABLED_MANY',
				['#DASHBOARDS#' => implode(', ', $items)],
				$languageId,
			);
		};
	}

	protected function isImAvailable(): bool
	{
		return Loader::includeModule('im');
	}

	protected function send(array $fields): void
	{
		\CIMNotify::Add($fields);
	}

	private function sendToUser(int $userId, int $controllerId, callable $messageCallback): void
	{
		$this->send([
			'TO_USER_ID' => $userId,
			'FROM_USER_ID' => $controllerId,
			'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
			'NOTIFY_MODULE' => 'biconnector',
			'NOTIFY_TITLE' => static fn(?string $languageId = null) => Loc::getMessage(
				'BICONNECTOR_SHARE_NOTIFICATION_TITLE',
				language: $languageId,
			),
			'NOTIFY_MESSAGE' => $messageCallback,
		]);
	}

	/**
	 * @param int[] $dashboardIds
	 * @return array<int, string> dashboard id => html link
	 */
	private function getDashboardLinks(array $dashboardIds): array
	{
		$dashboardIds = array_unique(array_map('intval', $dashboardIds));
		if (empty($dashboardIds))
		{
			return [];
		}

		$dashboards = SupersetDashboardTable::getList([
			'select' => ['ID', 'TITLE'],
			'filter' => ['@ID' => $dashboardIds],
			'order' => ['ID' => 'ASC'],
		])->fetchCollection();

		$links = [];
		foreach ($dashboards as $dashboard)
		{
			$url = htmlspecialcharsbx($dashboard->getDetailUrl()->getUri());
			$title = htmlspecialcharsbx($dashboard->getTitle());
			$links[$dashboard->getId()] = "<a href=\"{$url}\">{$title}</a>";
		}

		return $links;
	}
}
