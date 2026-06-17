<?php

namespace Bitrix\Main;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\Date;

final class License
{
	private ?string $key = null;
	private ?string $region = null;

	private const DOMAINS_STORE_LICENSE = [
		'ru' => 'https://util.1c-bitrix.ru',
		'en' => 'https://util.bitrixsoft.com',
		'kz' => 'https://util.1c-bitrix.kz',
		'by' => 'https://util.1c-bitrix.by',
		'uz' => 'https://util.1c-bitrix.uz',
	];
	public const URL_BUS_EULA = [
		'ru' => 'https://www.1c-bitrix.ru/download/law/eula_bus.pdf',
		'by' => 'https://www.1c-bitrix.by/download/law/eula_bus.pdf',
		'kz' => 'https://www.1c-bitrix.kz/download/law/eula_bus.pdf',
	];
	public const URL_CP_EULA = [
		'ru' => 'https://www.1c-bitrix.ru/download/law/eula_cp.pdf',
		'by' => 'https://www.1c-bitrix.by/download/law/eula_cp.pdf',
		'kz' => 'https://www.1c-bitrix.kz/download/law/eula_cp.pdf',
		'en' => 'https://www.bitrix24.com/eula/',
		'br' => 'https://www.bitrix24.com.br/eula/',
		'fr' => 'https://www.bitrix24.fr/eula/',
		'pl' => 'https://www.bitrix24.pl/eula/',
		'it' => 'https://www.bitrix24.it/eula/',
		'la' => 'https://www.bitrix24.es/eula/',
	];
	public const URL_RENEWAL_LICENSE = [
		'com' => 'https://store.bitrix24.com/profile/license-keys.php',
		'eu' => 'https://store.bitrix24.eu/profile/license-keys.php',
		'de' => 'https://store.bitrix24.de/profile/license-keys.php',
		'ru' => 'https://www.1c-bitrix.ru/buy/products/b24.php#tab-section-2',
		'by' => 'https://www.1c-bitrix.by/buy/products/b24.php#tab-section-2',
		'kz' => 'https://www.1c-bitrix.kz/buy/products/b24.php#tab-section-2',
	];

	private const CIS = ['ru' => 1, 'by' => 1, 'kz' => 1, 'uz' => 1, 'kg' => 1, 'am' => 1, 'az' => 1, 'ge' => 1];

	public function getKey(): string
	{
		if ($this->key === null)
		{
			$licenseFile = Loader::getDocumentRoot() . '/bitrix/license_key.php';

			$LICENSE_KEY = '';
			if (file_exists($licenseFile))
			{
				include($licenseFile);
			}
			$this->key = ($LICENSE_KEY == '' || strtoupper($LICENSE_KEY) == 'DEMO' ? 'DEMO' : $LICENSE_KEY);
		}
		return $this->key;
	}

	public function getHashLicenseKey(): string
	{
		return md5($this->getKey());
	}

	public function getPublicHashKey(): string
	{
		return md5('BITRIX' . $this->getKey() . 'LICENCE');
	}

	public function isDemoKey(): bool
	{
		return $this->getKey() == 'DEMO';
	}

	public function getBuyLink(): string
	{
		return $this->getDomainStoreLicense()
			. '/key_update.php?license_key='
			. $this->getHashLicenseKey()
			. '&tobasket=y&lang='
			. LANGUAGE_ID;
	}

	public function getDocumentationLink(): string
	{
		if ($this->isCis())
		{
			return 'https://dev.1c-bitrix.ru/learning/course/index.php?COURSE_ID=135&LESSON_ID=25720';
		}

		return 'https://training.bitrix24.com/support/training/course/index.php?COURSE_ID=178&LESSON_ID=25932&LESSON_PATH=17520.17562.25930.25932';
	}

	public function getRenewalLink(): string
	{
		$region = $this->getRegion();

		if (in_array($region, ['ru', 'by', 'kz', 'de']))
		{
			return self::URL_RENEWAL_LICENSE[$region];
		}

		if (in_array($region, ['eu', 'fr', 'pl', 'it', 'uk']))
		{
			return self::URL_RENEWAL_LICENSE['eu'];
		}

		return self::URL_RENEWAL_LICENSE['com'];
	}

	public function getDomainStoreLicense(): string
	{
		$region = $this->getRegion();

		if (isset(self::DOMAINS_STORE_LICENSE[$region]))
		{
			return self::DOMAINS_STORE_LICENSE[$region];
		}

		$fallback = $this->isCis() ? 'ru' : 'en';

		return self::DOMAINS_STORE_LICENSE[$fallback];
	}

	public function isDemo(): bool
	{
		return defined('DEMO') && DEMO === 'Y';
	}

	public function isTimeBound(): bool
	{
		return defined('TIMELIMIT_EDITION') && TIMELIMIT_EDITION === 'Y';
	}

	public function isEncoded(): bool
	{
		return defined('ENCODE') && ENCODE === 'Y';
	}

	public function getExpireDate(): ?Date
	{
		$date = (int)($GLOBALS['SiteExpireDate'] ?? 0);
		if ($date > 0)
		{
			return Date::createFromTimestamp($date);
		}

		return null;
	}

	public function getSupportExpireDate(): ?Date
	{
		$date = Option::get('main', '~support_finish_date');
		if (Date::isCorrect($date, 'Y-m-d'))
		{
			return new Date($date, 'Y-m-d');
		}

		return null;
	}

	public function isCis(): bool
	{
		return isset(self::CIS[$this->getRegion()]);
	}

	public function getRegion(): ?string
	{
		if ($this->region === null)
		{
			if (Loader::includeModule('bitrix24'))
			{
				$this->region = \CBitrix24::getPortalZone();
			}
			else
			{
				$region = Option::get('main', '~PARAM_CLIENT_LANG');
				if (empty($region))
				{
					$region = $this->getRegionByVendor();
					if (empty($region))
					{
						$region = $this->getRegionByLanguage();
					}
				}

				$this->region = $region ?? '';
			}
		}

		return $this->region ?: null;
	}

	public function getEulaLink(): string
	{
		if (ModuleManager::isModuleInstalled('intranet'))
		{
			return self::URL_CP_EULA[$this->getRegion()] ?? self::URL_CP_EULA['en'];
		}

		return self::URL_BUS_EULA[$this->getRegion()] ?? self::URL_BUS_EULA['ru'];
	}

	private function getRegionByVendor(): ?string
	{
		$vendor = Option::get('main', 'vendor');
		if ($vendor === 'bitrix_portal' || $vendor === 'bitrix')
		{
			return 'en';
		}
		if ($vendor === '1c_bitrix_portal' || $vendor === '1c_bitrix')
		{
			return 'ru';
		}

		return null;
	}

	private function getRegionByLanguage(): ?string
	{
		$documentRoot = Application::getDocumentRoot();

		if (file_exists($documentRoot . '/bitrix/modules/main/lang/ua'))
		{
			return 'ua';
		}
		if (file_exists($documentRoot . '/bitrix/modules/main/lang/by'))
		{
			return 'by';
		}
		if (file_exists($documentRoot . '/bitrix/modules/main/lang/kz'))
		{
			return 'kz';
		}
		if (file_exists($documentRoot . '/bitrix/modules/main/lang/ru'))
		{
			return 'ru';
		}

		return null;
	}

	public function getPartnerId(): int
	{
		return (int)Option::get('main', '~PARAM_PARTNER_ID', 0);
	}

	public function getMaxUsers(): int
	{
		return (int)Option::get('main', 'PARAM_MAX_USERS', 0);
	}

	public function isExtraCountable(): bool
	{
		return Option::get('main', '~COUNT_EXTRA', 'N') === 'Y' && ModuleManager::isModuleInstalled('extranet');
	}

	public function getActiveUsersCount(?Date $lastLoginDate = null): int
	{
		static $cacheCount = null;

		if ($cacheCount !== null && $lastLoginDate === null)
		{
			return $cacheCount;
		}

		$connection = Application::getConnection();
		$count = 0;

		if ($lastLoginDate !== null)
		{
			// logged in today
			$filter = "AND U.LAST_LOGIN > " . $connection->getSqlHelper()->convertToDbDate($lastLoginDate);
		}
		else
		{
			// logged in total
			$filter = "AND U.LAST_LOGIN IS NOT NULL";
		}

		if (ModuleManager::isModuleInstalled("intranet"))
		{
			$sql = "
				SELECT COUNT(DISTINCT U.ID)
				FROM
					b_user U
					INNER JOIN b_user_field F ON F.ENTITY_ID = 'USER' AND F.FIELD_NAME = 'UF_DEPARTMENT'
					INNER JOIN b_utm_user UF ON
						UF.FIELD_ID = F.ID
						AND UF.VALUE_ID = U.ID
						AND UF.VALUE_INT > 0
				WHERE U.ACTIVE = 'Y'
					" . $filter . "
			";
			$count = (int)$connection->queryScalar($sql);
			$extranetGroupId = (int)Option::get('extranet', 'extranet_group');

			if ($extranetGroupId > 0 && $this->isExtraCountable())
			{
				$sql = "
						SELECT COUNT(1)
						FROM
							b_user U
							INNER JOIN b_extranet_user EU ON EU.USER_ID = U.ID AND EU.CHARGEABLE = 'Y'
							INNER JOIN b_user_group UG ON UG.USER_ID = U.ID AND UG.GROUP_ID = " . $extranetGroupId . "
							LEFT JOIN (
								SELECT UF.VALUE_ID 
								FROM 
									b_user_field F
									INNER JOIN b_utm_user UF ON UF.FIELD_ID = F.ID AND UF.VALUE_INT > 0
								WHERE F.ENTITY_ID = 'USER' AND F.FIELD_NAME = 'UF_DEPARTMENT'
							) D ON D.VALUE_ID = U.ID
						WHERE U.ACTIVE = 'Y'
							" . $filter . "
							AND D.VALUE_ID IS NULL
					";
				$count += (int)$connection->queryScalar($sql);
			}
		}

		if ($lastLoginDate === null)
		{
			$cacheCount = $count;
		}

		return $count;
	}

	/**
	 * Returns the license (edition) name, set by Update System.
	 * @return string
	 */
	public function getName(): string
	{
		return Option::get('main', '~license_name');
	}

	/**
	 * Returns the array of license (edition) codes, set by Update System.
	 * @return string[]
	 */
	public function getCodes(): array
	{
		$codes = Option::get('main', '~license_codes');

		if ($codes != '')
		{
			return explode(',', $codes);
		}

		return [];
	}
}
