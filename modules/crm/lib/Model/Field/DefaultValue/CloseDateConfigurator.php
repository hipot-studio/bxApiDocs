<?php

namespace Bitrix\Crm\Model\Field\DefaultValue;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\RegionalSettings\Maps\DaysBeforeClose\DealDaysBeforeCloseRegionalMap;
use Bitrix\Crm\Service\RegionalSettings\Maps\DaysBeforeClose\InvoiceDaysBeforeCloseRegionalMap;
use Bitrix\Crm\Service\RegionalSettings\Maps\DaysBeforeClose\QuoteDaysBeforeCloseRegionalMap;
use Bitrix\Crm\Service\RegionalSettings\RegionalSettings;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Type\Date;

final class CloseDateConfigurator implements Configurator
{
	public const DEFAULT_VALUE = 7;

	public function __construct(
		private RegionalSettings $regionalSettings,
	)
	{
	}

	public function getDefaultValue(int $entityTypeId, array ...$args): Date
	{
		$daysBeforeClose = $this->resolveDefaultCloseDateDays($entityTypeId);

		return (new Date())->add($daysBeforeClose . 'D');
	}

	public function resolveDefaultCloseDateDays(int $entityTypeId): int
	{
		$perEntityDays = $this->resolvePerEntityDaysBeforeClose($entityTypeId);
		if ($perEntityDays !== null)
		{
			return $perEntityDays;
		}

		$regionDays = $this->resolveRegionDefaultDaysBeforeClose($entityTypeId);
		if ($regionDays !== null)
		{
			return $regionDays;
		}

		return self::DEFAULT_VALUE;
	}

	public function parseDaysBeforeCloseValue(mixed $value): ?int
	{
		if ($value === null)
		{
			return null;
		}

		if (is_int($value))
		{
			return $value >= 0 ? $value : null;
		}

		if (is_string($value))
		{
			$value = trim($value);
			if ($value === '')
			{
				return null;
			}

			if (!preg_match('/^\d+$/', $value))
			{
				return null;
			}

			$value = (int)$value;

			return $value >= 0 ? $value : null;
		}

		if (is_float($value))
		{
			return null;
		}

		if (is_numeric($value))
		{
			$value = (int)$value;

			return $value >= 0 ? $value : null;
		}

		return null;
	}

	private function resolvePerEntityDaysBeforeClose(int $entityTypeId): ?int
	{
		if ($entityTypeId === \CCrmOwnerType::Deal)
		{
			return $this->parseDaysBeforeCloseValue(Option::get('crm', 'crm_deal_days_before_close', ''));
		}

		if ($entityTypeId === \CCrmOwnerType::Quote)
		{
			return $this->parseDaysBeforeCloseValue(Option::get('crm', 'crm_quote_days_before_close', ''));
		}

		$type = Container::getInstance()->getTypeByEntityTypeId($entityTypeId);
		if ($type === null)
		{
			return null;
		}

		return $this->parseDaysBeforeCloseValue($type?->getDaysBeforeClose());
	}

	private function resolveRegionDefaultDaysBeforeClose(int $entityTypeId): ?int
	{
		$settingsMap = match ($entityTypeId)
		{
			\CCrmOwnerType::Deal => DealDaysBeforeCloseRegionalMap::class,
			\CCrmOwnerType::Quote => QuoteDaysBeforeCloseRegionalMap::class,
			\CCrmOwnerType::SmartInvoice => InvoiceDaysBeforeCloseRegionalMap::class,
			default => null,
		};

		if ($settingsMap === null)
		{
			return null;
		}

		$map = ServiceLocator::getInstance()->get($settingsMap);
		if ($map === null)
		{
			return null;
		}

		$value = $this->regionalSettings->getSetting($map);

		return $this->parseDaysBeforeCloseValue($value);
	}
}
