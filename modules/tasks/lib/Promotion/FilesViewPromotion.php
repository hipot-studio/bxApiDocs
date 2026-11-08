<?php

namespace Bitrix\Tasks\Promotion;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Bitrix\Tasks\Integration\Bitrix24\Portal;

class FilesViewPromotion extends AbstractPromotion
{
	public function getPromotionType(): PromotionType
	{
		return PromotionType::TASKS_FILES_VIEW;
	}

	public function shouldShow(int $userId): bool
	{
		if (!Loader::includeModule('intranet'))
		{
			return false;
		}

		if ($this->isViewed($userId) || !$this->isSuitableRegion())
		{
			return false;
		}

		$portalCreateDate = (new Portal())->getCreationDateTime();
		$userDateRegister = $this->getUserDateRegister($userId);
		$suitablePortalCreationDate = $this->getMinimumSuitablePortalCreationDate();

		return ($portalCreateDate?->getTimestamp() < $suitablePortalCreationDate->getTimestamp())
			&& (!$userDateRegister || $userDateRegister->getTimestamp() <= $suitablePortalCreationDate->getTimestamp())
		;
	}

	private function getUserDateRegister(int $userId): ?DateTime
	{
		$row = UserTable::query()
			->setSelect(['DATE_REGISTER'])
			->where('ID', $userId)
			->fetch()
		;

		return $row ? $row['DATE_REGISTER'] : null;
	}

	private function getMinimumSuitablePortalCreationDate(): DateTime
	{
		return Loader::includeModule('bitrix24')
			? new DateTime('2026-06-23', 'Y-m-d')
			: new DateTime('2026-08-18', 'Y-m-d')
		;
	}

	private function isSuitableRegion(): bool
	{
		$region = Application::getInstance()->getLicense()->getRegion();

		return $region !== 'cn';
	}
}
