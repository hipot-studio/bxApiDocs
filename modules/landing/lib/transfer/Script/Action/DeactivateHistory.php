<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\History;

class DeactivateHistory extends Blank
{
	public function action(): void
	{
		History::deactivate();
	}
}