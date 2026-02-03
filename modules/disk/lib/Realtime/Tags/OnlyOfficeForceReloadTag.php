<?php
declare(strict_types=1);

namespace Bitrix\Disk\Realtime\Tags;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use CPullWatch;

class OnlyOfficeForceReloadTag extends Tag
{
	public function getName(): string
	{
		return 'onlyoffice_force_reload';
	}

	public function subscribe(): void
	{
		if (Loader::includeModule('pull'))
		{
			CPullWatch::Add(
				CurrentUser::get()->getId(),
				$this->getName(),
			);
		}
	}
}