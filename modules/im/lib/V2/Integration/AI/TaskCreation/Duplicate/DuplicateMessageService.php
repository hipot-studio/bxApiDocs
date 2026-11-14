<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\TaskCreation\Duplicate;

use Bitrix\Im\Bot\Keyboard;
use Bitrix\Im\Command;
use Bitrix\Im\V2\Integration\AI\AIHelper;
use Bitrix\Im\V2\Logger;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Color\Color;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Im\V2\Message\Send\SendingConfig;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class DuplicateMessageService
{
	public const COMMAND = 'aiTaskCreateAnyway';

	public function send(Message $anchor, int $initiatorId, array $duplicates): ?int
	{
		if (empty($duplicates))
		{
			return null;
		}

		$anchorMessageId = (int)$anchor->getMessageId();
		$chat = $anchor->getChat();
		$botId = AIHelper::getCopilotBotId();
		if ($botId <= 0 || !$this->registerCommand($botId))
		{
			return null;
		}

		$message = (new Message())
			->setChat($chat)
			->setAuthorId($botId)
			->setMessage($this->buildText($duplicates, $initiatorId))
		;

		$message->addParam(Params::REPLY_ID, $anchorMessageId);
		$message->addParam(Params::KEYBOARD, $this->buildKeyboard($botId, $anchorMessageId));

		$sendingConfig = (new SendingConfig())
			->enableSkipUserCheck()
			->enableSkipConnectorSend()
			->enableSkipCommandExecution()
			->enableSkipUrlIndex()
		;

		$result = $chat->sendMessage($message, $sendingConfig);
		if (!$result->isSuccess())
		{
			return null;
		}

		$decisionMessageId = (int)$result->getMessageId();
		if ($decisionMessageId <= 0)
		{
			return null;
		}

		return $decisionMessageId;
	}

	/** Keeps the action visible and disables it after the decision has been made. */
	public function showResult(int $decisionMessageId): void
	{
		if ($decisionMessageId <= 0)
		{
			return;
		}

		$botId = AIHelper::getCopilotBotId();
		if ($botId <= 0)
		{
			return;
		}

		$keyboard = $this->buildKeyboard($botId, 0, true);

		\CIMMessageParam::Set($decisionMessageId, ['KEYBOARD' => $keyboard]);
		\CIMMessageParam::SendPull($decisionMessageId, ['KEYBOARD']);
	}

	public function notifySelectionIncomplete(int $anchorMessageId, int $initiatorId, string $text): void
	{
		$this->sendNotification(
			$initiatorId,
			$text,
			"IM|AI_TASK_DEDUP|SELECTION|{$anchorMessageId}",
		);
	}

	public static function onCommandAdd(int $decisionMessageId, array $messageFields): bool
	{
		if (($messageFields['COMMAND_CONTEXT'] ?? '') !== 'KEYBOARD')
		{
			return false;
		}

		$anchorMessageId = (int)($messageFields['COMMAND_PARAMS'] ?? 0);
		$userId = (int)($messageFields['FROM_USER_ID'] ?? 0);
		if ($anchorMessageId <= 0 || $userId <= 0 || $decisionMessageId <= 0)
		{
			return false;
		}

		return (new DuplicateCheckService())->createAnyway(
			$anchorMessageId,
			$userId,
			$decisionMessageId,
		);
	}

	private function registerCommand(int $botId): bool
	{
		return (int)Command::register([
			'MODULE_ID' => 'im',
			'BOT_ID' => $botId,
			'COMMAND' => self::COMMAND,
			'COMMON' => 'Y',
			'HIDDEN' => 'Y',
			'EXTRANET_SUPPORT' => 'Y',
			'CLASS' => self::class,
			'METHOD_COMMAND_ADD' => 'onCommandAdd',
		]) > 0;
	}

	private function buildKeyboard(int $botId, int $anchorMessageId, bool $disabled = false): Keyboard
	{
		$keyboard = new Keyboard($botId);
		$button = [
			'TEXT' => (string)Loc::getMessage('IM_AI_TASK_DEDUP_CREATE_BUTTON'),
			'COMMAND' => self::COMMAND,
			'COMMAND_PARAMS' => (string)$anchorMessageId,
			'DISPLAY' => 'LINE',
			'BG_COLOR_TOKEN' => Color::AI_ASSISTANT->value,
		];
		if ($disabled)
		{
			$button['DISABLED'] = 'Y';
		}

		$keyboard->addButton($button);

		return $keyboard;
	}

	/**
	 * @param array $duplicates list of ['matchedTitle' => string, 'matchedTaskId' => int]
	 */
	private function buildText(array $duplicates, int $initiatorId): string
	{
		$lines = [(string)Loc::getMessage('IM_AI_TASK_DEDUP_TITLE')];
		$isNumbered = count($duplicates) > 1;

		$itemNumber = 1;
		foreach ($duplicates as $duplicate)
		{
			$link = $this->buildTaskLink($duplicate, $initiatorId);
			$lines[] = $isNumbered ? $itemNumber . '. ' . $link : $link;
			$itemNumber++;
		}

		return implode("\n", array_filter($lines, static fn (string $line): bool => $line !== ''));
	}

	private function sendNotification(int $initiatorId, string $text, string $tag): void
	{
		if ($initiatorId <= 0 || $text === '')
		{
			return;
		}

		try
		{
			\CIMNotify::Add([
				'TO_USER_ID' => $initiatorId,
				'FROM_USER_ID' => 0,
				'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
				'NOTIFY_MODULE' => 'im',
				'NOTIFY_EVENT' => 'ai_task_duplicate',
				'NOTIFY_TAG' => $tag,
				'NOTIFY_MESSAGE' => $text,
				'NOTIFY_MESSAGE_OUT' => $text,
			]);
		}
		catch (\Throwable $exception)
		{
			(new Logger('ai_task_duplicate_check'))->logThrowable($exception);
		}
	}

	/**
	 * The verdict is handled by its own AI queue event, which loads im but not tasks: when the
	 * whole batch turns out to be a duplicate, nothing on the way here has touched tasks.
	 */
	private function buildTaskLink(array $duplicate, int $initiatorId): string
	{
		$title = $this->stripBbCodes((string)($duplicate['matchedTitle'] ?? ''));
		$taskId = (int)($duplicate['matchedTaskId'] ?? 0);
		if ($taskId <= 0 || !Loader::includeModule('tasks'))
		{
			return $title;
		}

		$url = \CTaskNotifications::getNotificationPath(['ID' => $initiatorId], $taskId);

		return (is_string($url) && $url !== '') ? "[URL={$url}]{$title}[/URL]" : $title;
	}

	/**
	 * The message reaches the client already parsed and is parsed once more there, and how many
	 * times a bracket written as an entity gets decoded on the way depends on the markup around it.
	 * So the value is normalized first - decoded until it stops changing - and only then loses its
	 * brackets: after that no spelling of a bracket is left to decode, and the link built here
	 * stays the only live BBCode in the message.
	 */
	private function stripBbCodes(string $text): string
	{
		do
		{
			$encoded = $text;
			$text = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}
		while ($text !== $encoded);

		return str_replace(['[', ']'], '', $text);
	}
}
