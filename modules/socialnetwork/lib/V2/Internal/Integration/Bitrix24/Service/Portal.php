<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Bitrix24\Service;

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;

final class Portal
{
	private const CACHE_TTL = 60 * 60 * 24 * 30;
	private const FIRST_ADMIN_ID = 1;
	private const MONTH_OFFSET = 2;

	public function isOld(DateTime $cloudPortalCreationDate): bool
	{
		$portalCreateDate = $this->getCreationDateTime();
		if (!$portalCreateDate)
		{
			return false;
		}

		$boxPortalCreationDate = (clone $cloudPortalCreationDate)->add(self::MONTH_OFFSET . ' months');

		$suitablePortalCreationDate = (
			Loader::includeModule('bitrix24')
				? $cloudPortalCreationDate
				: $boxPortalCreationDate
		);

		return ($portalCreateDate->getTimestamp() <= $suitablePortalCreationDate->getTimestamp());
	}

	private function getCreationDateTime(): ?DateTime
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return $this->getFirstUserRegisterDate();
		}

		$createTime = (int)(\CBitrix24::getCreateTime());

		if ($createTime <= 0)
		{
			return null;
		}

		return DateTime::createFromTimestamp($createTime);
	}

	private function getFirstUserRegisterDate(): ?DateTime
	{
		$firstUser = UserTable::query()
			->setSelect(['ID', 'DATE_REGISTER'])
			->where('ID', self::FIRST_ADMIN_ID)
			->setLimit(1)
			->setCacheTtl(self::CACHE_TTL)
			->fetchObject();

		return $firstUser?->getDateRegister();

	}
}
