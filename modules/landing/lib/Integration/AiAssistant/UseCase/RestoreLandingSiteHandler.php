<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Integration\AiAssistant\Dto\RestoreLandingSiteDto;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Realtime\Event;
use Bitrix\Landing\Realtime\EventAction;
use Bitrix\Landing\Realtime\EventDispatcher;
use Bitrix\Landing\Realtime\EventSource;
use Bitrix\Landing\Site;
use RuntimeException;

class RestoreLandingSiteHandler extends AbstractSiteHandler
{
	public function handle(RestoreLandingSiteDto $dto, ?int $initiatorUserId = null): array
	{
		$row = Site::getList([
			'select' => ['ID', 'TITLE'],
			'filter' => [
				'ID' => $dto->siteId,
				'=DELETED' => 'Y',
			],
		])->fetch();

		if (!$row)
		{
			throw new RuntimeException('Deleted site not found or access denied.');
		}

		$siteId = (int)$row['ID'];
		$siteTitle = (string)$row['TITLE'];
		$result = Site::markUnDelete($siteId);

		if (!$result->isSuccess())
		{
			throw new RuntimeException($this->getFirstErrorMessage($result, 'Failed to restore the site from the recycle bin.'));
		}

		EventDispatcher::dispatch(
			Event::siteChanged(
				entityId: $siteId,
				action: EventAction::Update,
				source: EventSource::AiTool,
				initiatorUserId: $this->resolveInitiatorUserId($initiatorUserId),
			),
		);

		return [
			'success' => true,
			'restored' => true,
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
