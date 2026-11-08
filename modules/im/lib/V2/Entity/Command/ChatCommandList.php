<?php

namespace Bitrix\Im\V2\Entity\Command;

use Bitrix\Im\Bot;
use Bitrix\Im\Model\BotTable;
use Bitrix\Im\Model\CommandLangTable;
use Bitrix\Im\Model\CommandTable;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Entity\User\UserCollection;
use Bitrix\Im\V2\Rest\RestConvertible;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Loader;

/**
 * Aggregates the list of slash commands visible in a chat.
 *
 * Two batch queries to CommandTable (bots of the chat + portal common slice), a single
 * batch openline read, batch localization by COMMAND_ID, a single batch resolve of
 * owner-bot names and a per-chat file cache keyed by (chatId, botSetHash, lang). System
 * default commands are never mixed in and HIDDEN is filtered at the query level. Rows are
 * ordered as nonCommon (chat bots) then common slice.
 */
class ChatCommandList implements RestConvertible
{
	protected const CACHE_PATH = '/bx/im/chat_command_list/';
	protected const CACHE_TTL = 3600;
	protected const COMMON_SLICE_LIMIT = 500;
	protected const REST_NAME = 'commands';

	private Chat $chat;
	private array $resultByLang = [];

	public function __construct(Chat $chat)
	{
		$this->chat = $chat;
	}

	public static function getRestEntityName(): string
	{
		return self::REST_NAME;
	}

	public function toRestFormat(array $option = []): array
	{
		return $this->getResult($option['langId'] ?? LANGUAGE_ID)['commands'];
	}

	/**
	 * Unique bot ids of the commands in the list (for users hydration in the REST response).
	 * @return int[]
	 */
	public function getBotIds(): array
	{
		return $this->getResult(LANGUAGE_ID)['botIds'];
	}

	private function getResult(string $lang): array
	{
		$lang = $this->normalizeLang($lang);

		return $this->resultByLang[$lang] ??= $this->buildResult($lang);
	}

	/**
	 * The lang id is part of the on-disk cache path, so accept only a plain two-letter
	 * code and fall back to the default language otherwise (guards against path traversal).
	 */
	private function normalizeLang(string $lang): string
	{
		return preg_match('/^[a-z]{2}$/', $lang) === 1 ? $lang : Bot::getDefaultLanguage();
	}

	private function buildResult(string $lang): array
	{
		if ($this->chat->getEntityType() === Chat::ENTITY_TYPE_LIVECHAT)
		{
			return ['commands' => [], 'botIds' => []];
		}

		$chatId = (int)$this->chat->getId();
		$participantBotIds = $this->resolveParticipantBotIds($chatId);
		$variantKey = $this->getBotSetHash($participantBotIds) . '_' . $lang;

		$cache = $this->getSavedCache($chatId, $variantKey);
		$cached = $cache->getVars();
		if ($cached !== false)
		{
			return $cached;
		}

		$result = $this->calculate($chatId, $participantBotIds, $lang);
		$cache->startDataCache();
		$cache->endDataCache($result);

		return $result;
	}

	private function calculate(int $chatId, array $participantBotIds, string $lang): array
	{
		$rows = array_merge(
			$this->getNonCommonRows($participantBotIds),
			$this->getCommonRows($chatId)
		);
		if (empty($rows))
		{
			return ['commands' => [], 'botIds' => []];
		}

		$entityType = $this->chat->getEntityType();
		$openlineByBotId = $entityType === Chat::ENTITY_TYPE_LINE
			? $this->resolveOpenline($this->uniqueBotIds($rows))
			: [];

		$visibleRows = [];
		foreach ($rows as $row)
		{
			// Drop legacy rows saved before command-name validation: an unsafe name (e.g. with a
			// newline) must never reach the client, or it would expand into an extra command line.
			if (!CommandTable::isNameValid((string)$row['COMMAND']))
			{
				continue;
			}

			$isVisible = CommandAccessPolicy::isVisible(
				$row['COMMON'],
				(int)$row['BOT_ID'],
				$participantBotIds,
				$entityType,
				$openlineByBotId
			);
			if ($isVisible)
			{
				$visibleRows[] = $row;
			}
		}

		$visibleRows = $this->localize($visibleRows, $lang);
		$botIds = $this->uniqueBotIds($visibleRows);

		return [
			'commands' => $this->toDto($visibleRows, $this->resolveBotNames($botIds)),
			'botIds' => $botIds,
		];
	}

	/**
	 * Non-common commands of the chat bots. Taken in full: not subject to the common slice cap.
	 */
	private function getNonCommonRows(array $participantBotIds): array
	{
		if (empty($participantBotIds))
		{
			return [];
		}

		return CommandTable::query()
			->setSelect(['ID', 'BOT_ID', 'COMMAND', 'COMMON', 'EXTRANET_SUPPORT', 'MODULE_ID', 'CLASS', 'METHOD_LANG_GET'])
			->whereIn('BOT_ID', $participantBotIds)
			->where('COMMON', 'N')
			->where('HIDDEN', 'N')
			->setOrder(['BOT_ID' => 'ASC', 'ID' => 'ASC'])
			->fetchAll()
		;
	}

	/**
	 * Portal-wide common slice. Capped by COMMON_SLICE_LIMIT with a deterministic ORDER BY ID ASC;
	 * truncation is logged, not silent. Output is ordered (BOT_ID, ID) within the group.
	 */
	private function getCommonRows(int $chatId): array
	{
		$rows = CommandTable::query()
			->setSelect(['ID', 'BOT_ID', 'COMMAND', 'COMMON', 'EXTRANET_SUPPORT', 'MODULE_ID', 'CLASS', 'METHOD_LANG_GET'])
			->where('BOT_ID', '>', 0)
			->where('COMMON', 'Y')
			->where('HIDDEN', 'N')
			->setOrder(['ID' => 'ASC'])
			->setLimit(self::COMMON_SLICE_LIMIT)
			->fetchAll()
		;

		if (count($rows) === self::COMMON_SLICE_LIMIT)
		{
			(new LoggerFactory())->createById('im.ChatCommandList')?->warning(
				'Chat command common-slice truncated',
				['chatId' => $chatId, 'limit' => self::COMMON_SLICE_LIMIT],
			);
		}

		usort(
			$rows,
			static fn(array $a, array $b): int =>
				[(int)$a['BOT_ID'], (int)$a['ID']] <=> [(int)$b['BOT_ID'], (int)$b['ID']]
		);

		return $rows;
	}

	/**
	 * @return array<int, bool> botId => isOpenline
	 */
	private function resolveOpenline(array $botIds): array
	{
		if (empty($botIds))
		{
			return [];
		}

		$map = [];
		$rows = BotTable::query()
			->setSelect(['BOT_ID', 'OPENLINE'])
			->whereIn('BOT_ID', $botIds)
			->fetchAll()
		;
		foreach ($rows as $row)
		{
			$map[(int)$row['BOT_ID']] = $row['OPENLINE'] === 'Y';
		}

		return $map;
	}

	/**
	 * Localizes command rows in place, preserving order.
	 *
	 * REST-style commands take their title/params from CommandLangTable (one batch read).
	 * Module commands (MODULE_ID != 'rest') expose localization through a METHOD_LANG_GET
	 * callback and have no table rows - they are localized per row, mirroring
	 * Bitrix\Im\Command::getListCache. A falsy callback result hides the command (its row
	 * is dropped), the same way getListCache marks it HIDDEN.
	 */
	private function localize(array $rows, string $lang): array
	{
		if (empty($rows))
		{
			return $rows;
		}

		$commandIds = array_map(static fn(array $row): int => (int)$row['ID'], $rows);

		$langSet = [];
		$orm = CommandLangTable::getList([
			'filter' => ['=COMMAND_ID' => $commandIds],
		]);
		while ($langRow = $orm->fetch())
		{
			$langSet[(int)$langRow['COMMAND_ID']][$langRow['LANGUAGE_ID']] = [
				'TITLE' => $langRow['TITLE'],
				'PARAMS' => $langRow['PARAMS'],
			];
		}

		$defaultLang = Bot::getDefaultLanguage();
		$localizedRows = [];
		foreach ($rows as $row)
		{
			if ((string)($row['CLASS'] ?? '') !== '' && (string)($row['METHOD_LANG_GET'] ?? '') !== '')
			{
				$moduleLang = $this->localizeModuleCommand($row, $lang);
				if ($moduleLang === null)
				{
					continue;
				}
				$row['TITLE'] = $moduleLang['TITLE'];
				$row['PARAMS'] = $moduleLang['PARAMS'];
			}
			else
			{
				$localized = $this->pickLang($langSet[(int)$row['ID']] ?? [], $lang, $defaultLang);
				if (empty($localized['TITLE']))
				{
					// A REST command with no non-empty localized title is HIDDEN in the legacy
					// getListCache path; discovery must not diverge and expose it.
					continue;
				}
				$row['TITLE'] = $localized['TITLE'];
				$row['PARAMS'] = $localized['PARAMS'];
			}

			$localizedRows[] = $row;
		}

		return $localizedRows;
	}

	/**
	 * @return array{TITLE: string, PARAMS: string}|null null when the command is unavailable
	 *         or the callback yields no localization (HIDDEN equivalent)
	 */
	private function localizeModuleCommand(array $row, string $lang): ?array
	{
		$class = (string)$row['CLASS'];
		$method = (string)$row['METHOD_LANG_GET'];

		if (
			!Loader::includeModule((string)$row['MODULE_ID'])
			|| !class_exists($class)
			|| !method_exists($class, $method)
		)
		{
			return null;
		}

		$localized = call_user_func([$class, $method], (string)$row['COMMAND'], $lang);
		if (!$localized)
		{
			return null;
		}

		return [
			'TITLE' => (string)($localized['TITLE'] ?? ''),
			'PARAMS' => (string)($localized['PARAMS'] ?? ''),
		];
	}

	private function pickLang(array $langByLangId, string $lang, string $defaultLang): array
	{
		if (isset($langByLangId[$lang]))
		{
			return $langByLangId[$lang];
		}
		if (isset($langByLangId[$defaultLang]))
		{
			return $langByLangId[$defaultLang];
		}
		if (!empty($langByLangId))
		{
			return array_values($langByLangId)[0];
		}

		return ['TITLE' => '', 'PARAMS' => ''];
	}

	private function toDto(array $rows, array $botNames): array
	{
		$result = [];
		foreach ($rows as $row)
		{
			$botId = (int)$row['BOT_ID'];
			$result[] = [
				'id' => (int)$row['ID'],
				'botId' => $botId,
				'command' => '/' . $row['COMMAND'],
				'category' => $botNames[$botId] ?? '',
				'common' => $row['COMMON'],
				'context' => '',
				'title' => $row['TITLE'] ?? '',
				'params' => $row['PARAMS'] ?? '',
				'extranet' => $row['EXTRANET_SUPPORT'],
			];
		}

		return $result;
	}

	/**
	 * Active bot participants without hydrating the full relation collection (cache-key path).
	 *
	 * @return int[]
	 */
	private function resolveParticipantBotIds(int $chatId): array
	{
		$rows = RelationTable::query()
			->setSelect(['USER_ID'])
			->where('CHAT_ID', $chatId)
			->where('IS_HIDDEN', false)
			->where('USER.ACTIVE', true)
			->where('USER.EXTERNAL_AUTH_ID', Bot::EXTERNAL_AUTH_ID)
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['USER_ID'], $rows);
	}

	/**
	 * @param int[] $botIds
	 * @return array<int, string> botId => full name
	 */
	private function resolveBotNames(array $botIds): array
	{
		if (empty($botIds))
		{
			return [];
		}

		// Warm the per-user cache with a single query and read names from the same V2 user
		// source as the response users payload. The legacy GetUserData materialized one V2
		// user per owner inside its loop, giving an N+1 on a cold cache.
		$users = UserCollection::initByUserIds($botIds);

		$names = [];
		foreach ($botIds as $botId)
		{
			$user = $users->getById($botId);
			$names[$botId] = $user !== null ? (string)$user->getName() : '';
		}

		return $names;
	}

	/**
	 * @return int[]
	 */
	private function uniqueBotIds(array $rows): array
	{
		$ids = [];
		foreach ($rows as $row)
		{
			$botId = (int)$row['BOT_ID'];
			$ids[$botId] = $botId;
		}

		return array_values($ids);
	}

	private function getBotSetHash(array $participantBotIds): string
	{
		$ids = array_values(array_unique(array_map('intval', $participantBotIds)));
		sort($ids, SORT_NUMERIC);

		return md5(implode(',', $ids));
	}

	private function getSavedCache(int $chatId, string $variantKey): Cache
	{
		$cache = Application::getInstance()->getCache();
		$cache->initCache(self::CACHE_TTL, $variantKey, self::getCacheDir($chatId, $variantKey));

		return $cache;
	}

	private static function getCacheDir(int $chatId, string $variantKey): string
	{
		$cacheSubDir = substr(md5((string)$chatId), 2, 2);

		return self::CACHE_PATH . "{$cacheSubDir}/chat_{$chatId}/{$variantKey}/";
	}

	private static function getDeleteDir(int $chatId): string
	{
		$cacheSubDir = substr(md5((string)$chatId), 2, 2);

		return self::CACHE_PATH . "{$cacheSubDir}/chat_{$chatId}/";
	}

	public static function cleanCache(int $chatId): void
	{
		Application::getInstance()->getCache()->cleanDir(self::getDeleteDir($chatId));
	}

	/**
	 * Drops the whole per-chat command list cache. Used on command content changes
	 * (register/update/unregister) - a rare admin/deploy-time path where the affected
	 * chats are unknown, so a full directory clean is acceptable.
	 */
	public static function cleanAllCache(): void
	{
		Application::getInstance()->getCache()->cleanDir(self::CACHE_PATH);
	}
}
