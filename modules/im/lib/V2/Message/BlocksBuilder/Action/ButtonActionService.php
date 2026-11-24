<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Action;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\AbstractBlock;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\Button;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\ButtonsHolder;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\RequestButton;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Footer\FooterElement;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\FormBlock;
use Bitrix\Im\V2\Message\BlocksBuilder\Validation\FormSubmitValidationService;
use Bitrix\Main\Application;
use Bitrix\Main\Event;
use Bitrix\Main\Result;

/**
 * A click on a button of any block: the element is looked up both in the footer, which every block may
 * carry, and in the `buttons` field of the blocks that have one.
 *
 * Nothing is stored - neither the click nor the submitted values; the click is only published to
 * consumers, and whatever it changes comes back as a block update made by the consumer itself.
 */
class ButtonActionService
{
	use ContextCustomer;

	private const MODULE_ID = 'im';
	private const EVENT_ON_FORM_SUBMIT = 'OnMessageFormSubmit';
	private const EVENT_ON_BLOCK_BUTTON_CLICK = 'OnMessageBlockButtonClick';

	/**
	 * Access to the message and its chat is checked by the caller; rights on the entity behind the
	 * button belong to whoever opens it and to the consumer of the click.
	 *
	 * @param array $values values of the form fields; dropped outside a submit of the Form block
	 * @return Result data of the accepted click, see getClickData()
	 */
	public function run(Message $message, string $blockId, string $elementId, array $values = []): Result
	{
		$result = new Result();

		$block = $message->getBlocksBuilder()?->getBlockById($blockId);
		if ($block === null)
		{
			return $result->addError(new ActionError(ActionError::BLOCK_NOT_FOUND));
		}

		$element = $this->findElement($block, $elementId);
		if ($element === null)
		{
			return $result->addError(new ActionError(ActionError::ELEMENT_NOT_FOUND));
		}

		if ($element instanceof FooterElement && !$element->isAllowedFor($this->getContext()->getUserId()))
		{
			return $result->addError(new ActionError(ActionError::ELEMENT_NOT_ALLOWED));
		}

		if ($element instanceof RequestButton && $element->isDisabled())
		{
			return $result->addError(new ActionError(ActionError::FORM_DISABLED));
		}

		if ($block instanceof FormBlock && $element instanceof RequestButton)
		{
			$validation = (new FormSubmitValidationService())->validate($block, $values);
			if (!$validation->isSuccess())
			{
				return $result->addErrors($validation->getErrors());
			}

			$values = $validation->getResult();
		}
		else
		{
			$values = [];
		}

		$clickData = $this->getClickData($message, $block, $element, $values);
		$this->publish($message, $block, $element, $values, $clickData);

		return $result->setData($clickData);
	}

	/**
	 * Delivers the click to its consumers. Form submit keeps its own channel on top of the common
	 * one, so the buttons of an already released Form block reach the same subscribers as before.
	 *
	 * An element of the footer addressed past the person is refused here as well: run() answers such
	 * a click with an error code of its own, while this method is public and holds the ban for every
	 * caller and every channel at once.
	 *
	 * @param array|null $clickData already computed data of the click, see getClickData()
	 */
	public function publish(Message $message, AbstractBlock $block, FooterElement|Button $element, array $values = [], ?array $clickData = null): void
	{
		if ($element instanceof FooterElement && !$element->isAllowedFor($this->getContext()->getUserId()))
		{
			return;
		}

		$clickData ??= $this->getClickData($message, $block, $element, $values);

		$this->sendEvent(self::EVENT_ON_BLOCK_BUTTON_CLICK, $clickData);
		$this->callBot(static fn () => \Bitrix\Im\Bot::onBlockButtonClick($message, $clickData));

		if ($block instanceof FormBlock && $element instanceof RequestButton)
		{
			$this->publishFormSubmit($message, $block, $element, $values);
		}
	}

	/** What was clicked: the caller needs the element itself to tell a footer click from a form submit. */
	public function findElement(AbstractBlock $block, string $elementId): FooterElement|Button|null
	{
		$element = $block->getFooter()->getById($elementId);
		if ($element !== null)
		{
			return $element;
		}

		if ($block instanceof ButtonsHolder)
		{
			return $block->getButtons()->getById($elementId);
		}

		return null;
	}

	/**
	 * What was clicked. Both an element of the footer and a button of the `buttons` field describe
	 * themselves the same way in toArray(), so the click reads identically wherever it came from.
	 *
	 * The dialog is computed here once and every channel takes it from here; a chat without a dialog
	 * leaves it empty, and the bot channel refuses such a click.
	 */
	private function getClickData(Message $message, AbstractBlock $block, FooterElement|Button $element, array $values): array
	{
		$elementData = $element->toArray();
		$userId = $this->getContext()->getUserId();
		$chat = $message->getChat();

		return [
			'actionId' => (string)($elementData['actionId'] ?? ''),
			'actionParams' => (array)($elementData['actionParams'] ?? []),
			'blockId' => $block->getId(),
			'buttonId' => $element->getId(),
			'buttonType' => $element->getType(),
			'values' => $values,
			'messageId' => $message->getMessageId() ?? 0,
			'chatId' => $chat->getId() ?? 0,
			'dialogId' => (string)(\Bitrix\Im\Bot::getDialogIdByChat($chat) ?? ''),
			'userId' => $userId,
			'languageId' => $this->getUserLanguage($userId),
		];
	}

	private function getUserLanguage(int $userId): string
	{
		if ($userId <= 0)
		{
			return \Bitrix\Im\Bot::getDefaultLanguage();
		}

		return User::getInstance($userId)->getLanguageId() ?? \Bitrix\Im\Bot::getDefaultLanguage();
	}

	/**
	 * The switch of the Form block silences this channel too: a form saved while the flag was on stays
	 * readable after it is turned off, and Form.submit answers a click on it with a refusal.
	 */
	private function publishFormSubmit(Message $message, FormBlock $block, RequestButton $button, array $values): void
	{
		if (!Features::isMessageFormBlockAvailable())
		{
			return;
		}

		$userId = $this->getContext()->getUserId();

		$this->sendEvent(self::EVENT_ON_FORM_SUBMIT, [
			'message' => $message,
			'userId' => $userId,
			'blockId' => $block->getId(),
			'buttonId' => $button->getId(),
			'actionId' => $button->getActionId(),
			'values' => $values,
		]);

		$botParams = [
			'ACTION_ID' => $button->getActionId(),
			'BLOCK_ID' => $block->getId(),
			'BUTTON_ID' => $button->getId(),
			'VALUES' => $values,
			'CHAT_ID' => $message->getChat()->getId(),
			'USER_ID' => $userId,
		];

		$this->callBot(static fn () => \Bitrix\Im\Bot::onFormSubmit($message, $botParams));
	}

	/** A failing internal subscriber must not break the action or block the bot channel. */
	private function sendEvent(string $eventName, array $params): void
	{
		try
		{
			(new Event(self::MODULE_ID, $eventName, $params))->send();
		}
		catch (\Throwable $e)
		{
			// Log only the exception itself: never the submitted form values.
			Application::getInstance()->getExceptionHandler()->writeToLog($e);
		}
	}

	private function callBot(callable $call): void
	{
		try
		{
			$call();
		}
		catch (\Throwable $e)
		{
			// Log only the exception itself: never the submitted form values.
			Application::getInstance()->getExceptionHandler()->writeToLog($e);
		}
	}
}
