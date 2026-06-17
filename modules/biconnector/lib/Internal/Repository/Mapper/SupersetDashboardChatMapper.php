<?php

namespace Bitrix\BIConnector\Internal\Repository\Mapper;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardChat;
use Bitrix\BIConnector\Internal\Model\EO_SupersetDashboardChat;
use Bitrix\BIConnector\Internal\Model\SupersetDashboardChatTable;

class SupersetDashboardChatMapper
{
	public function convertFromOrm(EO_SupersetDashboardChat $ormModel): SupersetDashboardChat
	{
		$dashboardChat = new SupersetDashboardChat(
			$ormModel->getDashboardId(),
			$ormModel->getChatId(),
			$ormModel->getCreatedById(),
			$ormModel->getDateCreate(),
		);

		$dashboardChat->setId($ormModel->getId());

		return $dashboardChat;
	}

	public function convertToOrm(SupersetDashboardChat $entity): EO_SupersetDashboardChat
	{
		$ormModel = $entity->getId()
			? EO_SupersetDashboardChat::wakeUp($entity->getId())
			: SupersetDashboardChatTable::createObject()
		;

		$ormModel
			->setDashboardId($entity->getDashboardId())
			->setChatId($entity->getChatId())
			->setCreatedById($entity->getCreatedById())
			->setDateCreate($entity->getDateCreate())
		;

		return $ormModel;
	}
}
