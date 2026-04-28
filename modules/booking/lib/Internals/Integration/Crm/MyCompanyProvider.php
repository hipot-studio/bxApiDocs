<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Crm;

use Bitrix\Crm\Requisite\EntityLink;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;

class MyCompanyProvider
{
	public function getName(): string|null
	{
		if (!Loader::includeModule('crm'))
		{
			return null;
		}

		$id = $this->getId();
		if ($id === null)
		{
			return null;
		}

		return Container::getInstance()->getCompanyBroker()->getTitle($id);
	}

	private function getId(): int|null
	{
		$id = (int)EntityLink::getDefaultMyCompanyId();

		return $id > 0 ? $id : null;
	}
}
