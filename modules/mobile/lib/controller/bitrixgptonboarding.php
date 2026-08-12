<?php

namespace Bitrix\Mobile\Controller;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;

final class BitrixGptOnboarding extends JsonController
{
	private const FIRST_USER_CACHE_TTL = 60 * 60 * 24 * 30;
	private const FIRST_ADMIN_ID = 1;

	public function configureActions(): array
	{
		return [
			'shouldShow' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
		];
	}

	/**
	 * @restMethod mobile.BitrixGptOnboarding.shouldShow
	 * @return bool
	 */
	public function shouldShowAction(): bool
	{
		if (!Loader::includeModule('im'))
		{
			return false;
		}

		if (!$this->isOldClient())
		{
			return false;
		}

		return \Bitrix\Im\V2\Application\Features::get()->isBitrixGptV2Available;
	}

	private function isOldClient(): bool
	{
		$cutoffRaw = (string)Option::get('aiassistant', 'launch_banner_cutoff_date', '');
		if ($cutoffRaw === '')
		{
			return false;
		}

		$cutoffTime = strtotime($cutoffRaw);
		if ($cutoffTime === false)
		{
			return false;
		}

		$portalCreateTime = $this->getPortalCreationTimestamp();
		if ($portalCreateTime === null)
		{
			return false;
		}

		return $portalCreateTime < $cutoffTime;
	}

	private function getPortalCreationTimestamp(): ?int
	{
		if (Loader::includeModule('bitrix24'))
		{
			$createTime = (int)\CBitrix24::getCreateTime();

			return $createTime > 0 ? $createTime : null;
		}

		$firstUser = UserTable::query()
			->setSelect(['ID', 'DATE_REGISTER'])
			->where('ID', self::FIRST_ADMIN_ID)
			->setLimit(1)
			->setCacheTtl(self::FIRST_USER_CACHE_TTL)
			->fetchObject()
		;

		return $firstUser?->getDateRegister()?->getTimestamp();
	}
}
