<?php

namespace Bitrix\Intranet\License\Widget\Content;

use Bitrix\Intranet\CurrentUser;
use Bitrix\Main\Localization\Loc;

class Updates extends BaseContent
{
	private bool $isCurrentUserAdmin;

	public function __construct()
	{
		$this->isCurrentUserAdmin = CurrentUser::get()->canDoOperation('bitrix24_config');
	}

	public function getName(): string
	{
		return 'updates';
	}

	public function getConfiguration(): array
	{
		return [
			'link' => $this->getLink(),
			'title' => $this->getTitle(),
			'isAdminRestricted' => !$this->isCurrentUserAdmin,
		];
	}

	private function getLink(): string
	{
		return SITE_DIR . 'bitrix/admin/update_system.php';
	}

	private function getTitle(): string
	{
		return Loc::getMessage('INTRANET_LICENSE_WIDGET_CONTENT_UPDATES_TEXT') ?? '';
	}
}
