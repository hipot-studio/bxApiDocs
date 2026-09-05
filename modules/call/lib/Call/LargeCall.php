<?php

namespace Bitrix\Call\Call;

use Bitrix\Main\Config\Option;

/**
 * Groundwork for a dedicated large room implementation, intentionally unreachable for now.
 *
 * {@see \Bitrix\Call\CallFactory::getProviderClass()} never returns this class: a large room today is
 * a BitrixCall or a ConferenceCall carrying BitrixCall::ROOM_TYPE_BIG in the call JWT
 * ({@see \Bitrix\Call\JwtCall::generateCallJwt()}). So the class is not dead code and must not be
 * removed, but it must not be added to the factory either until room type routing moves here as well.
 *
 * @internal
 */
class LargeCall extends BitrixCall
{
	public function getMaxUsers(): int
	{
		return (int)Option::get('call', 'call_server_large_room', parent::getMaxUsers());
	}
}