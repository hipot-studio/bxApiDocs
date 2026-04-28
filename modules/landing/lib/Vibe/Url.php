<?php
declare(strict_types=1);

namespace Bitrix\Landing\Vibe;

use Bitrix\Main\Web\Uri;
use Bitrix\Landing\Site\Type;
use Bitrix\Landing\Transfer;
use Bitrix\Landing\Metrika;
use Bitrix\Landing\Vibe\Provider\AbstractVibeProvider;
use Bitrix\Intranet\Binding\Marketplace;
use Bitrix\Landing\Scope\Guard;

class Url
{
	private const MAIN_PAGE_CREATE_PATH_BASE = '/welcome/new/';
	private const MAIN_PAGE_EDIT_PATH_BASE = '/welcome/edit/';
	private const MAIN_PAGE_MARKET_CATEGORY_PATH = 'category/vibe/';

	private Vibe $vibe;
	private AbstractVibeProvider $provider;

	public function __construct(Vibe $vibe)
	{
		$this->vibe = $vibe;
		$provider = $this->vibe->getProvider();
		if (isset($provider))
		{
			$this->provider = $provider;
		}
	}

	public function getImport(): string
	{
		$guard = new Guard\Vibe();
		$guard->start();

		$importType = Type::getScopeIdForTransfer();
		$uri = new Uri(Transfer\Import\Site::getUrl($importType));
		$uri->addParams([
			'additional' => [
				'replaceSiteId' => $this->vibe->getSiteId(),
			],
		]);

		return $uri->getUri();
	}

	public function getExport(): string
	{
		$guard = new Guard\Vibe();
		$guard->start();

		$siteId = $this->vibe->getSiteId();
		$exportType = Type::getScopeIdForTransfer();

		return $siteId
			? Transfer\Export\Site::getUrl($exportType, $siteId)
			: '';
	}

	public function getCreate(): ?string
	{
		$siteId = $this->vibe->getSiteId();
		if (!isset($siteId))
		{
			return null;
		}

		$createUri = new Uri($this->enrichBaseUrl(self::MAIN_PAGE_CREATE_PATH_BASE));
		$url = new Uri(Marketplace::getMainDirectory() . self::MAIN_PAGE_MARKET_CATEGORY_PATH);
		$url->addParams([
			'create_uri' => $createUri->getUri(),
			'st[tool]' => Metrika\Tools::Vibe->value,
			'st[category]' => Metrika\Categories::Vibe->value,
			'st[event]' => Metrika\Events::openMarket->value,
			'st[status]' => Metrika\Statuses::Success->value,
		]);

		return $url->getUri();
	}

	public function getEdit(): ?string
	{
		$siteId = $this->vibe->getSiteId();
		if (!isset($siteId))
		{
			return null;
		}

		return $this->enrichBaseUrl(self::MAIN_PAGE_EDIT_PATH_BASE);
	}

	private function enrichBaseUrl(string $base): string
	{
		return $base . "{$this->vibe->getModuleId()}/{$this->vibe->getEmbedId()}/";
	}

	public function getPublic(): ?string
	{
		return isset($this->provider) ? $this->provider->getUrlPublic() : null;
	}

	public function getPartners(): ?string
	{
		// todo: do
		// 'urlPartners' => $mainPageUrl->getPublic()->getUri(),
		return null;
	}
}