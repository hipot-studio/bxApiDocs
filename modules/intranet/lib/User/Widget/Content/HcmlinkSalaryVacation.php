<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Widget\Content;

use Bitrix\Intranet\CurrentUser;
use Bitrix\Intranet\Internal\Integration\Humanresources\HcmLinkSalaryAndVacationFacade;
use Bitrix\Intranet\User\Widget\BaseContent;
use Bitrix\Main\Localization\Loc;

class HcmlinkSalaryVacation extends BaseContent
{
	public function getName(): string
	{
		return 'salaryVacation';
	}

	public function getConfiguration(): array
	{
		return [
			'isAvailable' => self::isAvailable(),
			'title' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_SALARY_VACATION_TITLE'),
			'disabledHint' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_SALARY_VACATION_DISABLED_MESSAGE', [
				'[LINK]' => "<a target='_self' onclick='(() => {BX.Helper.show(`redirect=detail&code=23343028`);})()' style='cursor:pointer;'>",
				'[/LINK]' => '</a>',
			]),
		];
	}

	public static function isAvailable(): bool
	{
		$userId = (int)CurrentUser::get()->getId();

		return (new HcmLinkSalaryAndVacationFacade())->isAvailableByUserId($userId);
	}
}
