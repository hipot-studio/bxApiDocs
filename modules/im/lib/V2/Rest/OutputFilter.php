<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Rest;

class OutputFilter
{
	private const FILE_EXCLUDE = [
		'urlPreview',
		'urlDownload',
		'urlShow',
		'viewerAttrs',
		'mediaUrl',
		'progress',
		'status',
	];

	private const USER_EXCLUDE = [
		'botData',
		'avatarHr',
		'network',
	];

	private const CHAT_EXCLUDE = [
		'role',
		'muteList',
	];

	private const MESSAGE_PARAMS_EXCLUDE = [
		'SENDING',
		'SENDING_TS',
		'COPILOT_PROMPT_CODE',
		'COPILOT_ROLE',
		'COPILOT_REASONING',
		'AI_ASSISTANT_MCP_AUTH_ID',
		'AI_TASK_TRIGGER_MESSAGE_ID',
	];

	public static function filterForEventLog(array $payload): array
	{
		$payload = self::filter($payload);

		if (isset($payload['chat']) && is_array($payload['chat']))
		{
			$payload['chat'] = self::filterChat($payload['chat']);
		}

		return $payload;
	}

	public static function filter(array $payload): array
	{
		if (isset($payload['message']) && is_array($payload['message']))
		{
			$payload['message'] = self::filterMessage($payload['message']);
		}

		if (isset($payload['messages']) && is_array($payload['messages']))
		{
			foreach ($payload['messages'] as $key => $message)
			{
				if (is_array($message))
				{
					$payload['messages'][$key] = self::filterMessage($message);
				}
			}
		}

		if (isset($payload['user']) && is_array($payload['user']))
		{
			$payload['user'] = self::filterUser($payload['user']);
		}

			if (isset($payload['users']) && is_array($payload['users']))
		{
			$payload['users'] = self::filterUserCollection($payload['users']);
		}

		if (isset($payload['file']) && is_array($payload['file']))
		{
			$payload['file'] = self::filterFile($payload['file']);
		}

		if (isset($payload['files']) && is_array($payload['files']))
		{
			$payload['files'] = self::filterFileCollection($payload['files']);
		}

		return $payload;
	}

	public static function filterChat(array $chat): array
	{
		foreach (self::CHAT_EXCLUDE as $key)
		{
			unset($chat[$key]);
		}

		return $chat;
	}

	public static function filterFile(array $file): array
	{
		foreach (self::FILE_EXCLUDE as $key)
		{
			unset($file[$key]);
		}

		return $file;
	}

	public static function filterUser(array $user): array
	{
		foreach (self::USER_EXCLUDE as $key)
		{
			unset($user[$key]);
		}

		return $user;
	}

	public static function filterMessage(array $message): array
	{
		if (isset($message['params']) && is_array($message['params']))
		{
			foreach (self::MESSAGE_PARAMS_EXCLUDE as $key)
			{
				unset($message['params'][$key]);
			}
		}

		return $message;
	}

	public static function filterFileCollection(array $files): array
	{
		foreach ($files as $key => $file)
		{
			if (is_array($file))
			{
				$files[$key] = self::filterFile($file);
			}
		}

		return $files;
	}

	public static function filterUserCollection(array $users): array
	{
		foreach ($users as $key => $user)
		{
			if (is_array($user))
			{
				$users[$key] = self::filterUser($user);
			}
		}

		return $users;
	}
}
