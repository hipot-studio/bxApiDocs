<?php

declare(strict_types=1);

namespace Bitrix\Im;

use Bitrix\Im\Model\BotTable;
use Bitrix\Main\Loader;

class RestBot
{
	public const BOT_TOKEN_MAX_LENGTH = 40;
	private const APPLICATION_TOKEN_MAX_LENGTH = 50;

	private const V2_EVENTS = [
		'OnImBotV2MessageAdd' => 'onImBotV2MessageAdd',
		'OnImBotV2MessageUpdate' => 'onImBotV2MessageUpdate',
		'OnImBotV2MessageDelete' => 'onImBotV2MessageDelete',
		'OnImBotV2JoinChat' => 'onImBotV2JoinChat',
		'OnImBotV2Delete' => 'onImBotV2Delete',
		'OnImBotV2ContextGet' => 'onImBotV2ContextGet',
		'OnImBotV2CommandAdd' => 'onImBotV2CommandAdd',
		'OnImBotV2ReactionChange' => 'onImBotV2ReactionChange',
	];

	/**
	 * Registers a Rest Bot
	 *
	 * Event modes (EVENT_MODE field, default WEBHOOK):
	 *  - WEBHOOK: server pushes events to WEBHOOK_URL. Requires WEBHOOK_URL; URL is validated; the bot is auto-subscribed to all V2 REST events.
	 *  - FETCH: bot polls events. WEBHOOK_URL is ignored; no event subscriptions are created.
	 *
	 * Exactly one of BOT_TOKEN / APP_ID must be supplied.
	 *
	 * @param array $fields
	 *
	 * @return int|false BotId on success; false on any validation/registration failure.
	 */
	public static function register(array $fields): int|false
	{
		$eventMode = self::normalizeEventMode($fields['EVENT_MODE'] ?? null);
		$webhookUrl = (string)($fields['WEBHOOK_URL'] ?? '');
		$botToken = (string)($fields['BOT_TOKEN'] ?? '');
		$explicitAppId = (string)($fields['APP_ID'] ?? '');

		if ($botToken === '' && $explicitAppId === '')
		{
			return false;
		}
		if ($eventMode === Bot::EVENT_MODE_WEBHOOK)
		{
			if ($webhookUrl === '' || !self::isValidCallback($webhookUrl))
			{
				return false;
			}
		}

		$clientId = $botToken !== '' ? self::buildClientId($botToken) : $explicitAppId;

		unset(
			$fields['CLASS'],
			$fields['WEBHOOK_URL'],
			$fields['BOT_TOKEN'],
		);

		$registerFields = $fields;
		$registerFields['MODULE_ID'] = 'rest';
		$registerFields['APP_ID'] = $clientId;
		$registerFields['EVENT_MODE'] = $eventMode;

		$botId = Bot::register($registerFields);
		if (!$botId)
		{
			return false;
		}

		if ($eventMode === Bot::EVENT_MODE_WEBHOOK)
		{
			self::bindRestEvents((int)$botId, $webhookUrl, $clientId);
		}

		return (int)$botId;
	}

	/**
	 * Unregisters a REST bot.
	 *
	 * @param array $bot BOT_ID (required, > 0) + exactly one of BOT_TOKEN / APP_ID.
	 *
	 * @return bool true on success; false when missing identifier, ownership mismatch, or bot does not exist.
	 */
	public static function unRegister(array $bot): bool
	{
		$botId = (int)($bot['BOT_ID'] ?? 0);
		$botToken = (string)($bot['BOT_TOKEN'] ?? '');
		$explicitAppId = (string)($bot['APP_ID'] ?? '');

		if ($botId <= 0 || ($botToken === '' && $explicitAppId === ''))
		{
			return false;
		}

		$clientId = $botToken !== '' ? self::buildClientId($botToken) : $explicitAppId;

		return (bool)Bot::unRegister([
			'BOT_ID' => $botId,
			'MODULE_ID' => 'rest',
			'APP_ID' => $clientId,
		]);
	}

	/**
	 * Updates a REST bot.
	 *
	 * @param array $bot          BOT_ID (required, > 0) + exactly one of BOT_TOKEN / APP_ID.
	 * @param array $updateFields Bot::update fields + WEBHOOK_URL (optional, new URL), BOT_TOKEN (optional, new token — rotation).
	 *
	 * @return bool true on success; false on any validation/update failure.
	 */
	public static function update(array $bot, array $updateFields): bool
	{
		$botId = (int)($bot['BOT_ID'] ?? 0);
		$botToken = (string)($bot['BOT_TOKEN'] ?? '');
		$explicitAppId = (string)($bot['APP_ID'] ?? '');

		if ($botId <= 0 || ($botToken === '' && $explicitAppId === ''))
		{
			return false;
		}

		$currentClientId = $botToken !== '' ? self::buildClientId($botToken) : $explicitAppId;

		$newToken = trim((string)($updateFields['BOT_TOKEN'] ?? ''));
		$effectiveClientId = $currentClientId;
		if ($newToken !== '' && $newToken !== $botToken)
		{
			$rotated = self::rotateBotToken($botId, $currentClientId, $newToken);
			if ($rotated === false)
			{
				return false;
			}
			$effectiveClientId = $rotated;
		}

		$webhookUrl = $updateFields['WEBHOOK_URL'] ?? null;
		if ($webhookUrl !== null && $webhookUrl !== '' && !self::isValidCallback((string)$webhookUrl))
		{
			return false;
		}

		$botUpdate = $updateFields;
		unset($botUpdate['BOT_TOKEN'], $botUpdate['WEBHOOK_URL']);

		if (!empty($botUpdate))
		{
			$ok = (bool)Bot::update(
				['BOT_ID' => $botId, 'MODULE_ID' => 'rest', 'APP_ID' => $effectiveClientId],
				$botUpdate,
			);
			if (!$ok)
			{
				return false;
			}
		}

		if (empty($botUpdate))
		{
			$botData = \Bitrix\Im\V2\Entity\User\Data\BotData::getInstance($botId);
			if (!$botData->exists() || $botData->getAppId() !== $effectiveClientId)
			{
				return false;
			}
		}

		if ($webhookUrl !== null && $webhookUrl !== '')
		{
			self::unbindRestEvents($botId, $effectiveClientId);
			self::bindRestEvents($botId, (string)$webhookUrl, $effectiveClientId);
		}

		return true;
	}

	/**
	 * Subscribes the bot to the V2 webhook REST events under the given clientId.
	 *
	 * @internal Use {@see register()} / {@see update()} from third-party code;
	 *           this helper is exposed so the REST controller and tests can rebind without going
	 *           through the full register/update flow.
	 */
	public static function bindRestEvents(int $botId, string $webhookUrl, string $clientId): void
	{
		if ($botId <= 0 || $webhookUrl === '' || $clientId === '')
		{
			return;
		}
		if (!Loader::includeModule('rest'))
		{
			return;
		}

		$dbRes = \Bitrix\Rest\AppTable::getList([
			'filter' => ['=CLIENT_ID' => $clientId],
			'select' => ['ID'],
		]);
		$arApp = $dbRes->fetch();
		$appId = $arApp['ID'] ?? '';

		$applicationToken = mb_substr($clientId, 0, self::APPLICATION_TOKEN_MAX_LENGTH);

		foreach (self::V2_EVENTS as $restEventName => $phpEventName)
		{
			$updateFields = [
				'APP_ID' => $appId,
				'EVENT_NAME' => mb_strtoupper($restEventName),
				'EVENT_HANDLER' => $webhookUrl,
				'APPLICATION_TOKEN' => $applicationToken,
			];
			$insertFields = [
				...$updateFields,
				'USER_ID' => 0,
			];

			\Bitrix\Rest\EventTable::merge($insertFields, $updateFields);
			\Bitrix\Rest\Event\Sender::bind('im', $phpEventName);
		}
	}

	/**
	 * Removes the bot's V2 webhook REST event subscriptions.
	 *
	 * No-op when another bot still owns the same APP_ID (custom token shared across bots).
	 *
	 * @internal Same scope as {@see bindRestEvents()}; called from Bot::unRegister/Bot::update
	 *           in {@see \Bitrix\Im\Bot} to keep the im → imbot dependency removed.
	 */
	public static function unbindRestEvents(int $botId, string $applicationToken): void
	{
		if ($botId <= 0 || $applicationToken === '')
		{
			return;
		}
		if (!Loader::includeModule('rest'))
		{
			return;
		}

		$siblings = BotTable::getCount([
			'=APP_ID' => $applicationToken,
			'!=BOT_ID' => $botId,
		]);
		if ($siblings > 0)
		{
			return;
		}

		$tokenPrefix = mb_substr($applicationToken, 0, self::APPLICATION_TOKEN_MAX_LENGTH);

		$rows = \Bitrix\Rest\EventTable::getList([
			'filter' => [
				'%=EVENT_NAME' => 'ONIMBOTV2%',
				'=APPLICATION_TOKEN' => $tokenPrefix,
			],
			'select' => ['ID'],
		]);
		while ($row = $rows->fetch())
		{
			\Bitrix\Rest\EventTable::delete($row['ID']);
		}
	}

	/**
	 * Validates the webhook URL via {@see \Bitrix\Rest\HandlerHelper::checkCallback()}.
	 *
	 * @internal Called by the REST controller to pre-validate the URL BEFORE creating/updating
	 *           the bot row, so a specific BotError::INVALID_CALLBACK can be returned to the API client.
	 *
	 * @return bool true when the URL is acceptable; false when rest is unavailable or the URL is rejected.
	 */
	public static function isValidCallback(string $webhookUrl): bool
	{
		if (!Loader::includeModule('rest'))
		{
			return false;
		}

		try
		{
			\Bitrix\Rest\HandlerHelper::checkCallback($webhookUrl);

			return true;
		}
		catch (\Bitrix\Rest\RestException)
		{
			return false;
		}
	}

	/**
	 * Rotates the APP_ID for a webhook bot under one transaction. After successful rotation,
	 * old REST event subscriptions are rebuilt by the caller ({@see update()}) using the new clientId.
	 *
	 * @return string|false New clientId on success; false on precondition failure (length, OAuth-attempt, ownership, collision).
	 */
	private static function rotateBotToken(int $botId, string $oldClientId, string $newToken): string|false
	{
		if (!self::canRotateToken($botId, $oldClientId, $newToken))
		{
			return false;
		}

		$newClientId = self::buildClientId($newToken);
		if (self::isClientIdInUse($botId, $newClientId))
		{
			return false;
		}

		$connection = \Bitrix\Main\Application::getConnection();
		$connection->startTransaction();
		try
		{
			BotTable::update($botId, ['APP_ID' => $newClientId]);
			$commandsTouched = self::migrateCommandRows($botId, $oldClientId, $newClientId);
			$appsTouched = self::migrateAppRows($botId, $oldClientId, $newClientId);
			self::migrateEventTokens($oldClientId, $newClientId);

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();
			throw $e;
		}

		self::invalidateRotationCaches($botId, $commandsTouched, $appsTouched);

		return $newClientId;
	}

	private static function canRotateToken(int $botId, string $oldClientId, string $newToken): bool
	{
		if (mb_strlen($newToken) > self::BOT_TOKEN_MAX_LENGTH)
		{
			return false;
		}
		if (!str_starts_with($oldClientId, Bot::WEBHOOK_CLIENT_ID_PREFIX))
		{
			return false;
		}

		$botData = \Bitrix\Im\V2\Entity\User\Data\BotData::getInstance($botId);

		return $botData->exists() && $botData->getAppId() === $oldClientId;
	}

	private static function isClientIdInUse(int $botId, string $clientId): bool
	{
		$row = BotTable::getList([
			'filter' => ['=APP_ID' => $clientId, '!=BOT_ID' => $botId],
			'select' => ['BOT_ID'],
			'limit' => 1,
		])->fetch();

		return (bool)$row;
	}

	private static function migrateCommandRows(int $botId, string $oldClientId, string $newClientId): bool
	{
		$touched = false;
		$rows = \Bitrix\Im\Model\CommandTable::getList([
			'filter' => ['=BOT_ID' => $botId, '=APP_ID' => $oldClientId],
			'select' => ['ID'],
		]);
		while ($row = $rows->fetch())
		{
			\Bitrix\Im\Model\CommandTable::update($row['ID'], ['APP_ID' => $newClientId]);
			$touched = true;
		}

		return $touched;
	}

	private static function migrateAppRows(int $botId, string $oldClientId, string $newClientId): bool
	{
		$touched = false;
		$rows = \Bitrix\Im\Model\AppTable::getList([
			'filter' => ['=BOT_ID' => $botId, '=APP_ID' => $oldClientId],
			'select' => ['ID'],
		]);
		while ($row = $rows->fetch())
		{
			\Bitrix\Im\Model\AppTable::update($row['ID'], ['APP_ID' => $newClientId]);
			$touched = true;
		}

		return $touched;
	}

	private static function migrateEventTokens(string $oldClientId, string $newClientId): void
	{
		if (!\Bitrix\Main\Loader::includeModule('rest'))
		{
			return;
		}

		$oldPrefix = mb_substr($oldClientId, 0, self::APPLICATION_TOKEN_MAX_LENGTH);
		$newPrefix = mb_substr($newClientId, 0, self::APPLICATION_TOKEN_MAX_LENGTH);
		if ($oldPrefix === $newPrefix)
		{
			return;
		}

		$rows = \Bitrix\Rest\EventTable::getList([
			'filter' => ['%=EVENT_NAME' => 'ONIMBOTV2%', '=APPLICATION_TOKEN' => $oldPrefix],
			'select' => ['ID'],
		]);
		while ($row = $rows->fetch())
		{
			\Bitrix\Rest\EventTable::update($row['ID'], ['APPLICATION_TOKEN' => $newPrefix]);
		}
	}

	private static function invalidateRotationCaches(int $botId, bool $commandsTouched, bool $appsTouched): void
	{
		if ($commandsTouched)
		{
			\Bitrix\Main\Data\Cache::createInstance()->cleanDir(\Bitrix\Im\Command::CACHE_PATH);
		}
		if ($appsTouched)
		{
			\Bitrix\Main\Data\Cache::createInstance()->cleanDir(\Bitrix\Im\App::CACHE_PATH);
		}
		\Bitrix\Im\V2\Entity\User\Data\BotData::cleanCache($botId);
	}

	private static function buildClientId(string $botToken): string
	{
		return Bot::WEBHOOK_CLIENT_ID_PREFIX . $botToken;
	}

	private static function normalizeEventMode(mixed $raw): string
	{
		$mode = mb_strtoupper((string)($raw ?? ''));
		return $mode === Bot::EVENT_MODE_FETCH ? Bot::EVENT_MODE_FETCH : Bot::EVENT_MODE_WEBHOOK;
	}
}
