<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Analytics\Event;

use Bitrix\Im\V2\Message;

class CopilotMentionEvent extends ChatEvent
{
	private Message $message;

	public function __construct(string $eventName, Message $message, int $userId)
	{
		$this->message = $message;
		parent::__construct($eventName, $message->getChat(), $userId);
	}

	protected function setDefaultParams(): ChatEvent
	{
		return parent::setDefaultParams()->setMentionType();
	}

	protected function setMentionType(): self
	{
		$this->type = $this->message->hasReply() ? 'with_quote' : 'no_quote';

		return $this;
	}

	protected function setChatP2(): ChatEvent
	{
		return $this;
	}
}
