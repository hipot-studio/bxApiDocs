<?php

namespace Bitrix\BIConnector\Public\Provider;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardChat;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardChatRepository;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

class SupersetDashboardChatProvider
{
	public function __construct(private readonly SupersetDashboardChatRepository $repository)
	{
	}

	/**
	 * @param int $id
	 * @return SupersetDashboardChat|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getById(int $id): ?SupersetDashboardChat
	{
		return $this->repository->getById($id);
	}

	/**
	 * @param int $dashboardId
	 * @return SupersetDashboardChat|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getByDashboardId(int $dashboardId): ?SupersetDashboardChat
	{
		return $this->repository->getByDashboardId($dashboardId);
	}

	/**
	 * @param int $chatId
	 * @return SupersetDashboardChat|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getByChatId(int $chatId): ?SupersetDashboardChat
	{
		return $this->repository->getByChatId($chatId);
	}
}
