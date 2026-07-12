<?php

namespace Bitrix\Im\V2\Bot;

use Bitrix\Im;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Message\Send\SendingConfig;

class BotService
{
	use ContextCustomer;

	private SendingConfig $sendingConfig;

	/**
	 * @param SendingConfig|null $sendingConfig
	 */
	public function __construct(?SendingConfig $sendingConfig = null)
	{
		if ($sendingConfig === null)
		{
			$sendingConfig = new SendingConfig();
		}
		$this->sendingConfig = $sendingConfig;
	}

	public function runMessageCommand(Im\V2\Message $message, array $fields): void
	{
		$fields['COMMAND_CONTEXT'] = 'TEXTAREA';
		$result = Im\Command::onCommandAdd($message->getId(), $fields);
		if (!$result)
		{
			Im\Bot::onMessageAdd($message->getId(), $fields, $message);
		}
	}
}
