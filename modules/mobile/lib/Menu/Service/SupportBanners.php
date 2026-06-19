<?php

namespace Bitrix\Mobile\Menu\Service;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

final class SupportBanners
{
	private const FORM_ID_LT_100 = 'integrationConsultLt100';
	private const FORM_ID_GT_100 = 'integrationConsultGt100';

	public function shouldShowSupportBanners(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		$region = Application::getInstance()->getLicense()->getRegion();
		if ($region !== 'ru')
		{
			return false;
		}

		$daysAlive = $this->getPortalAgeInDays();

		if ($daysAlive > 60)
		{
			return false;
		}

		if (!$this->isPortalAdmin())
		{
			return false;
		}

		return true;
	}

	public function getFormCode(): string
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return self::FORM_ID_LT_100;
		}

		$employeeCount = $this->getEmployeeCountByQualification();

		return $employeeCount < 100 ? self::FORM_ID_LT_100 : self::FORM_ID_GT_100;
	}

	private function getPortalAgeInDays(): int
	{
		$portalCreatedTimestamp = \CBitrix24::getCreateTime();
		$portalCreatedAt = empty($portalCreatedTimestamp)
			? new DateTime()
			: Datetime::createFromTimestamp((int)$portalCreatedTimestamp);

		$diff = $portalCreatedAt->getDiff(new DateTime())->days ?? 0;

		return $diff <= 0 ? 0 : $diff;
	}

	private function isPortalAdmin(): bool
	{
		$userId = (int)CurrentUser::get()->getId();
		if ($userId > 0)
		{
			return \CBitrix24::IsPortalAdmin($userId);
		}

		return false;
	}

	private function getEmployeeCountByQualification(): int
	{
		$rawValue = Option::get('bitrix24', 'cjm-employee-count', '');
		if (empty($rawValue))
		{
			return 0;
		}

		$maxEmployeeCount = 0;

		try
		{
			$objValue = Json::decode($rawValue);
			$strValue = $objValue['itemValue'] ?? '';

			if (preg_match('/^\d+-(\d+)$/', $strValue, $matches))
			{
				$maxEmployeeCount = (int)$matches[1];
			}
			elseif (preg_match('/^(\d+)\+$/', $strValue, $matches))
			{
				$maxEmployeeCount = (int)$matches[1];
			}
		}
		catch (ArgumentException $e)
		{}

		return $maxEmployeeCount;
	}
}
