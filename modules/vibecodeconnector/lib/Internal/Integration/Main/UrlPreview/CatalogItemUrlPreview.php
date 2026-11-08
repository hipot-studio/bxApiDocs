<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Main\UrlPreview;

use Bitrix\Im\V2\Entity\Url\RichData;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Web\Uri;
use Bitrix\Vibecodeconnector\Internal\Dto\Catalog\Preview\CatalogItemPreview;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\ImGate;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Preview\CatalogItemPreviewService;

class CatalogItemUrlPreview
{
	private const CACHE_TAG = 'vibecodeconnector_catalog_item_preview';

	public static function checkUserReadAccess(array $params, int $userId = 0): bool
	{
		$catalogItemId = static::resolveCatalogItemId($params);

		return $catalogItemId > 0
			&& $userId > 0
			&& static::service()->isPreviewAvailableToUser($catalogItemId, $userId);
	}

	public static function getImAttach(array $params): \CIMMessageParamAttach|false
	{
		$preview = static::resolvePreview($params);

		return $preview === null ? false : static::imGate()->buildAttach($preview);
	}

	public static function getImRich(array $params): RichData|false
	{
		$preview = static::resolvePreview($params);

		return $preview === null ? false : static::imGate()->buildRichData($preview);
	}

	public static function getCacheTag(): string
	{
		return self::CACHE_TAG;
	}

	protected static function resolvePreview(array $params): ?CatalogItemPreview
	{
		$catalogItemId = static::resolveCatalogItemId($params);
		$userId = static::getCurrentUserId();

		return $catalogItemId > 0 && $userId > 0
			? static::service()->getPreview($catalogItemId, $userId)
			: null;
	}

	protected static function resolveCatalogItemId(array $params): int
	{
		$idFromLinkPath = static::parseCatalogItemIdFromPath((string)($params['URL'] ?? ''));

		return $idFromLinkPath ?? (int)($params['catalogItemId'] ?? 0);
	}

	protected static function parseCatalogItemIdFromPath(string $url): ?int
	{
		if ($url === '')
		{
			return null;
		}

		$pattern = '#^' . preg_quote(CatalogItemPreviewService::PAGE_PATH_PREFIX, '#') . '(\d+)/?$#';

		return preg_match($pattern, (new Uri($url))->getPath(), $matches) === 1
			? (int)$matches[1]
			: null;
	}

	protected static function getCurrentUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	protected static function service(): CatalogItemPreviewService
	{
		return ServiceLocator::getInstance()->get(CatalogItemPreviewService::class);
	}

	protected static function imGate(): ImGate
	{
		return new ImGate();
	}
}
