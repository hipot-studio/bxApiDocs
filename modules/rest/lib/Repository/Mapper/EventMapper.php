<?php

namespace Bitrix\Rest\Repository\Mapper;

use Bitrix\Rest\EO_Event;
use Bitrix\Rest\Internal\Entity\Event;

class EventMapper
{
	public function convertFromOrm(EO_Event $event): Event
	{
		return new Event(
			id: $event->getId() > 0 ? $event->getId() : null,
			appId: $event->getAppId() > 0 ? $event->getAppId() : null,
			name: $event->getEventName(),
			handler: $event->getEventHandler(),
			userId: $event->getUserId() > 0 ? $event->getUserId() : null,
			title: $event->getTitle() !== '' ? $event->getTitle() : null,
			comment: $event->getComment() !== '' ? $event->getComment() : null,
			applicationToken: $event->getApplicationToken() !== '' ? $event->getApplicationToken() : null,
			connectorId: $event->getConnectorId(),
			integrationId: $event->getIntegrationId() > 0 ? $event->getIntegrationId() : null,
			options: $event->getOptions(),
			version: $event->getVersion(),
			jsonOptions: $event->getJsonOptions(),
		);
	}

	public function convertToFields(Event $event): array
	{
		$fields = [];
		if ($event->getId() !== null)
		{
			$fields['ID'] = $event->getId();
		}

		$fields['APP_ID'] = $event->getAppId();
		$fields['EVENT_NAME'] = $event->getName();
		$fields['EVENT_HANDLER'] = $event->getHandler();
		$fields['USER_ID'] = $event->getUserId();
		$fields['TITLE'] = $event->getTitle();
		$fields['COMMENT'] = $event->getComment();
		$fields['APPLICATION_TOKEN'] = $event->getApplicationToken();
		$fields['CONNECTOR_ID'] = $event->getConnectorId();
		$fields['INTEGRATION_ID'] = $event->getIntegrationId();
		$fields['OPTIONS'] = $event->getOptions();
		$fields['VERSION'] = $event->getVersion();
		$fields['JSON_OPTIONS'] = $event->getJsonOptions();

		return $fields;
	}
}