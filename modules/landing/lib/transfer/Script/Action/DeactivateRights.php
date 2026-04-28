<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Rights;

class DeactivateRights extends Blank
{
	public function action(): void
	{
		Rights::setGlobalOff();
	}
}