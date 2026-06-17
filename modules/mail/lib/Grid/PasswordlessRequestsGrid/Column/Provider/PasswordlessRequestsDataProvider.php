<?php

declare(strict_types=1);

namespace Bitrix\Mail\Grid\PasswordlessRequestsGrid\Column\Provider;

use Bitrix\Mail\Helper\Enum\PasswordlessRequestField;
use Bitrix\Main\Grid\Column\DataProvider;
use Bitrix\Main\Localization\Loc;

class PasswordlessRequestsDataProvider extends DataProvider
{
	public function prepareColumns(): array
	{
		$result = [];

		$result[] =
			$this->createColumn(PasswordlessRequestField::Employee->value)
				->setName(Loc::getMessage('MAIL_PASSWORDLESS_GRID_COLUMN_EMPLOYEE'))
				->setDefault(true)
				->setSort(PasswordlessRequestField::Employee->value)
		;

		$result[] =
			$this->createColumn(PasswordlessRequestField::Email->value)
				->setName(Loc::getMessage('MAIL_PASSWORDLESS_GRID_COLUMN_EMAIL'))
				->setDefault(true)
				->setSort(PasswordlessRequestField::Email->value)
		;

		$result[] =
			$this->createColumn(PasswordlessRequestField::Status->value)
				->setName(Loc::getMessage('MAIL_PASSWORDLESS_GRID_COLUMN_STATUS'))
				->setDefault(true)
		;

		$result[] =
			$this->createColumn(PasswordlessRequestField::DateSent->value)
				->setName(Loc::getMessage('MAIL_PASSWORDLESS_GRID_COLUMN_DATE_SENT'))
				->setDefault(true)
				->setSort(PasswordlessRequestField::DateSent->value)
		;

		return $result;
	}
}
