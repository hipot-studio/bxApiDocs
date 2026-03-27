<?php
namespace Bitrix\IntranetMobile\Controller;

use Bitrix\Bitrix24\Feature;
use Bitrix\IntranetMobile\Dto\LoginDto;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Main\Authentication\Internal\UserDeviceLoginTable;
use Bitrix\Main\Authentication\Internal\UserDeviceTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Service\GeoIp;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\UserAgent\Browser;
use Bitrix\Main\Web\UserAgent\DeviceType;
use Bitrix\Main\Context;
use Bitrix\Main\Service\GeoIp\Internal\GeonameTable;
use Bitrix\Intranet\Settings\Tools\ToolsManager;

class LoginHistory extends Base
{
	private const DEFAULT_LIMIT = 20;

	private const DEVICES_KEYS = [
		DeviceType::UNKNOWN =>'UNKNOWN',
		DeviceType::DESKTOP => 'DESKTOP',
		DeviceType::MOBILE_PHONE => 'MOBILE',
		DeviceType::TABLET => 'TABLET',
		DeviceType::TV => 'TV',
	];

	private $countries;

	private $currentLang;

	public function init()
	{
		parent::init();
		$this->countries = GetCountries();
		$this->currentLang = Context::getCurrent()?->getLanguageObject()?->getCode();
	}

	public function configureActions(): array
	{
		return [
			'getList' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
		];
	}

	/**
	 * @restMethod intranetmobile.loginhistory.getList
	 * @param PageNavigation|null $nav
	 * @return array
	 */
	public function getListAction(
		?PageNavigation $nav = null,
	): array
	{

		if (
			!Feature::isFeatureEnabled('user_login_history')
			|| !ToolsManager::getInstance()->checkAvailabilityByToolId('login_history')
		)
		{
			return [];
		}

		$userId = $this->getCurrentUser()?->getId();

		if (!$userId)
		{
			return [];
		}

		$query = UserDeviceLoginTable::query()
			->where('DEVICE.USER_ID', $userId)
			->registerRuntimeField(
				'DEVICE',
				new Reference(
					'DEVICE',
					UserDeviceTable::class,
					Join::on('this.DEVICE_ID', 'ref.ID'),
					['join_type' => Join::TYPE_INNER]
				)
			)
			->setSelect([
				'*',
				'DEVICE_TYPE' => 'DEVICE.DEVICE_TYPE',
				'USER_ID' => 'DEVICE.USER_ID',
				'BROWSER' => 'DEVICE.BROWSER',
				'DEVICE_PLATFORM' => 'DEVICE.PLATFORM',
			])
			->setOrder(['LOGIN_DATE' => 'DESC'])
			->setOffset($nav ? $nav->getOffset() : 0)
			->setLimit($nav ? min($nav->getLimit(), $this::DEFAULT_LIMIT) : $this::DEFAULT_LIMIT);

		$result = $query->exec()->fetchAll();

		return $this->prepareResult($result);
	}

	private function prepareResult($rawItems): array
	{
		return [
			'items' => array_map([$this, 'prepareItem'], $rawItems),
			'currentDevice' => $this->getCurrentDevice()
		];
	}

	private function prepareItem(array $item): LoginDto
	{
		return new LoginDto(
			id: $item['ID'],
			loginDate: $item['LOGIN_DATE']->getTimestamp(),
			deviceType: $this->prepareDeviceType($item['DEVICE_TYPE']),
			devicePlatform: $item['DEVICE_PLATFORM'] ?? null,
			browser: $item['BROWSER'] ?? null,
			address: $this->prepareGeolocationName($item['CITY_GEOID'], $item['REGION_GEOID'], $item['COUNTRY_ISO_CODE']),
			ip: $item['IP'] ?? null,
		);
	}

	private function getCurrentDevice(): array
	{
		$browser = Browser::detect();
		$ip = GeoIp\Manager::getRealIp();

		$address = null;
		if ($ip && Option::get('main', 'user_device_geodata', 'N') === 'Y')
		{
			$ipData = GeoIp\Manager::getDataResult($ip, '', ['cityGeonameId']);
			if ($ipData && $ipData->isSuccess())
			{
				$data = $ipData->getGeoData();
				$address = $this->prepareGeolocationName(
					$data->cityGeonameId ?? null,
					$data->subRegionGeonameId ?? $data->regionGeonameId ?? null,
					$data->countryCode ?? null,
				);
			}
		}

		return [
			'loginDate' => (new DateTime())->getTimestamp(),
			'deviceType' => $this->prepareDeviceType($browser->getDeviceType()),
			'devicePlatform' => $browser->getPlatform() ?: null,
			'browser' => $browser->getName() ?: null,
			'address' => $address,
			'ip' => $ip ?: null,
		];
	}

	private function prepareDeviceType(int $deviceType): string
	{
		return self::DEVICES_KEYS[$deviceType] ?? self::DEVICES_KEYS[DeviceType::UNKNOWN];
	}

	private function prepareGeolocationName(?int $cityID, ?int $regionID, ?string $countryCode): ?string
	{
		$geoID = [];
		$result = [];

		if ($cityID && $cityID > 0)
		{
			$geoID[$cityID] = $cityID;
		}

		if ($regionID && $regionID > 0)
		{
			$geoID[$regionID] = $regionID;
		}

		$geonames = GeonameTable::get($geoID);


		if ($cityID && $cityID > 0)
		{
			$city = $geonames[$cityID][$this->currentLang] ?? $geonames[$cityID]['en'] ?? '';

			if($city)
			{
				$result[] = $city;
			}
		}

		if ($regionID && $regionID > 0)
		{
			$region = $geonames[$regionID][$this->currentLang] ?? $geonames[$regionID]['en'] ?? '';

			if($region)
			{
				$result[] = $region;
			}
		}

		if ($countryCode)
		{
			$country = $this->countries[$countryCode]['NAME'] ?? '';

			if($country)
			{
				$result[] = $country;
			}
		}

		if (!$result)
		{
			return null;
		}

		return implode('/', $result);
	}
}
