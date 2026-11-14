<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\AiSummary;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\V2\Internal\Integration\Pull\UserEventSender;

/**
 * Tells the person who asked for a summary that its state has changed (EVENT-01).
 *
 * The signal is addressed to that one person and to nobody else: a summary is collected on their
 * behalf and out of the data their rights allow, so a subscription by a tag would show it to people
 * it was never collected for.
 *
 * The text of the summary does not travel with the signal. The tab asks for it by the state endpoint,
 * where the right to the client is checked anew - by the time the job ends there is no authorized
 * user left here to ask (R-3).
 *
 * The class is left open to inheritance for the tests of its callers alone: the launch service is
 * measured without a transport of the signal behind it.
 *
 * @internal
 */
class ClientSummaryReadyNotifier
{
	/** The command of EVENT-01; the extension of the tab listens to this very string. */
	public const COMMAND = 'aiSummary.clientSummaryStateChanged';

	private UserEventSender $sender;

	public function __construct(?UserEventSender $sender = null)
	{
		$this->sender = $sender ?? new UserEventSender();
	}

	/**
	 * @return bool whether the signal was sent; a portal without `pull` answers false and changes
	 *         nothing else
	 */
	public function notify(int $userId, ItemIdentifier $client, ClientSummaryState $state): bool
	{
		return $this->sender->send($userId, self::COMMAND, [
			'clientTypeId' => $client->getEntityTypeId(),
			'clientId' => $client->getEntityId(),
			'status' => $state->value,
		]);
	}
}
