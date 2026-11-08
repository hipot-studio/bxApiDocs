<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Market;

use Bitrix\Crm\Integration\Market\Router;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Uri;

class MarketplaceGateway
{
	public function isMarketAvailable(): bool
	{
		return Loader::includeModule('market');
	}

	public function isCloud(): bool
	{
		return Loader::includeModule('bitrix24');
	}
	public function getPlacementUrl(): Uri
	{
		return new Uri(Router::getPlacementPath());
	}

	public function getCategoryUrl(string $category): Uri
	{
		return new Uri(Router::getCategoryPath($category));
	}

	public function getMigrationUrl(bool $isMarketAvailable): Uri
	{
		$path = $isMarketAvailable
			? Router::getBasePath() . 'collection/migration_crm/'
			: Router::getCategoryPath('migration');

		return new Uri($path);
	}
}
