<?php

namespace Bitrix\Intranet\User\Grid\Column\Provider;

use Bitrix\Intranet\User\Grid\Settings\UserSettings;
use Bitrix\Main\Grid\Column\DataProvider;
use Bitrix\Main\Localization\Loc;

/**
 * @method UserSettings getSettings()
 */
class IntegratorUserDataProvider extends DataProvider
{
	public function prepareColumns(): array
	{
		Loc::loadMessages(__DIR__ . '/UserDataProvider.php');
		$result = [];

		$result[] =
			$this->createColumn('EMPLOYEE_CARD')
				->setSelect(['ID', 'LOGIN', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'EMAIL', 'TITLE', 'PERSONAL_PHOTO', 'WORK_POSITION', 'PERSONAL_GENDER', 'CONFIRM_CODE', 'ACTIVE', 'UF_DEPARTMENT'])
				->setName(Loc::getMessage('INTRANET_USER_LIST_COLUMN_EMPLOYEE_CARD'))
				->setDefault(true)
				->setSort('LAST_NAME')
		;

		$result[] =
			$this->createColumn('CONNECT')
				->setSelect(['ID'])
				->setName(Loc::getMessage('INTRANET_USER_LIST_COLUMN_CONNECT'))
				->setDefault(true)
		;

		$result[] =
			$this->createColumn('INTEGRATOR')
				->setSelect(['ID'])
				->setName(Loc::getMessage('INTRANET_USER_LIST_COLUMN_INTEGRATOR'))
				->setDefault(true)
		;

		$result[] =
			$this->createColumn('EMAIL')
				->setName(Loc::getMessage('INTRANET_USER_LIST_COLUMN_EMAIL'))
				->setDefault(true)
				->setSort('EMAIL')
		;

		$result[] =
			$this->createColumn('PERSONAL_MOBILE')
				->setName(Loc::getMessage('INTRANET_USER_LIST_COLUMN_PERSONAL_MOBILE'))
				->setDefault(true)
				->setSort('PERSONAL_MOBILE')
		;

		return $result;
	}
}