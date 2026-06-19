<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Event;

use Bitrix\Im\Bot;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Entity\User\Data\BotData;
use Bitrix\Im\V2\Rest\OutputFilter;

class BotWebhookPayloadBuilder
{
	public function buildMessageAdd(array $arParams, array $arHandler): array
	{
		return $this->buildForBotList($arParams, $arHandler, static function (array $p): array {
			return (new EventPayload())->messageAdd((int)$p[1], $p[2]);
		});
	}

	public function buildMessageUpdate(array $arParams, array $arHandler): array
	{
		return $this->buildForBotList($arParams, $arHandler, static function (array $p): array {
			return (new EventPayload())->messageUpdate((int)$p[1], $p[2]);
		});
	}

	public function buildMessageDelete(array $arParams, array $arHandler): array
	{
		return $this->buildForBotList($arParams, $arHandler, static function (array $p): array {
			return (new EventPayload())->messageDelete((int)$p[1], $p[2]);
		});
	}

	public function buildContextGet(array $arParams, array $arHandler): array
	{
		return $this->buildForBotList($arParams, $arHandler, static function (array $p): array {
			return (new EventPayload())->contextGet($p[1] ?? '', $p[2] ?? []);
		});
	}

	public function buildJoinChat(array $arParams, array $arHandler): array
	{
		return $this->buildForSingleBot($arParams, $arHandler, static function (array $p): array {
			return $p[3] ?? (new EventPayload())->welcomeMessage($p[1] ?? '', $p[2] ?? []);
		});
	}

	public function buildDelete(array $arParams, array $arHandler): array
	{
		return $this->buildForSingleBot($arParams, $arHandler, static function (array $p): array {
			$v2Payload = $p[2] ?? (new EventPayload())->botDelete((int)$p[1]);

			// EventPayload::botDelete() returns ['bot' => entity] — strip it
			// to avoid overwriting $bot (which contains auth tokens for delivery).
			unset($v2Payload['bot']);

			return $v2Payload;
		});
	}

	public function buildReactionChange(array $arParams, array $arHandler): array
	{
		return $this->buildForSingleBot($arParams, $arHandler, static function (array $p): array {
			return $p[3] ?? (new EventPayload())->reactionChange((int)$p[1], $p[2]);
		});
	}

	public function buildCommandAdd(array $arParams, array $arHandler): array
	{
		$arParams = array_change_key_case($arParams, CASE_UPPER);

		$appCode = $this->resolveAppCode($arHandler);
		if ($appCode === null)
		{
			throw new BotWebhookException('Event is intended for another application', 0);
		}

		$commandData = null;
		foreach ($arParams[0] as $candidate)
		{
			$candidateAppId = (string)($candidate['APP_ID'] ?? '');
			if (!$this->appCodesEqual($candidateAppId, $appCode) || ($candidate['BOT_ID'] ?? 0) <= 0)
			{
				continue;
			}

			if (($candidate['EVENT_MODE'] ?? Bot::EVENT_MODE_WEBHOOK) === Bot::EVENT_MODE_FETCH)
			{
				continue;
			}

			$commandData = $candidate;
			break;
		}

		if ($commandData === null)
		{
			throw new BotWebhookException('No bots matched for this application', 0);
		}

		$botUserId = (int)$commandData['BOT_ID'];
		$botData = BotData::getInstance($botUserId)->toArray();
		$authData = $this->getAccessToken((int)$arHandler['APP_ID'], $botUserId);

		$bot = [
			'id' => $botUserId,
			'code' => $botData['CODE'] ?? '',
			'auth' => $authData,
		];

		$messageId = (int)$arParams[1];
		$messageFields = $arParams[2];
		$eventPayload = new EventPayload();

		$messageRest = $eventPayload->loadMessageRest($messageId);

		$chatId = $eventPayload->resolveChatId($messageFields);
		if ($chatId <= 0 && !empty($messageRest['chat_id']))
		{
			$chatId = (int)$messageRest['chat_id'];
		}

		$payload = [
			'bot' => $bot,
			'command' => [
				'id' => (int)($commandData['ID'] ?? 0),
				'command' => '/' . ($commandData['COMMAND'] ?? ''),
				'params' => $commandData['EXEC_PARAMS'] ?? '',
				'context' => mb_strtolower($commandData['CONTEXT'] ?? ''),
			],
			'message' => $messageRest,
			'chat' => $eventPayload->loadChatRest($chatId),
			'user' => $eventPayload->loadUserRest((int)($messageFields['FROM_USER_ID'] ?? 0)),
			'language' => Bot::getDefaultLanguage(),
		];

		return $this->applyFilter($this->fixDialogIdForBot($payload, $botUserId));
	}

	// region Template methods

	private function buildForBotList(array $arParams, array $arHandler, \Closure $payloadBuilder): array
	{
		$arParams = array_change_key_case($arParams, CASE_UPPER);

		$appCode = $this->resolveAppCode($arHandler);
		if ($appCode === null)
		{
			throw new BotWebhookException('Event is intended for another application', 0);
		}

		$bots = $this->resolveBots($arParams[0], $appCode, (int)$arHandler['APP_ID']);
		if (empty($bots))
		{
			throw new BotWebhookException('No bots matched for this application', 0);
		}

		$v2Payload = $arParams[3] ?? $payloadBuilder($arParams);
		$payload = array_merge(['bot' => $bots[0]], $v2Payload);

		return $this->applyFilter($this->fixDialogIdForBot($payload, $bots[0]['id']));
	}

	private function buildForSingleBot(array $arParams, array $arHandler, \Closure $payloadBuilder): array
	{
		$arParams = array_change_key_case($arParams, CASE_UPPER);

		$appCode = $this->resolveAppCode($arHandler);
		if ($appCode === null)
		{
			throw new BotWebhookException('Event is intended for another application', 0);
		}

		$botData = $arParams[0];
		$botAppId = (string)($botData['APP_ID'] ?? '');

		if (!$this->appCodesEqual($botAppId, $appCode))
		{
			throw new BotWebhookException('No bots matched for this application', 0);
		}

		if (($botData['EVENT_MODE'] ?? Bot::EVENT_MODE_WEBHOOK) === Bot::EVENT_MODE_FETCH)
		{
			throw new BotWebhookException('No bots matched for this application', 0);
		}

		$botUserId = (int)$botData['BOT_ID'];
		$authData = $this->getAccessToken((int)$arHandler['APP_ID'], $botUserId);

		$bot = [
			'id' => $botUserId,
			'code' => $botData['CODE'] ?? '',
			'auth' => $authData,
		];

		$v2Payload = $payloadBuilder($arParams);
		$payload = array_merge(['bot' => $bot], $v2Payload);

		return $this->applyFilter($this->fixDialogIdForBot($payload, $botUserId));
	}

	// endregion

	// region Helpers

	private function resolveAppCode(array $arHandler): ?string
	{
		if (!empty($arHandler['APP_CODE']))
		{
			return $arHandler['APP_CODE'];
		}

		if (!empty($arHandler['APPLICATION_TOKEN']))
		{
			return $arHandler['APPLICATION_TOKEN'];
		}

		// Legacy v1 fallback
		$parts = parse_url($arHandler['EVENT_HANDLER'] ?? '');
		parse_str($parts['query'] ?? '', $query);
		$query = array_change_key_case($query, CASE_UPPER);
		if (!empty($query['CLIENT_ID']))
		{
			return \Bitrix\Im\Bot::WEBHOOK_CLIENT_ID_PREFIX . $query['CLIENT_ID'];
		}

		return null;
	}

	private function appCodesEqual(string $botAppId, string $appCode): bool
	{
		if ($appCode === '' || $botAppId === '')
		{
			return false;
		}
		if ($botAppId === $appCode)
		{
			return true;
		}

		return mb_strlen($appCode) === 50
			&& mb_strlen($botAppId) > 50
			&& str_starts_with($botAppId, $appCode);
	}

	private function resolveBots(array $botList, string $appCode, int $appId): array
	{
		$bots = [];
		foreach ($botList as $botData)
		{
			$botAppId = (string)($botData['APP_ID'] ?? '');
			if (!$this->appCodesEqual($botAppId, $appCode))
			{
				continue;
			}

			if (($botData['EVENT_MODE'] ?? Bot::EVENT_MODE_WEBHOOK) === Bot::EVENT_MODE_FETCH)
			{
				continue;
			}

			$authData = $this->getAccessToken($appId, (int)$botData['BOT_ID']);
			$bots[] = [
				'id' => (int)$botData['BOT_ID'],
				'code' => $botData['CODE'] ?? '',
				'auth' => $authData,
			];
		}

		return $bots;
	}

	private function getAccessToken(int $appId, int $userId): array
	{
		$session = \Bitrix\Rest\Event\Session::get();
		if (!$session)
		{
			return [];
		}

		$auth = \Bitrix\Rest\Event\Sender::getAuth(
			$appId,
			$userId,
			['EVENT_SESSION' => $session],
			[
				'sendRefreshToken' => 1,
				'sendAuth' => 1,
			],
		);

		return $auth ?: [];
	}

	/**
	 * For private chats, recalculates dialogId from the bot's perspective
	 * (bot needs sender's userId, not its own).
	 */
	private function fixDialogIdForBot(array $payload, int $botUserId): array
	{
		if (
			$botUserId > 0
			&& isset($payload['chat']['id'])
			&& ($payload['chat']['type'] ?? '') === 'private'
		)
		{
			$chat = Chat::getInstance((int)$payload['chat']['id']);
			if ($chat instanceof Chat\PrivateChat)
			{
				$payload['chat']['dialogId'] = $chat->getDialogId($botUserId);
			}
		}

		return $payload;
	}

	private function applyFilter(array $payload): array
	{
		return OutputFilter::filterForEventLog($payload);
	}

	// endregion
}
