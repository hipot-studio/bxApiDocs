<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Widget\Content;

use Bitrix\Intranet\User\Widget\BaseContent;
use Bitrix\Main\Localization\Loc;

class QrAuth extends BaseContent
{
	public function getName(): string
	{
		return 'qrAuth';
	}

	public function getConfiguration(): array
	{
		return [
			'title' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_QR_AUTH_TITLE'),
			'popup' => [
				'title' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_QR_AUTH_POPUP_TITLE'),
				'description' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_QR_AUTH_POPUP_DESCRIPTION'),
			],
			'buttonTitle' => Loc::getMessage('INTRANET_USER_WIDGET_CONTENT_QR_AUTH_BUTTON_TITLE'),
		];
	}
}
