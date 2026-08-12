<?php

declare(strict_types=1);

namespace Bitrix\Landing\Realtime\Enricher;

use Bitrix\Landing\Realtime\Event;
use Bitrix\Landing\Realtime\EventEnricherInterface;
use Bitrix\Landing\Realtime\EventEntityType;
use Bitrix\Landing\Realtime\EventName;
use Bitrix\Landing\Site;

final class SiteEventEnricher implements EventEnricherInterface
{
	public function supports(Event $event): bool
	{
		return $event->getEventName() === EventName::SiteChanged
			&& $event->getEntityType() === EventEntityType::Site
		;
	}

	public function enrich(Event $event): Event
	{
		$row = Site::getList([
			'select' => [
				'TYPE',
				'DELETED',
			],
			'filter' => [
				'ID' => $event->getEntityId(),
				'=DELETED' => ['Y', 'N'],
				'CHECK_PERMISSIONS' => 'N',
			],
		])->fetch();

		if (!$row)
		{
			return $event;
		}

		return $event->withScope([
			'siteType' => (string)$row['TYPE'],
			'deleted' => ($row['DELETED'] === 'Y'),
		]);
	}
}
