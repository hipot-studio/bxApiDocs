<?php
namespace Bitrix\Landing\AI\SiteBuilder\Service;

use Bitrix\Landing\AI\SiteBuilder\Dto\DevContextDto;
use Bitrix\Landing\AI\SiteBuilder\Storage\ContextStorageInterface;
use Bitrix\Landing\AI\SiteBuilder\Storage\OptionContextStorage;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Site;

final class DevContextService
{
	private ContextStorageInterface $storage;

	public function __construct(?ContextStorageInterface $storage = null)
	{
		$this->storage = $storage ?? new OptionContextStorage();
	}

	public function load(): DevContextDto
	{
		return $this->storage->load();
	}

	public function save(DevContextDto $context): DevContextDto
	{
		$this->storage->save($context);

		return $context;
	}

	public function saveSiteId(int $siteId): DevContextDto
	{
		$currentContext = $this->load();

		return $this->save($currentContext->withSiteId($siteId));
	}

	public function saveLandingId(int $landingId): DevContextDto
	{
		$currentContext = $this->load();

		return $this->save($currentContext->withLandingId($landingId));
	}

	public function reset(): DevContextDto
	{
		$this->storage->reset();

		return new DevContextDto();
	}

	public function ensureExisting(): DevContextDto
	{
		$currentContext = $this->load();
		$siteId = $currentContext->getSiteId();
		$landingId = $currentContext->getLandingId();
		$isChanged = false;

		if ($siteId > 0 && !$this->siteExists($siteId))
		{
			$siteId = 0;
			$isChanged = true;
		}

		if ($landingId > 0 && !$this->landingExists($landingId))
		{
			$landingId = 0;
			$isChanged = true;
		}

		if (!$isChanged)
		{
			return $currentContext;
		}

		return $this->save(new DevContextDto($siteId, $landingId));
	}

	public function getEditorUrl(DevContextDto $context): string
	{
		if (!$context->hasSite())
		{
			return '';
		}

		return '/sites/site/' . $context->getSiteId() . '/view/' . $context->getLandingId() . '/';
	}

	public function getPublicUrl(DevContextDto $context): string
	{
		if (!$context->hasSite())
		{
			return '';
		}

		$landing = Landing::createInstance($context->getLandingId(), ['skip_blocks' => true]);
		if ($landing->exist())
		{
			$url = trim((string)$landing->getPublicUrl());
			if ($url !== '')
			{
				return $url;
			}
		}

		return '/pub/site/' . $context->getSiteId() . '/';
	}

	public function getSiteLinks(DevContextDto $context): array
	{
		return [
			'editorUrl' => $this->getEditorUrl($context),
			'publicUrl' => $this->getPublicUrl($context),
		];
	}

	private function siteExists(int $siteId): bool
	{
		return (bool)Site::getList([
			'select' => ['ID'],
			'filter' => [
				'=ID' => $siteId,
				'CHECK_PERMISSIONS' => 'N',
			],
		])->fetch();
	}

	private function landingExists(int $landingId): bool
	{
		return (bool)Landing::getList([
			'select' => ['ID'],
			'filter' => [
				'=ID' => $landingId,
				'CHECK_PERMISSIONS' => 'N',
			],
		])->fetch();
	}
}
