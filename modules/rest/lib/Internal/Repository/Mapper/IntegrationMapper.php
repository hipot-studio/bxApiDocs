<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository\Mapper;

use Bitrix\Rest\Internal\Entity\Integration\Integration;
use Bitrix\Rest\Preset\EO_Integration;
use Bitrix\Rest\Preset\IntegrationTable;

class IntegrationMapper
{
	public function convertFromOrm(EO_Integration $ormModel): Integration
	{
		$integration = new Integration(
			userId: (int)$ormModel->getUserId(),
			elementCode: $ormModel->getElementCode(),
			title: $ormModel->getTitle(),
			passwordId: $ormModel->getPasswordId() ? (int)$ormModel->getPasswordId() : null,
			appId: $ormModel->getAppId() ? (int)$ormModel->getAppId() : null,
			scope: $ormModel->getScope(),
			query: $ormModel->getQuery(),
			outgoingEvents: $ormModel->getOutgoingEvents(),
			outgoingNeeded: ($ormModel->getOutgoingNeeded() ?? '') === 'Y',
			outgoingHandlerUrl: $ormModel->getOutgoingHandlerUrl(),
			widgetNeeded: ($ormModel->getWidgetNeeded() ?? '') === 'Y',
			widgetHandlerUrl: $ormModel->getWidgetHandlerUrl(),
			widgetList: $ormModel->getWidgetList(),
			applicationToken: $ormModel->getApplicationToken(),
			applicationNeeded: ($ormModel->getApplicationNeeded() ?? '') === 'Y',
			applicationOnlyApi: ($ormModel->getApplicationOnlyApi() ?? '') === 'Y',
			botId: $ormModel->getBotId() ? (int)$ormModel->getBotId() : null,
			botHandlerUrl: $ormModel->getBotHandlerUrl(),
		);

		$integration->setId($ormModel->getId());

		return $integration;
	}

	public function convertToOrm(Integration $entity): EO_Integration
	{
		$ormModel = $entity->getId()
			? EO_Integration::wakeUp($entity->getId())
			: IntegrationTable::createObject()
		;

		$ormModel
			->setUserId($entity->getUserId())
			->setElementCode($entity->getElementCode())
			->setTitle($entity->getTitle())
			->setPasswordId($entity->getPasswordId())
			->setAppId($entity->getAppId())
			->setScope($entity->getScope())
			->setQuery($entity->getQuery())
			->setOutgoingEvents($entity->getOutgoingEvents())
			->setOutgoingNeeded($entity->isOutgoingNeeded() ? 'Y' : 'N')
			->setOutgoingHandlerUrl($entity->getOutgoingHandlerUrl())
			->setWidgetNeeded($entity->isWidgetNeeded() ? 'Y' : 'N')
			->setWidgetHandlerUrl($entity->getWidgetHandlerUrl())
			->setWidgetList($entity->getWidgetList())
			->setApplicationToken($entity->getApplicationToken())
			->setApplicationNeeded($entity->isApplicationNeeded() ? 'Y' : 'N')
			->setApplicationOnlyApi($entity->isApplicationOnlyApi() ? 'Y' : 'N')
			->setBotId($entity->getBotId())
			->setBotHandlerUrl($entity->getBotHandlerUrl())
		;

		return $ormModel;
	}
}
