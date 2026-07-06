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
			scope: $this->parseArrayField($ormModel->getScope()),
			query: $ormModel->getQuery(),
			outgoingEvents: $this->parseArrayField($ormModel->getOutgoingEvents()),
			outgoingNeeded: ($ormModel->getOutgoingNeeded() ?? '') === 'Y',
			outgoingHandlerUrl: $ormModel->getOutgoingHandlerUrl(),
			widgetNeeded: ($ormModel->getWidgetNeeded() ?? '') === 'Y',
			widgetHandlerUrl: $ormModel->getWidgetHandlerUrl(),
			widgetList: $this->parseArrayField($ormModel->getWidgetList()),
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

	public function convertFromArray(array $ormData): Integration
	{
		$integration = new Integration(
			userId: (int)($ormData['USER_ID'] ?? 0),
			elementCode: $ormData['ELEMENT_CODE'] ?? '',
			title: $ormData['TITLE'] ?? '',
			passwordId: !empty($ormData['PASSWORD_ID']) ? (int)$ormData['PASSWORD_ID'] : null,
			appId: !empty($ormData['APP_ID']) ? (int)$ormData['APP_ID'] : null,
			scope: $this->parseArrayField($ormData['SCOPE'] ?? []),
			query: $ormData['QUERY'] ?? null,
			outgoingEvents: $this->parseArrayField($ormData['OUTGOING_EVENTS'] ?? []),
			outgoingNeeded: ($ormData['OUTGOING_NEEDED'] ?? '') === 'Y',
			outgoingHandlerUrl: $ormData['OUTGOING_HANDLER_URL'] ?? null,
			widgetNeeded: ($ormData['WIDGET_NEEDED'] ?? '') === 'Y',
			widgetHandlerUrl: $ormData['WIDGET_HANDLER_URL'] ?? null,
			widgetList: $this->parseArrayField($ormData['WIDGET_LIST'] ?? []),
			applicationToken: $ormData['APPLICATION_TOKEN'] ?? null,
			applicationNeeded: ($ormData['APPLICATION_NEEDED'] ?? '') === 'Y',
			applicationOnlyApi: ($ormData['APPLICATION_ONLY_API'] ?? '') === 'Y',
			botId: !empty($ormData['BOT_ID']) ? (int)$ormData['BOT_ID'] : null,
			botHandlerUrl: $ormData['BOT_HANDLER_URL'] ?? null,
		);

		$integration->setId((int)$ormData['ID']);

		return $integration;
	}

	public function convertToArray(Integration $integration): array
	{
		return [
			'USER_ID' => $integration->getUserId(),
			'ELEMENT_CODE' => $integration->getElementCode(),
			'TITLE' => $integration->getTitle(),
			'PASSWORD_ID' => $integration->getPasswordId(),
			'APP_ID' => (int)$integration->getAppId(),
			'SCOPE' => $integration->getScope(),
			'QUERY' => $integration->getQuery(),
			'OUTGOING_EVENTS' => $integration->getOutgoingEvents(),
			'OUTGOING_NEEDED' => $integration->isOutgoingNeeded() ? 'Y' : 'N',
			'OUTGOING_HANDLER_URL' => $integration->getOutgoingHandlerUrl(),
			'WIDGET_NEEDED' => $integration->isWidgetNeeded() ? 'Y' : 'N',
			'WIDGET_HANDLER_URL' => $integration->getWidgetHandlerUrl(),
			'WIDGET_LIST' => $integration->getWidgetList(),
			'APPLICATION_TOKEN' => $integration->getApplicationToken(),
			'APPLICATION_NEEDED' => $integration->isApplicationNeeded() ? 'Y' : 'N',
			'APPLICATION_ONLY_API' => $integration->isApplicationOnlyApi() ? 'Y' : 'N',
			'BOT_ID' => (int)$integration->getBotId(),
			'BOT_HANDLER_URL' => $integration->getBotHandlerUrl(),
		];
	}

	private function parseArrayField(mixed $value): array
	{
		if (is_array($value))
		{
			return array_filter($value);
		}

		if (is_string($value) && $value !== '')
		{
			return array_filter(explode(',', $value));
		}

		return [];
	}
}
