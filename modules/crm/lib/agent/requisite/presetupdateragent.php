<?php
namespace Bitrix\Crm\Agent\Requisite;

use Bitrix\Crm\EntityPreset;
use Bitrix\Crm\EntityRequisite;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Application;
use Bitrix\Crm\Agent\AgentBase;
use Bitrix\Main\Config\Option;
use Bitrix\Main\SystemException;
use Psr\Log\LoggerInterface;

class PresetUpdaterAgent extends AgentBase
{

	private LoggerInterface $logger;
	private static ?PresetUpdaterAgent $instance = null;

	private function __construct()
	{
		$this->logger = Container::getInstance()->getLogger('Agent');
	}

	public static function getInstance()
	{
		if(self::$instance === null)
		{
			self::$instance = new static();
		}
		return self::$instance;
	}

	protected static function logError(string $errorMessage): void
	{
		static::getInstance()->logger->error("PresetUpdaterAgent: $errorMessage");
	}

	protected static function logException(string $errorMessage): void
	{
		static::getInstance()->logger->critical("PresetUpdaterAgent: $errorMessage");
	}

	private static function checkRequisitesByPresetIds (array $presetIds): bool
	{
		return (bool)EntityRequisite::getSingleInstance()->getList([
			'filter' => ['=PRESET_ID' => $presetIds],
			'select' => ['ID'],
			'limit'  => 1,
		])->Fetch();
	}

	protected static function isCountryRequisitesExists(int $countryId): bool
	{
		$chunkSize = 200;

		$presetListResult = EntityPreset::getSingleInstance()->getList([
			'filter' => [
				'=ENTITY_TYPE_ID' => EntityPreset::Requisite,
				'=COUNTRY_ID'     => $countryId,
			],
			'select' => ['ID'],
		]);

		$presetIds = [];
		while ($row = $presetListResult->Fetch())
		{
			$presetIds[] = (int)$row['ID'];

			if (count($presetIds) === $chunkSize)
			{
				if (self::checkRequisitesByPresetIds($presetIds))
				{
					return true;
				}

				$presetIds = [];
			}
		}

		if (!empty($presetIds))
		{
			return self::checkRequisitesByPresetIds($presetIds);
		}

		return false;
	}

	public static function doRun()
	{
		$zone = Application::getInstance()->getLicense()->getRegion();

		if (!is_string($zone) || $zone === '')
		{
			return false;
		}

		try
		{
			$countryId = EntityRequisite::getCountryIdByRegion($zone);
			if ($countryId <= 0 )
			{
				$countryId = (int)GetCountryIdByCode('US');
			}
			$currentCountryId = EntityPreset::getCurrentCountryId();
			if (
				$countryId > 0 // New country code
				&& $currentCountryId === (int)GetCountryIdByCode('US') // Default country code for unsupported countries
				&& $countryId !== $currentCountryId // The new country code is not equal to the default country code
				&& in_array( // The new country code is supported by the requisites
					$countryId,
					EntityRequisite::getAllowedRqFieldCountries(),
					true
				)
			)
			{
				// If there are requisites corresponding to the current country, then set the option,
				// otherwise immediately switch to the requisites of the new country.
				if (static::isCountryRequisitesExists($currentCountryId))
				{
					// Set option with new country identifier
					Option::set('crm', '~crm_requisite_current_country_can_change', $countryId);
				}
				else
				{
					$result = EntityPreset::getSingleInstance()->changeCurrentCountryWithoutCheckingRights($countryId);
					if (!$result->isSuccess())
					{
						static::logError(implode('; ', $result->getErrorMessages()));
					}
				}
			}
		}
		catch (SystemException $exception)
		{
			static::logException($exception->getMessage());
		}

		return false;
	}
}
