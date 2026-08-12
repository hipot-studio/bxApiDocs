<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Integration\AiAssistant\Dto\CreateLandingSiteDto;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Realtime\Event;
use Bitrix\Landing\Realtime\EventAction;
use Bitrix\Landing\Realtime\EventDispatcher;
use Bitrix\Landing\Realtime\EventSource;
use Bitrix\Landing\Site;
use RuntimeException;

class CreateLandingSiteHandler extends AbstractSiteHandler
{
	public function handle(CreateLandingSiteDto $dto, ?int $initiatorUserId = null): array
	{
		$siteId = $this->createSite($dto);

		try
		{
			$landingId = $this->createInitialPage($dto, $siteId);
		}
		catch (\Throwable $e)
		{
			$this->rollbackSite($siteId);

			throw $e;
		}

		$this->saveMetaDescription($landingId, $dto);

		$published = false;
		$warning = null;

		if ($dto->publish)
		{
			$publicationResult = Site::publication($siteId, true);
			$published = $publicationResult->isSuccess();

			if (!$published)
			{
				$warning = $this->getFirstErrorMessage(
					$publicationResult,
					'The site was created, but publication failed.',
				);
			}
		}

		EventDispatcher::dispatch(
			Event::siteChanged(
				entityId: $siteId,
				action: EventAction::Create,
				source: EventSource::AiTool,
				initiatorUserId: $this->resolveInitiatorUserId($initiatorUserId),
			)
		);

		[$siteTitle, $siteCode] = $this->getSiteData($siteId, $dto);
		[$pageTitle, $pageCode] = $this->getLandingData($landingId, $dto);

		return [
			'siteId' => $siteId,
			'landingId' => $landingId,
			'siteTitle' => $siteTitle,
			'pageTitle' => $pageTitle,
			'siteCode' => $siteCode,
			'pageCode' => $pageCode,
			'published' => $published,
			'publicUrl' => $published ? $this->getLandingPublicUrl($landingId) : null,
			'previewUrl' => $this->getLandingPreviewUrl($landingId),
			'warning' => $warning,
		];
	}

	private function createSite(CreateLandingSiteDto $dto): int
	{
		$fields = [
			'TITLE' => $dto->siteTitle,
			'TYPE' => Site::getDefaultType(),
		];

		if ($dto->siteCode !== null)
		{
			$fields['CODE'] = $dto->siteCode;
		}

		if ($dto->description !== null)
		{
			$fields['DESCRIPTION'] = $dto->description;
		}

		$result = Site::add($fields);
		if (!$result->isSuccess())
		{
			throw new RuntimeException($this->getFirstErrorMessage($result, 'Failed to create a site.'));
		}

		return (int)$result->getId();
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

	private function createInitialPage(CreateLandingSiteDto $dto, int $siteId): int
	{
		$fields = [
			'SITE_ID' => $siteId,
			'TITLE' => $this->resolvePageTitle($dto),
		];

		if ($dto->pageCode !== null)
		{
			$fields['CODE'] = $dto->pageCode;
		}

		$result = Landing::add($fields);
		if (!$result->isSuccess())
		{
			throw new RuntimeException($this->getFirstErrorMessage($result, 'Failed to create the initial page.'));
		}

		return (int)$result->getId();
	}

	private function saveMetaDescription(int $landingId, CreateLandingSiteDto $dto): void
	{
		if ($dto->description === null)
		{
			return;
		}

		Landing::saveAdditionalFields($landingId, [
			'METAMAIN_USE' => 'Y',
			'METAMAIN_TITLE' => $this->resolvePageTitle($dto),
			'METAMAIN_DESCRIPTION' => $dto->description,
			'METAOG_DESCRIPTION' => $dto->description,
		]);
	}

	private function rollbackSite(int $siteId): void
	{
		Site::delete($siteId, true);
	}

	private function resolvePageTitle(CreateLandingSiteDto $dto): string
	{
		return $dto->pageTitle ?? $dto->siteTitle;
	}

	private function getSiteData(int $siteId, CreateLandingSiteDto $dto): array
	{
		$row = Site::getList([
			'select' => ['TITLE', 'CODE'],
			'filter' => [
				'ID' => $siteId,
			],
		])->fetch();

		return [
			(string)($row['TITLE'] ?? $dto->siteTitle),
			$this->normalizeCode($row['CODE'] ?? $dto->siteCode),
		];
	}

	private function getLandingData(int $landingId, CreateLandingSiteDto $dto): array
	{
		$row = Landing::getList([
			'select' => ['TITLE', 'CODE'],
			'filter' => [
				'ID' => $landingId,
			],
		])->fetch();

		return [
			(string)($row['TITLE'] ?? $this->resolvePageTitle($dto)),
			$this->normalizeCode($row['CODE'] ?? $dto->pageCode),
		];
	}

	private function getLandingPublicUrl(int $landingId): ?string
	{
		$landing = Landing::createInstance($landingId, ['skip_blocks' => true]);
		if (!$landing->exist())
		{
			return null;
		}

		return (string)$landing->getPublicUrl();
	}

	private function getLandingPreviewUrl(int $landingId): ?string
	{
		$landing = Landing::createInstance($landingId, ['skip_blocks' => true]);
		if (!$landing->exist())
		{
			return null;
		}

		$previousPreviewMode = Landing::getPreviewMode();
		Landing::setPreviewMode(true);

		try
		{
			return (string)$landing->getPublicUrl();
		}
		finally
		{
			Landing::setPreviewMode($previousPreviewMode);
		}
	}
}
