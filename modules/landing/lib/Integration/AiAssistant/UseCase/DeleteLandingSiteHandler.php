<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Integration\AiAssistant\Dto\DeleteLandingSiteDto;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Realtime\Event;
use Bitrix\Landing\Realtime\EventAction;
use Bitrix\Landing\Realtime\EventDispatcher;
use Bitrix\Landing\Realtime\EventSource;
use Bitrix\Landing\Site;
use RuntimeException;

class DeleteLandingSiteHandler extends AbstractSiteHandler
{
	public function handle(DeleteLandingSiteDto $dto, ?int $initiatorUserId = null): array
	{
		$row = Site::getList([
			'select' => ['ID', 'TITLE'],
			'filter' => [
				'ID' => $dto->siteId,
			],
		])->fetch();

		if (!$row)
		{
			throw new RuntimeException('Site not found or access denied.');
		}

		$siteId = (int)$row['ID'];
		$siteTitle = (string)$row['TITLE'];
		$result = Site::markDelete($siteId);

		if (!$result->isSuccess())
		{
			throw new RuntimeException($this->getFirstErrorMessage($result, 'Failed to move the site to the recycle bin.'));
		}

		EventDispatcher::dispatch(
			Event::siteChanged(
				entityId: $siteId,
				action: EventAction::Delete,
				source: EventSource::AiTool,
				initiatorUserId: $this->resolveInitiatorUserId($initiatorUserId),
			)
		);

		return [
			'siteId' => $siteId,
			'siteTitle' => $siteTitle,
		];
	}

	private function resolveInitiatorUserId(?int $initiatorUserId): int
	{
		$initiatorUserId = (int)$initiatorUserId;
		if ($initiatorUserId > 0)
		{
			return $initiatorUserId;
		}

		return Manager::getUserId();
	}
}
