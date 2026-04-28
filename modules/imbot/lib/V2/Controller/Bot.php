<?php

declare(strict_types=1);

namespace Bitrix\Imbot\V2\Controller;

use Bitrix\Im\Model\BotTable;
use Bitrix\Im\V2\Entity\Bot\BotCollection;
use Bitrix\Im\V2\Entity\Bot\BotItem;
use Bitrix\Im\V2\Entity\Bot\BotType;
use Bitrix\Im\V2\Chat\Background\Background;
use Bitrix\Im\V2\Entity\User\Data\BotData;
use Bitrix\Imbot\V2\Error\BotError;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Rest\HandlerHelper;

class Bot extends BotController
{
	private const BOT_LIMIT_DEFAULT = 100;

	protected function processBeforeAction(Action $action): bool
	{
		$restServer = $this->resolveRestServer();
		if ($restServer === null)
		{
			$this->addError(new Error('REST server context not available', 'REST_SERVER_NOT_FOUND'));

			return false;
		}

		$this->clientId = $this->resolveClientId($restServer);

		if ($this->clientId === null && $action->getName() === 'register')
		{
			$params = $this->getSourceParametersList()[0] ?? [];
			$fields = $params['fields'] ?? $params['FIELDS'] ?? [];
			$botToken = $fields['botToken'] ?? $fields['BOT_TOKEN'] ?? null;
			if ($botToken !== null && $botToken !== '')
			{
				$this->clientId = self::buildCustomClientId($botToken);
			}
		}

		if ($this->clientId === null)
		{
			$this->addError(new Error(
				'Bot token not specified (botToken is required for webhook auth)',
				'BOT_TOKEN_NOT_SPECIFIED',
			));

			return false;
		}

		return true;
	}

	/**
	 * @restMethod imbot.v2.Bot.register
	 */
	public function registerAction(
		\CRestServer $restServer,
		array $fields = [],
	): ?array
	{
		$code = $fields['code'] ?? $fields['CODE'] ?? '';
		$properties = $fields['properties'] ?? $fields['PROPERTIES'] ?? [];
		$type = $fields['type'] ?? $fields['TYPE'] ?? 'bot';
		$eventMode = $fields['eventMode'] ?? $fields['EVENT_MODE'] ?? 'fetch';
		$webhookUrl = $fields['webhookUrl'] ?? $fields['WEBHOOK_URL'] ?? null;
		$isHidden = $fields['isHidden'] ?? $fields['IS_HIDDEN'] ?? 'N';
		$isReactionsEnabled = $fields['isReactionsEnabled'] ?? $fields['IS_REACTIONS_ENABLED'] ?? 'Y';
		$isSupportOpenline = $fields['isSupportOpenline'] ?? $fields['IS_SUPPORT_OPENLINE'] ?? 'N';
		$backgroundId = $fields['backgroundId'] ?? $fields['BACKGROUND_ID'] ?? null;

		if ($code === '')
		{
			$this->addError(new BotError(BotError::CODE_REQUIRED));

			return null;
		}

		if (empty($properties) || empty($properties['name'] ?? $properties['NAME'] ?? ''))
		{
			$this->addError(new BotError(BotError::PROPERTIES_REQUIRED));

			return null;
		}

		$eventMode = mb_strtolower((string)$eventMode);
		if ($eventMode !== 'fetch' && $eventMode !== 'webhook')
		{
			$this->addError(new BotError(BotError::INVALID_EVENT_MODE));

			return null;
		}

		if ($eventMode === 'webhook' && ($webhookUrl === null || $webhookUrl === ''))
		{
			$this->addError(new BotError(BotError::WEBHOOK_URL_REQUIRED));

			return null;
		}

		$clientId = $this->getClientId();

		// Bot::register() deduplicates by MODULE_ID+CODE, ignoring APP_ID.
		// Pre-check to prevent returning another app's bot.
		$existingBot = BotTable::getList([
			'filter' => ['=MODULE_ID' => 'rest', '=CODE' => $code],
			'limit' => 1,
		])->fetch();

		if ($existingBot)
		{
			if ($existingBot['APP_ID'] === $clientId)
			{
				$botItem = BotItem::createFromData($existingBot, true);

				return $this->toRestFormat($botItem);
			}

			$this->addError(new BotError(BotError::CODE_ALREADY_TAKEN));

			return null;
		}

		if (Loader::includeModule('bitrix24'))
		{
			$appBotCount = \Bitrix\Im\Model\BotTable::getCount(['=APP_ID' => $clientId]);
			$limit = (int)(\Bitrix\Bitrix24\Feature::getVariable('imbot_rest_register_limit') ?: self::BOT_LIMIT_DEFAULT);

			if ($appBotCount >= $limit)
			{
				$this->addError(new BotError(BotError::LIMIT_EXCEEDED));

				return null;
			}
		}

		$botType = BotType::fromRestName($type);
		if ($botType === null)
		{
			$this->addError(new BotError(BotError::INVALID_TYPE));

			return null;
		}
		$dbType = $botType->value;

		$methods = [];
		if ($eventMode === 'webhook')
		{
			$methods = $this->prepareWebhookUrl($webhookUrl);
			if ($methods === null)
			{
				return null;
			}
		}

		$rawAvatar = $properties['avatar'] ?? $properties['PERSONAL_PHOTO'] ?? null;
		if (!empty($rawAvatar))
		{
			$avatar = $this->processAvatar($rawAvatar, $code);
			if ($avatar === null)
			{
				return null;
			}
			$properties['PERSONAL_PHOTO'] = $avatar;
		}
		unset($properties['avatar']);

		$userProperties = $this->mapUserProperties($properties);
		$dbBackgroundId = Background::normalizeBackgroundId($backgroundId);

		$registerFields = array_merge($methods, [
			'CODE' => $code,
			'TYPE' => $dbType,
			'MODULE_ID' => 'rest',
			'APP_ID' => $clientId,
			'PROPERTIES' => $userProperties,
			'OPENLINE' => self::normalizeBooleanVariable($isSupportOpenline) ? 'Y' : 'N',
			'HIDDEN' => self::normalizeBooleanVariable($isHidden) ? 'Y' : 'N',
			'REACTIONS_ENABLED' => self::normalizeBooleanVariable($isReactionsEnabled) ? 'Y' : 'N',
			'BACKGROUND_ID' => $dbBackgroundId,
			'EVENT_MODE' => mb_strtoupper($eventMode),
		]);

		$botId = \Bitrix\Im\Bot::register($registerFields);

		if (!$botId)
		{
			$this->addError(new BotError(BotError::REGISTER_FAILED));

			return null;
		}

		if ($eventMode === 'webhook')
		{
			$this->bindV2RestEvents($restServer, $botId, $methods, $clientId);
		}

		$botItem = BotItem::createFromId($botId, true);

		return $this->toRestFormat($botItem);
	}

	/**
	 * @restMethod imbot.v2.Bot.unregister
	 */
	public function unregisterAction(): ?array
	{
		$botId = $this->resolveAndValidateBot();
		if ($botId === 0)
		{
			return null;
		}

		$result = \Bitrix\Im\Bot::unRegister([
			'BOT_ID' => $botId,
			'MODULE_ID' => 'rest',
			'APP_ID' => $this->getClientId(),
		]);

		if (!$result)
		{
			$this->addError(new BotError(BotError::UNREGISTER_FAILED));

			return null;
		}

		return ['result' => true];
	}

	/**
	 * @restMethod imbot.v2.Bot.update
	 */
	public function updateAction(
		\CRestServer $restServer,
		array $fields = [],
	): ?array
	{
		$botId = $this->resolveAndValidateBot();
		if ($botId === 0)
		{
			return null;
		}

		$clientId = $this->getClientId();
		$currentBot = BotData::getInstance($botId)->toArray();

		$botUpdateFields = [];

		if (!empty($fields['properties'] ?? $fields['PROPERTIES'] ?? []))
		{
			$rawProperties = $fields['properties'] ?? $fields['PROPERTIES'] ?? [];

			$rawAvatar = $rawProperties['avatar'] ?? $rawProperties['PERSONAL_PHOTO'] ?? null;
			if (!empty($rawAvatar))
			{
				$botCode = $currentBot['CODE'] ?? '';
				$avatarFileId = $this->processAvatar($rawAvatar, $botCode, true);
				if ($avatarFileId === null)
				{
					return null;
				}
				$rawProperties['PERSONAL_PHOTO'] = $avatarFileId;
			}
			unset($rawProperties['avatar']);

			$botUpdateFields['PROPERTIES'] = $this->mapUserProperties($rawProperties);
		}

		if (isset($fields['isHidden']))
		{
			$botUpdateFields['HIDDEN'] = self::normalizeBooleanVariable($fields['isHidden']) ? 'Y' : 'N';
		}
		if (isset($fields['isReactionsEnabled']))
		{
			$botUpdateFields['REACTIONS_ENABLED'] = self::normalizeBooleanVariable($fields['isReactionsEnabled']) ? 'Y' : 'N';
		}
		if (isset($fields['isSupportOpenline']))
		{
			$botUpdateFields['OPENLINE'] = self::normalizeBooleanVariable($fields['isSupportOpenline']) ? 'Y' : 'N';
		}
		if (isset($fields['backgroundId']))
		{
			$botUpdateFields['BACKGROUND_ID'] = Background::normalizeBackgroundId($fields['backgroundId']);
		}
		if (isset($fields['eventMode']))
		{
			$normalizedMode = mb_strtolower((string)$fields['eventMode']);
			if ($normalizedMode !== 'fetch' && $normalizedMode !== 'webhook')
			{
				$this->addError(new BotError(BotError::INVALID_EVENT_MODE));

				return null;
			}
			$botUpdateFields['EVENT_MODE'] = mb_strtoupper($normalizedMode);
		}

		if (!empty($botUpdateFields))
		{
			\Bitrix\Im\Bot::update(
				['BOT_ID' => $botId, 'MODULE_ID' => 'rest', 'APP_ID' => $clientId],
				$botUpdateFields,
			);
		}

		$effectiveEventMode = mb_strtolower(
			$fields['eventMode'] ?? $currentBot['EVENT_MODE'] ?? 'fetch'
		);

		if ($effectiveEventMode === 'webhook' && !empty($fields['webhookUrl'] ?? null))
		{
			$methods = $this->prepareWebhookUrl($fields['webhookUrl']);
			if ($methods !== null)
			{
				$this->bindV2RestEvents($restServer, $botId, $methods, $clientId);
			}
		}

		\Bitrix\Im\Bot::clearCache();
		$botItem = BotItem::createFromId($botId, true);

		return $this->toRestFormat($botItem);
	}

	/**
	 * @restMethod imbot.v2.Bot.get
	 */
	public function getAction(): ?array
	{
		$botData = $this->resolveFlexibleBot();
		if ($botData === null)
		{
			return null;
		}

		$isOwner = ($botData['APP_ID'] ?? '') === $this->getClientId();

		$botItem = BotItem::createFromData($botData, $isOwner);

		return $this->toRestFormat($botItem);
	}

	/**
	 * @restMethod imbot.v2.Bot.list
	 */
	public function listAction(
		?array $filter = null,
		int $limit = 50,
		int $offset = 0,
	): ?array
	{
		$clientId = $this->getClientId();

		$dbFilter = ['=APP_ID' => $clientId];
		if (!empty($filter['type']))
		{
			$filterType = BotType::fromRestName($filter['type']);
			if ($filterType !== null)
			{
				$dbFilter['=TYPE'] = $filterType->value;
			}
		}

		$rows = BotTable::getList([
			'filter' => $dbFilter,
			'select' => ['BOT_ID'],
			'limit' => $limit,
			'offset' => $offset,
		]);

		$botIds = [];
		while ($row = $rows->fetch())
		{
			$botIds[] = (int)$row['BOT_ID'];
		}

		$collection = BotCollection::initByBotIds($botIds, true);

		return $this->filterOutput([
			'bots' => $collection->toRestFormat(),
			'users' => (new \Bitrix\Im\V2\Entity\User\UserCollection($botIds))
				->getUnique()
				->toRestFormat(),
			'hasNextPage' => count($botIds) === $limit,
		]);
	}

	protected function resolveAndValidateBot(): int
	{
		return $this->resolveBotId($this->clientId);
	}

	protected function resolveFlexibleBot(): ?array
	{
		$requestBotId = (int)($this->getRequestParamAny(['botId', 'BOT_ID']) ?? 0);
		if ($requestBotId > 0)
		{
			$botData = BotData::getInstance($requestBotId)->toArray();
			if (empty($botData))
			{
				$this->addError(new Error('Bot not found', 'BOT_NOT_FOUND'));

				return null;
			}

			return $botData;
		}

		$code = $this->getRequestParamAny(['code', 'CODE']);
		if ($code !== null)
		{
			$row = BotTable::getList([
				'filter' => ['=CODE' => $code],
				'limit' => 1,
			])->fetch();

			if ($row)
			{
				return $row;
			}

			$this->addError(new Error('Bot not found', 'BOT_NOT_FOUND'));

			return null;
		}

		$this->addError(new Error('botId or code is required', 'PARAMS_REQUIRED'));

		return null;
	}


	private function prepareWebhookUrl(?string $webhookUrl): ?array
	{
		if ($webhookUrl === null || $webhookUrl === '')
		{
			return [];
		}

		if (Loader::includeModule('rest'))
		{
			try
			{
				HandlerHelper::checkCallback($webhookUrl);
			}
			catch (\Bitrix\Rest\RestException $e)
			{
				$this->addError(new BotError(BotError::INVALID_CALLBACK, $e->getMessage()));

				return null;
			}
		}

		return ['EVENT_HANDLER' => $webhookUrl];
	}

	private function bindV2RestEvents(
		\CRestServer $restServer,
		int $botId,
		array $methods,
		string $clientId,
	): void
	{
		if (!Loader::includeModule('rest'))
		{
			return;
		}

		$handlerUrl = $methods['EVENT_HANDLER'] ?? null;
		if ($handlerUrl === null || $handlerUrl === '')
		{
			return;
		}

		$appCode = $clientId;

		$dbRes = \Bitrix\Rest\AppTable::getList([
			'filter' => ['=CLIENT_ID' => $clientId],
			'select' => ['ID'],
		]);
		$arApp = $dbRes->fetch();
		$appId = $arApp['ID'] ?? '';

		$v2Events = [
			'OnImBotV2MessageAdd' => 'onImBotV2MessageAdd',
			'OnImBotV2MessageUpdate' => 'onImBotV2MessageUpdate',
			'OnImBotV2MessageDelete' => 'onImBotV2MessageDelete',
			'OnImBotV2JoinChat' => 'onImBotV2JoinChat',
			'OnImBotV2Delete' => 'onImBotV2Delete',
			'OnImBotV2ContextGet' => 'onImBotV2ContextGet',
			'OnImBotV2CommandAdd' => 'onImBotV2CommandAdd',
			'OnImBotV2ReactionChange' => 'onImBotV2ReactionChange',
		];

		foreach ($v2Events as $restEventName => $phpEventName)
		{
			$updateFields = [
				'APP_ID' => $appId,
				'EVENT_NAME' => mb_strtoupper($restEventName),
				'EVENT_HANDLER' => $handlerUrl,
				'APPLICATION_TOKEN' => $appCode,
			];
			$insertFields = [
				...$updateFields,
				'USER_ID' => 0,
			];

			\Bitrix\Rest\EventTable::merge($insertFields, $updateFields);
			\Bitrix\Rest\Event\Sender::bind('im', $phpEventName);
		}
	}

	public static function unbindV2RestEvents(int $restAppId): void
	{
		if (!Loader::includeModule('rest'))
		{
			return;
		}

		$v2Events = [
			'ONIMBOTV2MESSAGEADD',
			'ONIMBOTV2MESSAGEUPDATE',
			'ONIMBOTV2MESSAGEDELETE',
			'ONIMBOTV2JOINCHAT',
			'ONIMBOTV2DELETE',
			'ONIMBOTV2CONTEXTGET',
			'ONIMBOTV2COMMANDADD',
			'ONIMBOTV2REACTIONCHANGE',
		];

		foreach ($v2Events as $eventName)
		{
			$res = \Bitrix\Rest\EventTable::getList([
				'filter' => ['=EVENT_NAME' => $eventName, '=APP_ID' => $restAppId],
				'select' => ['ID'],
			]);
			while ($row = $res->fetch())
			{
				\Bitrix\Rest\EventTable::delete($row['ID']);
			}
		}
	}

	/**
	 * Validates and saves uploaded avatar image.
	 *
	 * CUser::Add() (register) accepts a file array, but Bot::update() expects a numeric file ID.
	 */
	private function processAvatar(mixed $avatarData, string $botCode, bool $asFileId = false): array|int|null
	{
		if (empty($avatarData))
		{
			return null;
		}

		$avatar = \CRestUtil::saveFile($avatarData, $botCode . '.png');
		if (!$avatar || !str_starts_with($avatar['type'], 'image/'))
		{
			$this->addError(new BotError(BotError::AVATAR_INCORRECT_TYPE));

			return null;
		}

		$imageCheck = (new \Bitrix\Main\File\Image($avatar['tmp_name']))->getInfo();
		if (
			!$imageCheck
			|| !$imageCheck->getWidth()
			|| $imageCheck->getWidth() > 5000
			|| !$imageCheck->getHeight()
			|| $imageCheck->getHeight() > 5000
		)
		{
			$this->addError(new BotError(BotError::AVATAR_INCORRECT_SIZE));

			return null;
		}

		$avatar['MODULE_ID'] = 'imbot';

		if ($asFileId)
		{
			$fileId = \CFile::saveFile($avatar, 'imbot');

			return $fileId ?: null;
		}

		return $avatar;
	}

	private function mapUserProperties(array $properties): array
	{
		$mapped = [
			'NAME' => $properties['name'] ?? $properties['NAME'] ?? '',
			'LAST_NAME' => $properties['lastName'] ?? $properties['LAST_NAME'] ?? '',
			'COLOR' => self::normalizeColorCode($properties['color'] ?? $properties['COLOR'] ?? ''),
			'WORK_POSITION' => $properties['workPosition'] ?? $properties['WORK_POSITION'] ?? '',
			'PERSONAL_GENDER' => $properties['gender'] ?? $properties['PERSONAL_GENDER'] ?? '',
		];

		if (isset($properties['PERSONAL_PHOTO']))
		{
			$mapped['PERSONAL_PHOTO'] = $properties['PERSONAL_PHOTO'];
		}

		return $mapped;
	}
}
