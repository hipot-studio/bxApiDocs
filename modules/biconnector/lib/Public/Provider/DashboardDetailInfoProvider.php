<?php
declare(strict_types=1);

namespace Bitrix\BIConnector\Public\Provider;

use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Market\AppDetail;
use Bitrix\BIConnector\Internal\Entity\ValueObject\DashboardDetailInfo\DashboardInfo;
use Bitrix\BIConnector\Internal\Repository\Mapper\SupersetDashboardInfoGalleryMapper;
use Bitrix\BIConnector\Internal\Repository\Mapper\SupersetDashboardInfoMapper;
use Bitrix\BIConnector\Internal\Repository\Mapper\SupersetDashboardViewMapper;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardInfoGalleryRepository;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardInfoRepository;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardViewRepository;
use Bitrix\Main\Application;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\Loader;
use Bitrix\Rest\AppLogTable;
use Bitrix\Rest\AppTable;

final class DashboardDetailInfoProvider
{
	private const MARKET_APP_INFO_CACHE_TTL = 86400;
	private const MARKET_APP_INFO_CACHE_DIR = '/biconnector/dashboard/detail-info/market-app/';

	private readonly SupersetDashboardInfoRepository $dashboardInfoRepository;
	private readonly SupersetDashboardInfoGalleryRepository $dashboardInfoGalleryRepository;
	private readonly SupersetDashboardViewRepository $dashboardViewRepository;

	public function __construct()
	{
		$this->dashboardInfoRepository = new SupersetDashboardInfoRepository(new SupersetDashboardInfoMapper());
		$this->dashboardInfoGalleryRepository = new SupersetDashboardInfoGalleryRepository(new SupersetDashboardInfoGalleryMapper());
		$this->dashboardViewRepository = new SupersetDashboardViewRepository(new SupersetDashboardViewMapper());
	}

	public function getByDashboard(Dashboard $dashboard, bool $refreshMarket = false): ?DashboardInfo
	{
		$dashboardId = $dashboard->getId();
		$ormObject = $dashboard->getOrmObject();
		if (!$ormObject)
		{
			return null;
		}

		$type = $ormObject->getType();
		$viewsCount = $this->dashboardViewRepository->countViews($dashboardId);
		$nativeFilterFields = $dashboard->getNativeFilterFields();
		$filterPeriod = $nativeFilterFields['FILTER_PERIOD'] ?? null;
		$dateFilterStart = $this->prepareDateFilterValue($nativeFilterFields['DATE_FILTER_START'] ?? null);
		$dateFilterEnd = $this->prepareDateFilterValue($nativeFilterFields['DATE_FILTER_END'] ?? null);
		$includeLastFilterDate = (bool)($nativeFilterFields['INCLUDE_LAST_FILTER_DATE'] ?? false);

		if ($type === SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM)
		{
			$dashboardInfo = $this->dashboardInfoRepository->getByDashboardId($dashboardId);
			if (!$dashboardInfo)
			{
				return null;
			}

			$images = [];
			$galleryCollection = $this->dashboardInfoGalleryRepository->getByDashboardInfoId($dashboardInfo->getId());
			foreach ($galleryCollection as $item)
			{
				$image = \CFile::GetFileArray($item->getImageId());
				if (is_array($image))
				{
					$images[] = $image;
				}
			}

			return new DashboardInfo(
				title: $dashboard->getTitle(),
				type: $type,
				viewsCount: $viewsCount,
				partnerName: null,
				icon: $dashboardInfo->getImageId() ? (string)$dashboardInfo->getImageId() : null,
				images: $images,
				description: $dashboardInfo->getDescription(),
				publishedById: $dashboardInfo->getPublishedById(),
				publishedDate: $dashboardInfo->getPublishedDate(),
				updatedById: $dashboardInfo->getUpdatedById(),
				updatedDate: $dashboardInfo->getUpdatedDate(),
				filterPeriod: $filterPeriod,
				dateFilterStart: $dateFilterStart,
				dateFilterEnd: $dateFilterEnd,
				includeLastFilterDate: $includeLastFilterDate,
			);
		}

		if (
			(
				$type === SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM
				|| $type === SupersetDashboardTable::DASHBOARD_TYPE_MARKET
			)
			&& Loader::includeModule('market')
		)
		{
			$appCode = $ormObject->getAppId();
			if (!$appCode)
			{
				return null;
			}

			$appDetail = $this->getMarketAppDetail($appCode, $refreshMarket);
			$iconUrl = $this->normalizeExternalImageUrl($appDetail->getIcon());
			$imageUrls = $this->normalizeExternalImageUrls($appDetail->getImages());
			$publishedById = $this->getMarketDashboardInstallUserId($appCode) ?? $ormObject->getCreatedById();

			return new DashboardInfo(
				title: $dashboard->getTitle(),
				type: $type,
				viewsCount: $viewsCount,
				partnerName: $appDetail->getPartnerName(),
				icon: $iconUrl,
				images: $imageUrls,
				description: $appDetail->getDescription(),
				publishedById: $publishedById,
				publishedDate: $ormObject->getDateCreate(),
				updatedById: null,
				updatedDate: $ormObject->getDateModify(),
				filterPeriod: $filterPeriod,
				dateFilterStart: $dateFilterStart,
				dateFilterEnd: $dateFilterEnd,
				includeLastFilterDate: $includeLastFilterDate,
				appCode: $appCode,
				ratingInfo: $appDetail->getReviews(),
			);
		}

		return null;
	}

	private function getMarketAppDetail(string $appCode, bool $refreshMarket = false): AppDetail
	{
		$cache = Application::getInstance()->getCache();
		$cacheId = 'biconnector_market_app_detail_' . md5($appCode);
		$cacheDir = self::MARKET_APP_INFO_CACHE_DIR . md5($appCode) . '/';

		if ($refreshMarket)
		{
			$cache->cleanDir($cacheDir);
		}

		if ($cache->initCache(self::MARKET_APP_INFO_CACHE_TTL, $cacheId, $cacheDir))
		{
			$cachedValue = $cache->getVars();

			return is_array($cachedValue) ? AppDetail::createFromInfo($cachedValue) : new AppDetail();
		}

		$appDetail = new AppDetail($appCode);
		$info = $appDetail->getInfo();
		if (!is_array($info) || empty($info))
		{
			return $appDetail;
		}

		$cache->startDataCache();
		$cache->endDataCache($info);

		return $appDetail;
	}

	private function getMarketDashboardInstallUserId(string $appCode): ?int
	{
		if ($appCode === '' || !Loader::includeModule('rest'))
		{
			return null;
		}

		$row = AppLogTable::getList([
			'select' => ['USER_ID'],
			'filter' => [
				'=ACTION_TYPE' => AppLogTable::ACTION_TYPE_INSTALL,
				'=APP.CODE' => $appCode,
			],
			'order' => [
				'ID' => 'ASC',
			],
			'limit' => 1,
			'runtime' => [
				new ReferenceField(
					'APP',
					AppTable::class,
					['=ref.ID' => 'this.APP_ID'],
					['join_type' => 'INNER']
				),
			],
			'cache' => [
				'ttl' => 86000,
				'cache_joins' => true,
			]
		])->fetch();

		$userId = (int)($row['USER_ID'] ?? 0);

		return $userId > 0 ? $userId : null;
	}

	private function normalizeExternalImageUrls(mixed $images): array
	{
		if (!is_array($images))
		{
			return [];
		}

		$result = [];
		foreach ($images as $image)
		{
			$imageUrl = null;
			if (is_string($image))
			{
				$imageUrl = $image;
			}
			elseif (is_array($image))
			{
				$imageUrl = is_string($image['SRC'] ?? null)
					? $image['SRC']
					: (is_string($image['src'] ?? null) ? $image['src'] : null)
				;
			}

			$normalizedUrl = $this->normalizeExternalImageUrl($imageUrl);
			if ($normalizedUrl !== null)
			{
				$result[] = $normalizedUrl;
			}
		}

		return $result;
	}

	private function normalizeExternalImageUrl(mixed $url): ?string
	{
		if (!is_string($url))
		{
			return null;
		}

		$url = trim($url);
		if ($url === '')
		{
			return null;
		}

		if (str_starts_with($url, '//'))
		{
			$parsedUrl = parse_url('https:' . $url);
			$host = is_array($parsedUrl) ? (string)($parsedUrl['host'] ?? '') : '';

			return $host !== '' ? $url : null;
		}

		$parsedUrl = parse_url($url);
		if (!is_array($parsedUrl))
		{
			return null;
		}

		$scheme = strtolower((string)($parsedUrl['scheme'] ?? ''));
		if ($scheme !== 'http' && $scheme !== 'https')
		{
			return null;
		}

		$host = (string)($parsedUrl['host'] ?? '');
		if ($host === '')
		{
			return null;
		}

		if (filter_var($url, FILTER_VALIDATE_URL) === false)
		{
			return null;
		}

		return $url;
	}

	private function prepareDateFilterValue(mixed $value): ?string
	{
		if ($value === null || $value === '')
		{
			return null;
		}

		return (string)$value;
	}
}
