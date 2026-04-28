<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Rights;

class SetContextUser extends Blank
{
	public function action(): void
	{
		$userId = $this->context->getUserId();
		if (isset($userId))
		{
			Rights::setContextUserId($userId);
		}
	}
}
