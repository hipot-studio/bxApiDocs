<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Widget\Content;

use Bitrix\Intranet\User\Widget\BaseContent;
use Bitrix\Main\Localization\Loc;

class Theme extends BaseContent
{
	public function getName(): string
	{
		return 'theme';
	}

	public function getConfiguration(): array
	{
		return [
			'title' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_THEME_TITLE'),
		];
	}
}
