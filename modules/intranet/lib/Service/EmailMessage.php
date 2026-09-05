<?php

namespace Bitrix\Intranet\Service;

use Bitrix\Intranet\Contract\SendableContract;
use Bitrix\Main\Mail\Event as MailEvent;

class EmailMessage implements SendableContract
{
	public function __construct(
		private string $eventName,
		private string $siteId,
		private array $templateParams,
		private ?int $messageId,
		private ?bool $isDuplicate = null,
		private string $userLang = LANGUAGE_ID,
	)
	{

	}

	public function sendImmediately(): void
	{
		$this->sendImmediatelyWithResult();
	}

	public function sendImmediatelyWithResult(): bool
	{
		return in_array(
			$this->sendImmediateEvent(),
			[MailEvent::SEND_RESULT_SUCCESS, MailEvent::SEND_RESULT_PARTLY],
			true,
		);
	}

	protected function sendImmediateEvent(): string|bool
	{
		return \CEvent::SendImmediate(
			$this->eventName,
			$this->siteId,
			$this->templateParams,
			$this->isDuplicate,
			$this->messageId,
			[],
			$this->userLang,
		);
	}

	public function send(): void
	{
		\CEvent::Send(
			$this->eventName,
			$this->siteId,
			$this->templateParams,
			$this->isDuplicate,
			$this->messageId,
			[],
			$this->userLang
		);
	}
}
