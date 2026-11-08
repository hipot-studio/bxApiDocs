<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Form;

use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\FormBlock;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\RequestButton;
use Bitrix\Main\Application;
use Bitrix\Main\Event;

class FormSubmitService
{
	use ContextCustomer;

	private const MODULE_ID = 'im';
	private const EVENT_ON_FORM_SUBMIT = 'OnMessageFormSubmit';

	/** Does not persist the submitted values — only fires an event for internal subscribers. */
	public function submit(Message $message, FormBlock $block, RequestButton $button, array $values): void
	{
		$userId = $this->getContext()->getUserId();

		$params = [
			'message' => $message,
			'userId' => $userId,
			'blockId' => $block->getId(),
			'buttonId' => $button->getId(),
			'actionId' => $button->getActionId(),
			'values' => $values,
		];

		$event = new Event(self::MODULE_ID, self::EVENT_ON_FORM_SUBMIT, $params);

		// A failing internal subscriber must not break the action or block the bot channel below.
		try
		{
			$event->send();
		}
		catch (\Throwable $e)
		{
			// Log only the exception itself — never the submitted form values.
			Application::getInstance()->getExceptionHandler()->writeToLog($e);
		}

		$botParams = [
			'ACTION_ID' => $button->getActionId(),
			'BLOCK_ID' => $block->getId(),
			'BUTTON_ID' => $button->getId(),
			'VALUES' => $values,
			'CHAT_ID' => $message->getChat()->getId(),
			'USER_ID' => $userId,
		];

		try
		{
			\Bitrix\Im\Bot::onFormSubmit($message, $botParams);
		}
		catch (\Throwable $e)
		{
			// Log only the exception itself — never the submitted form values.
			Application::getInstance()->getExceptionHandler()->writeToLog($e);
		}
	}
}
