<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Comment\Trait;

use Bitrix\Main\Localization\Loc;

trait LoadMessagesTrait
{
	private function loadPosterMessages(): void
	{
		Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/tasks/lib/comments/task/commentposter.php');
	}
}
