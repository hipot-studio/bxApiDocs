<?php

declare(strict_types=1);

namespace Bitrix\Imbot\V2\Controller;

use Bitrix\Im\Bot;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Error;

class Command extends BotController
{
	/**
	 * @restMethod imbot.v2.Command.register
	 *
	 * Accepts both formats for backward compatibility:
	 * - New (preferred): fields: {command, title, params, common, hidden, extranetSupport}
	 * - Legacy: flat parameters (command, title, params, common, hidden, extranetSupport)
	 */
	public function registerAction(
		array $fields = [],
		// Legacy named parameters — backward compatibility
		?string $command = null,
		?array $title = null,
		?array $params = null,
		$common = 'N',
		$hidden = 'N',
		$extranetSupport = 'N',
	): ?array
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		$fields = $this->resolveRegisterFields($fields, $command, $title, $params, $common, $hidden, $extranetSupport);

		$botId = $this->getBotId();
		$clientId = $this->getClientId();

		$command = $fields['command'] ?? '';
		if (!is_string($command))
		{
			$this->addError(new Error('Command name must be a string', 'COMMAND_NAME_INVALID'));

			return null;
		}

		if (str_starts_with($command, '/'))
		{
			$command = mb_substr($command, 1);
		}

		if ($command === '')
		{
			$this->addError(new Error('Command is required', 'COMMAND_REQUIRED'));

			return null;
		}

		$commands = \Bitrix\Im\Command::getListCache();
		foreach ($commands as $registeredCommandId => $registeredCommand)
		{
			if (
				(int)$registeredCommand['BOT_ID'] === $botId
				&& $registeredCommand['COMMAND'] === $command
				&& $registeredCommand['APP_ID'] === $clientId
			)
			{
				return [
					'command' => $this->formatCommand($registeredCommandId),
				];
			}
		}

		$title = $fields['title'] ?? null;
		$params = $fields['params'] ?? null;
		$langSet = [];
		if ($title !== null)
		{
			$normalizedParams = [];
			if ($params !== null)
			{
				foreach ($params as $lang => $text)
				{
					$normalizedParams[mb_strtolower($lang)] = $text;
				}
			}

			foreach ($title as $langId => $titleText)
			{
				$langId = mb_strtolower($langId);
				$langEntry = [
					'LANGUAGE_ID' => $langId,
					'TITLE' => $titleText,
				];
				if (isset($normalizedParams[$langId]))
				{
					$langEntry['PARAMS'] = $normalizedParams[$langId];
				}
				$langSet[] = $langEntry;
			}
		}

		$isHidden = self::normalizeBooleanVariable($fields['hidden'] ?? 'N');

		if (!$isHidden && empty($langSet))
		{
			$this->addError(new Error('Title is required for visible commands', 'COMMAND_TITLE_REQUIRED'));

			return null;
		}

		$registerFields = [
			'MODULE_ID' => 'rest',
			'BOT_ID' => $botId,
			'COMMAND' => $command,
			'COMMON' => self::normalizeBooleanVariable($fields['common'] ?? 'N') ? 'Y' : 'N',
			'HIDDEN' => $isHidden ? 'Y' : 'N',
			'EXTRANET_SUPPORT' => self::normalizeBooleanVariable($fields['extranetSupport'] ?? 'N') ? 'Y' : 'N',
			'APP_ID' => $clientId,
			'LANG' => $langSet,
		];

		$commandId = \Bitrix\Im\Command::register($registerFields);

		if ($commandId === false)
		{
			$this->addError(new Error('Command registration failed', 'COMMAND_REGISTER_FAILED'));

			return null;
		}

		return [
			'command' => $this->formatCommand($commandId),
		];
	}

	private function resolveRegisterFields(
		array $fields,
		?string $command,
		?array $title,
		?array $params,
		$common,
		$hidden,
		$extranetSupport,
	): array
	{
		if (!empty($fields))
		{
			return $fields;
		}

		return [
			'command' => $command,
			'title' => $title,
			'params' => $params,
			'common' => $common,
			'hidden' => $hidden,
			'extranetSupport' => $extranetSupport,
		];
	}

	/**
	 * @restMethod imbot.v2.Command.unregister
	 */
	public function unregisterAction(int $commandId): ?array
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		if (!$this->validateCommandOwnership($commandId))
		{
			return null;
		}

		$clientId = $this->getClientId();

		$result = \Bitrix\Im\Command::unRegister([
			'COMMAND_ID' => $commandId,
			'MODULE_ID' => 'rest',
			'APP_ID' => $clientId,
		]);

		if (!$result)
		{
			$this->addError(new Error('Command not found or access denied', 'COMMAND_NOT_FOUND'));

			return null;
		}

		return ['result' => true];
	}

	/**
	 * @restMethod imbot.v2.Command.update
	 */
	public function updateAction(
		int $commandId,
		array $fields = [],
	): ?array
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		if (!$this->validateCommandOwnership($commandId))
		{
			return null;
		}

		$clientId = $this->getClientId();

		$updateFields = [];

		if (isset($fields['command']))
		{
			if (!is_string($fields['command']))
			{
				$this->addError(new Error('Command name must be a string', 'COMMAND_NAME_INVALID'));

				return null;
			}

			$command = $fields['command'];
			if (str_starts_with($command, '/'))
			{
				$command = mb_substr($command, 1);
			}

			if ($command === '')
			{
				$this->addError(new Error('Command name cannot be empty', 'COMMAND_NAME_EMPTY'));

				return null;
			}

			$botId = $this->getBotId();
			$commands = \Bitrix\Im\Command::getListCache();
			foreach ($commands as $registeredCommandId => $registeredCommand)
			{
				if (
					$registeredCommandId !== $commandId
					&& (int)$registeredCommand['BOT_ID'] === $botId
					&& $registeredCommand['COMMAND'] === $command
				)
				{
					$this->addError(new Error('Command with this name already exists', 'COMMAND_ALREADY_EXISTS'));

					return null;
				}
			}

			$updateFields['COMMAND'] = $command;
		}

		if (isset($fields['common']))
		{
			$updateFields['COMMON'] = self::normalizeBooleanVariable($fields['common']) ? 'Y' : 'N';
		}

		if (isset($fields['hidden']))
		{
			$updateFields['HIDDEN'] = self::normalizeBooleanVariable($fields['hidden']) ? 'Y' : 'N';
		}

		if (isset($fields['extranetSupport']))
		{
			$updateFields['EXTRANET_SUPPORT'] = self::normalizeBooleanVariable($fields['extranetSupport']) ? 'Y' : 'N';
		}

		if (isset($fields['title']))
		{
			if (!is_array($fields['title']))
			{
				$this->addError(new Error('Title must be an object with language keys', 'COMMAND_TITLE_INVALID'));

				return null;
			}

			$langSet = [];
			$orm = \Bitrix\Im\Model\CommandLangTable::getList([
				'filter' => ['=COMMAND_ID' => $commandId],
			]);
			while ($row = $orm->fetch())
			{
				$langSet[$row['LANGUAGE_ID']] = [
					'LANGUAGE_ID' => $row['LANGUAGE_ID'],
					'TITLE' => $row['TITLE'],
					'PARAMS' => $row['PARAMS'] ?? '',
				];
			}

			$normalizedParams = [];
			if (isset($fields['params']) && is_array($fields['params']))
			{
				foreach ($fields['params'] as $lang => $text)
				{
					$normalizedParams[mb_strtolower($lang)] = $text;
				}
			}

			foreach ($fields['title'] as $langId => $title)
			{
				$langId = mb_strtolower($langId);
				if ($title === null)
				{
					unset($langSet[$langId]);
					continue;
				}

				$langSet[$langId] = [
					'LANGUAGE_ID' => $langId,
					'TITLE' => $title,
					'PARAMS' => $normalizedParams[$langId] ?? $langSet[$langId]['PARAMS'] ?? '',
				];
			}

			$updateFields['LANG'] = array_values($langSet);
		}

		$result = \Bitrix\Im\Command::update(
			[
				'COMMAND_ID' => $commandId,
				'MODULE_ID' => 'rest',
				'APP_ID' => $clientId,
			],
			$updateFields,
		);

		if (!$result)
		{
			$this->addError(new Error('Command not found or access denied', 'COMMAND_NOT_FOUND'));

			return null;
		}

		return [
			'command' => $this->formatCommand($commandId),
		];
	}

	/**
	 * @restMethod imbot.v2.Command.answer
	 */
	public function answerAction(
		int $commandId,
		int $messageId,
		string $dialogId,
		array $fields = [],
	): ?array
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		if (!$this->validateCommandOwnership($commandId))
		{
			return null;
		}

		$botId = $this->getBotId();
		$clientId = $this->getClientId();

		$messageFields = [
			'DIALOG_ID' => $dialogId,
		];

		if (isset($fields['message']))
		{
			$messageFields['MESSAGE'] = $fields['message'];
		}

		if (isset($fields['attach']))
		{
			$messageFields['ATTACH'] = $fields['attach'];
		}

		if (isset($fields['keyboard']))
		{
			$messageFields['KEYBOARD'] = $fields['keyboard'];
		}

		if (isset($fields['menu']))
		{
			$messageFields['MENU'] = $fields['menu'];
		}

		if (isset($fields['system']))
		{
			$messageFields['SYSTEM'] = self::normalizeBooleanVariable($fields['system']) ? 'Y' : 'N';
		}

		if (isset($fields['urlPreview']))
		{
			$messageFields['URL_PREVIEW'] = self::normalizeBooleanVariable($fields['urlPreview']) ? 'Y' : 'N';
		}

		$result = \Bitrix\Im\Command::addMessage(
			[
				'MESSAGE_ID' => $messageId,
				'COMMAND_ID' => $commandId,
				'MODULE_ID' => 'rest',
				'APP_ID' => $clientId,
			],
			$messageFields,
		);

		if ($result === false)
		{
			$this->addError(new Error('Failed to send command answer', 'COMMAND_ANSWER_FAILED'));

			return null;
		}

		return ['result' => true];
	}

	/**
	 * @restMethod imbot.v2.Command.list
	 */
	public function listAction(): ?array
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		$botId = $this->getBotId();

		$commandEntity = new \Bitrix\Im\V2\Entity\Command\Command($botId);
		$commands = $commandEntity->toRestFormat();

		return [
			'commands' => $commands,
		];
	}

	private function validateCommandOwnership(int $commandId): bool
	{
		$botId = $this->getBotId();
		$commands = \Bitrix\Im\Command::getListCache();

		if (!isset($commands[$commandId]))
		{
			$this->addError(new Error('Command not found', 'COMMAND_NOT_FOUND'));

			return false;
		}

		if ((int)$commands[$commandId]['BOT_ID'] !== $botId)
		{
			$this->addError(new Error('Command belongs to a different bot', 'COMMAND_NOT_FOUND'));

			return false;
		}

		return true;
	}

	private function formatCommand(int $commandId): ?array
	{
		$commands = \Bitrix\Im\Command::getListCache();

		if (!isset($commands[$commandId]))
		{
			return null;
		}

		$command = $commands[$commandId];

		return [
			'id' => (int)$command['ID'],
			'botId' => (int)$command['BOT_ID'],
			'command' => '/' . $command['COMMAND'],
			'common' => $command['COMMON'] === 'Y',
			'hidden' => $command['HIDDEN'] === 'Y',
			'extranetSupport' => $command['EXTRANET_SUPPORT'] === 'Y',
		];
	}
}
