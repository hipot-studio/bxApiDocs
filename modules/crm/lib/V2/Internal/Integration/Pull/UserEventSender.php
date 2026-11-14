<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Pull;

use Bitrix\Main\Loader;

/**
 * Sends one event to the private channel of one person.
 *
 * The single place of CRM V2 that touches `pull`: the module is loaded here, its types never cross
 * this boundary, and the caller names nothing but the recipient, the command and the parameters.
 *
 * The dependency is optional on purpose. The summary of the client is delivered to the copilot chat
 * as well, and a portal without `pull` must keep that delivery: an absent module is a signal that was
 * not sent and not a failure of the job.
 *
 * The class is left open to inheritance for the tests of its callers alone: a case that states what
 * was signalled must not queue an event of a real portal to state it.
 *
 * @internal
 */
class UserEventSender
{
	/**
	 * @param array $parameters payload of the event, read by the listener as `params`
	 *
	 * @return bool whether the event was queued for the person
	 */
	public function send(int $userId, string $command, array $parameters): bool
	{
		if ($userId <= 0 || !Loader::includeModule('pull'))
		{
			return false;
		}

		return (bool)\Bitrix\Pull\Event::add($userId, [
			'module_id' => 'crm',
			'command' => $command,
			'params' => $parameters,
		]);
	}
}
