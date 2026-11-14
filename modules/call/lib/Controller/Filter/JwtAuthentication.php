<?php

namespace Bitrix\Call\Controller\Filter;

use Bitrix\Call\Controller\JwtReader;
use Bitrix\Call\Error;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

/**
 * Lets a server-to-server action run only for a request signed with the portal key.
 *
 * Takes the place of the Authentication filter on actions the call server calls without a user session.
 * Works in every scope, so the action is closed whatever transport brings the request.
 *
 * @internal
 */
final class JwtAuthentication extends Base
{
	public function onBeforeAction(Event $event): ?EventResult
	{
		$reader = new JwtReader();
		$jwt = $reader->read($this->getAction()->getController()->getSourceParametersList());

		if ($reader->decode($jwt) === null)
		{
			$this->addError(new Error(Error::WRONG_JWT));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}
