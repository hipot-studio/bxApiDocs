<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat;

final class WorkgroupChat extends ExternalChat
{
	protected function getDefaultType(): string
	{
		return self::IM_TYPE_CHAT;
	}
}
