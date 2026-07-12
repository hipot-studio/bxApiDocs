<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Photogallery\Service;

use Bitrix\Main\Loader;

class Project
{
	public function isAvailable(): bool
	{
		return Loader::includeModule('photogallery');
	}
}
