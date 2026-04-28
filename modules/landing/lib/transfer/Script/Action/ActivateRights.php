<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Rights;

class ActivateRights extends Blank
{
	public function action(): void
	{
		Rights::setGlobalOn();
	}
}