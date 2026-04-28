<?php

declare(strict_types=1);

namespace Bitrix\Imbot\V2\Controller;

use Bitrix\Im\V2\Controller\BaseController;

\Bitrix\Main\Loader::requireModule('im');

class Revision extends BaseController
{
	/**
	 * @restMethod imbot.v2.Revision.get
	 */
	public function getAction(): array
	{
		return \Bitrix\Im\Revision::get();
	}
}
