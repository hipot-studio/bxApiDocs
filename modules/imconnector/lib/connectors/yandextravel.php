<?php
namespace Bitrix\ImConnector\Connectors;

use Bitrix\Im;
use Bitrix\Im\Model\MessageViewedTable;
use Bitrix\ImConnector\Tools\Text;
use Bitrix\ImOpenLines\Im\Messages;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Class Yandextravel
 * @package Bitrix\ImConnector\Connectors
 */
class Yandextravel extends Base
{
	public function __construct($idConnector)
	{
		parent::__construct('yandextravel');
	}

	/**
	 * The connector is offered only on ru portals and is served by the ru gateway, which is also where its
	 * registration and per-line state live. Left in the proxied group, the line bundle commands
	 * (deleteLine/infoConnectorsLine) would be answered by the other region, where this line does not
	 * exist: the portal would drop its status while the gateway kept the line registered and kept
	 * acknowledging the provider's events for it.
	 *
	 * @return bool
	 */
	public function needProxy(): bool
	{
		return false;
	}

	public function sendMessageProcessing(array $message, $line): array
	{
		$message = $this->processMessageForRich($message);
		$message['message']['text'] = Text::parseQuoting($message['message']['text']);

		unset($message['user']);

		return $message;
	}

	/**
	 * The connector reports a refusal of the channel — a moderation verdict or an attachment type the API
	 * has no method for — with its own text: it names what happened and whose restriction it is. That text
	 * becomes the main one instead of hiding under the generic «Не удалось доставить сообщение», which adds
	 * nothing to it. The provider's own wording, when there is any, stays in messageConnector and is shown
	 * separately as an external quote.
	 *
	 * Which message was refused is shown by a reply block: a refusal arrives asynchronously and lands
	 * under whatever the operator said last. The ancestor writes the notice without a reply, so with a
	 * message to point at this method does the work itself instead of calling it.
	 *
	 * @param array  $paramsError Error payload from the connector server.
	 * @param string $message     Main text; filled by an ancestor for some connectors.
	 * @return bool
	 */
	protected function receivedErrorNotSendMessageChat($paramsError, string $message = ''): bool
	{
		if ($message === '' && !empty($paramsError['messageOwn']))
		{
			$message = (string)$paramsError['messageOwn'];
		}

		$chatId = (int)($paramsError['chatId'] ?? 0);
		$messageId = (int)($paramsError['messageId'] ?? 0);
		if (
			$message === ''
			|| $chatId <= 0
			|| $messageId <= 0
			|| !Loader::includeModule('imopenlines')
		)
		{
			return parent::receivedErrorNotSendMessageChat($paramsError, $message);
		}

		$this->markMessageUndelivered($messageId);
		$this->removeMessageDeliveryMark($messageId, $chatId);
		$this->revokeReadMark($messageId);

		$notice = Messages\Error::addErrorNotSendChat(
			$chatId,
			$message,
			(string)($paramsError['messageConnector'] ?? ''),
			$messageId
		);

		// The notice is what this handler is for: the marks above only cross the message out, and what
		// happened to it is in this text alone. So the answer is the outcome of writing it, not the fact
		// that writing was attempted - a notice that was not written must not read as a handled refusal.
		return (int)$notice > 0;
	}

	/**
	 * Rolls back the read mark of a message the channel refused. The mark is not the guest's: the open
	 * line sets it once the connector confirms the send (see \Bitrix\ImOpenLines\Connector::setStatusDelivery(),
	 * which reads the message for the connector user), and a refusal arrives after that confirmation —
	 * leaving a message nobody received shown to the operator as read.
	 *
	 * @param int $messageId Portal id of the refused message.
	 * @return void
	 */
	private function revokeReadMark(int $messageId): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		$message = new Im\V2\Message($messageId);
		if ($message->getMessageId() !== $messageId)
		{
			return;
		}

		$chat = $message->getChat();
		if (!($chat instanceof Im\V2\Chat\OpenLineChat))
		{
			return;
		}

		$connectorUserId = (int)($chat->getEntityData()['entityId']['connectorUserId'] ?? 0);
		if ($connectorUserId <= 0)
		{
			return;
		}

		// Only the view the delivery confirmation left behind is dropped — the very one the open line
		// wrote for this user. Another operator of the line may have really read the message, and their
		// view says so truthfully. The read tick is a field of the message, so it is recomputed from the
		// views that remain instead of being cleared together with them.
		MessageViewedTable::deleteByFilter([
			'=MESSAGE_ID' => $messageId,
			'=USER_ID' => $connectorUserId,
		]);

		$this->syncReadMark($message);

		// Counting the views and writing the field are two steps, and a real read landing between them
		// would be undone by the write: its view stays in place while the field says the message was not
		// read, and nothing recomputes the field afterwards. The second pass sees such a view and puts the
		// field back.
		$this->syncReadMark($message);
	}

	/**
	 * Brings the read tick of the message in line with the views it has left.
	 *
	 * @param Im\V2\Message $message An already loaded message.
	 * @return void
	 */
	private function syncReadMark(Im\V2\Message $message): void
	{
		$read = MessageViewedTable::getCount(['=MESSAGE_ID' => $message->getMessageId()]) > 0;
		if ($read === $message->isNotifyRead())
		{
			return;
		}

		$message->markNotifyRead($read)->save();
	}
}
