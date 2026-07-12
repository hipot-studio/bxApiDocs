<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Factory;

use Bitrix\Im;

class Message
{
	public function createMessage(): Im\V2\Message
	{
		return new Im\V2\Message();
	}
}
