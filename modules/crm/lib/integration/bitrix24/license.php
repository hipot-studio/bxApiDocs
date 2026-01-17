<?php

namespace Bitrix\Crm\Integration\Bitrix24;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

final class License
{
	private bool $isAvailable;

	public function __construct()
	{
		$this->isAvailable = Loader::includeModule('bitrix24');
	}

	public function isOverdue(): bool
	{
		return $this->getDaysLeft() <= 0;
	}

	public function getDaysLeft(): ?int
	{
		if (!$this->isAvailable)
		{
			return null;
		}

		$licenseTill = (int)Option::get('main', '~controller_group_till', null);
		$currentDate = (new DateTime())->getTimestamp();

		if (!$licenseTill)
		{
			return null;
		}

		return (int)ceil(($licenseTill - $currentDate) / 60 / 60 / 24);
	}
}
