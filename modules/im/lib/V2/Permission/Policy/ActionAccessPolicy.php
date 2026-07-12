<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Permission\Policy;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Permission\Action;

interface ActionAccessPolicy
{
	public function check(Chat $chat, int $userId, Action $action, mixed $target): ?bool;
}
