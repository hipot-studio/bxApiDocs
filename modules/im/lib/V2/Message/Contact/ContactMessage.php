<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Contact;

use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Params;

final class ContactMessage
{
	public const COMPONENT_ID = 'ContactMessage';

	public static function is(Message $message): bool
	{
		$params = $message->getParams();

		return $params->isSet(Params::COMPONENT_ID)
			&& $params->get(Params::COMPONENT_ID)->getValue() === self::COMPONENT_ID;
	}
}
