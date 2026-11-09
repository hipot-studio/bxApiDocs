<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Copilot\Services\CreateAiSiteChecker;
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

		$response = [
			'success' => true,
			'restored' => true,
			'siteId' => $siteId,
			'siteTitle' => $siteTitle,
		];

		$siteUrl = $this->resolveSiteUrl($siteId);
		if ($siteUrl !== null)
		{
			$response['siteUrl'] = $siteUrl;
		}

		return $response;
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

	/**
	 * The link search_landing_sites gives for the same site: the editor of the first page of an AI site, the
	 * pages of the site otherwise. The site is already restored here, so a failure only leaves the link out.
	 */
	private function resolveSiteUrl(int $siteId): ?string
	{
		try
		{
			$pageId = $this->resolveFirstPageId($siteId);
			if ($pageId > 0 && (new CreateAiSiteChecker())->isSiteCreated($siteId))
			{
				return $this->getSitePageEditorUrl($siteId, $pageId);
			}

			return $this->getSitePagesListUrl($siteId);
		}
		catch (\Throwable)
		{
			return null;
		}
	}
}
