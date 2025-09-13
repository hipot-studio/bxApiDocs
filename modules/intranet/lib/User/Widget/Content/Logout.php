<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Widget\Content;

use Bitrix\Intranet\User\Widget\BaseContent;
use Bitrix\Main\Localization\Loc;

class Logout extends BaseContent
{
	public function getName(): string
	{
		return 'logout';
	}

	public function getConfiguration(): array
	{
		$logoutPath = SITE_DIR . 'auth/?logout=yes';

		return [
			'title' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_LOGOUT_TITLE'),
			'path' => $logoutPath,
			'removeQueryParam' => ['logout', 'login', 'back_url_pub', 'user_lang'],
		];
	}
}
