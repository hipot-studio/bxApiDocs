<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Integration\AiAssistant\Dto\PublishLandingSiteDto;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Realtime\Event;
use Bitrix\Landing\Realtime\EventAction;
use Bitrix\Landing\Realtime\EventDispatcher;
use Bitrix\Landing\Realtime\EventSource;
use Bitrix\Landing\Site;
use Bitrix\Main\Result;
use RuntimeException;

class PublishLandingSiteHandler extends AbstractSiteHandler
{
	public function handle(PublishLandingSiteDto $dto, ?int $initiatorUserId = null): array
	{
		$siteId = (int)$dto->siteId;
		$row = $this->loadSite($siteId);
		if (!$row)
		{
			throw new RuntimeException('Site not found or access denied.');
		}

		$result = $dto->isPublishAction()
			? $this->publishSite($siteId)
			: $this->unpublishSite($siteId);

		if (!$result->isSuccess())
		{
			throw new RuntimeException(
				$this->getFirstErrorMessage($result, 'Failed to update site publication status.'),
			);
		}

		$this->dispatchSiteChanged($siteId, $this->resolveInitiatorUserId($initiatorUserId));

		$response = [
			'siteTitle' => (string)$row['TITLE'],
			'status' => $dto->getStatus(),
		];

		if ($dto->isPublishAction())
		{
			$publicUrl = $this->getSitePublicUrl($siteId);
			if ($publicUrl !== null)
			{
				$response['publicUrl'] = $publicUrl;
			}
		}

		return $response;
	}

	protected function loadSite(int $siteId): ?array
	{
		$row = Site::getList([
			'select' => ['ID', 'TITLE'],
			'filter' => [
				'=ID' => $siteId,
			],
		])->fetch();

		return is_array($row) ? $row : null;
	}

	protected function publishSite(int $siteId): Result
	{
		return Site::publication($siteId, true);
	}

	protected function unpublishSite(int $siteId): Result
	{
		return Site::unpublic($siteId);
	}

	protected function getSitePublicUrl(int $siteId): ?string
	{
		$publicUrl = Site::getPublicUrl($siteId);
		if (!is_scalar($publicUrl))
		{
			return null;
		}

		$publicUrl = trim((string)$publicUrl);

		return $publicUrl !== '' ? $publicUrl : null;
	}

	protected function dispatchSiteChanged(int $siteId, int $initiatorUserId): void
	{
		EventDispatcher::dispatch(
			Event::siteChanged(
				entityId: $siteId,
				action: EventAction::Update,
				source: EventSource::AiTool,
				initiatorUserId: $initiatorUserId,
			),
		);
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
