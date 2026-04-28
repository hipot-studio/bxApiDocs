<?php

namespace Bitrix\Im\V2\Entity\User\Data;

use Bitrix\Im\Model\BotTable;
use Bitrix\Im\V2\Chat\Background\BackgroundId;
use Bitrix\Im\V2\Entity\Command\Command;
use Bitrix\Im\V2\Rest\RestEntity;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Loader;

class BotData implements RestEntity
{
	private const CACHE_PATH = '/bx/im/bot/new_cache_v1/';
	private const CACHE_TTL = 31536000;
	private const CACHE_KEY = 'bot_data_';

	private const BOT_TYPE = [
		'TYPE_HUMAN' => 'H',
		'TYPE_NETWORK' => 'N',
		'TYPE_OPENLINE' => 'O',
		'TYPE_SUPERVISOR' => 'S',
		'TYPE_PERSONAL_ASSISTANT' => 'P',
	];

	private static array $staticCache = [];

	private int $id;
	private array $botData = [];
	private array $commands = [];

	private function __construct()
	{
	}

	public static function getInstance(?int $id): self
	{
		if (!isset($id))
		{
			return new self;
		}

		if (isset(self::$staticCache[$id]))
		{
			return self::$staticCache[$id];
		}

		self::$staticCache[$id] = new self;
		self::$staticCache[$id]->id = $id;
		self::$staticCache[$id]->fillBotData();

		return self::$staticCache[$id];
	}

	private function fillBotData(): void
	{
		$botId = $this->getId();

		$cache = $this->getSavedCache($botId);
		$cachedBot = $cache->getVars();

		if ($cachedBot !== false)
		{
			$this->botData = $cachedBot;

			return;
		}

		$botData = $this->getBotDataFromDb($botId);
		if ($botData === null)
		{
			$this->botData = [];

			return;
		}

		$this->saveInCache($cache, $botData);

		$this->botData = $botData;
	}

	public function getId(): int
	{
		return $this->id ?? 0;
	}

	public function getCode(): string
	{
		return $this->botData['CODE'] ?? '';
	}

	public function isHidden(): bool
	{
		return ($this->botData['HIDDEN'] ?? 'N') === 'Y';
	}

	public function getType(): string
	{
		return $this->botData['TYPE'] ?? '';
	}

	public function getClass(): string
	{
		return $this->botData['CLASS'] ?? '';
	}

	public function getAppId(): string
	{
		return $this->botData['APP_ID'] ?? '';
	}

	public function getLang(): string
	{
		return $this->botData['LANG'] ?? '';
	}

	public function getModuleId(): string
	{
		return $this->botData['MODULE_ID'] ?? '';
	}

	public function getTextPrivateWelcomeMessage(): string
	{
		return $this->botData['TEXT_PRIVATE_WELCOME_MESSAGE'] ?? '';
	}

	public function getOpenline(): string
	{
		return $this->botData['OPENLINE'] ?? '';
	}

	public function getMethodBotDelete(): string
	{
		return $this->botData['METHOD_BOT_DELETE'] ?? '';
	}

	public function toArray(): array
	{
		return $this->botData;
	}

	public static function getRestEntityName(): string
	{
		return 'botData';
	}

	public function toRestFormat(array $option = []): array
	{
		if (empty($this->botData))
		{
			return [];
		}

		$type = 'bot';
		$code = $this->botData['CODE'];

		if ($this->botData['TYPE'] === self::BOT_TYPE['TYPE_HUMAN'])
		{
			$type = 'human';
		}
		else if ($this->botData['TYPE'] === self::BOT_TYPE['TYPE_NETWORK'])
		{
			$type = 'network';

			if ($this->botData['CLASS'] === 'Bitrix\ImBot\Bot\Support24')
			{
				$type = 'support24';
				$code = 'network_cloud';
			}
			else if ($this->botData['CLASS'] === 'Bitrix\ImBot\Bot\Partner24')
			{
				$type = 'support24';
				$code = 'network_partner';
			}
			else if ($this->botData['CLASS'] === 'Bitrix\ImBot\Bot\SupportBox')
			{
				$type = 'support24';
				$code = 'network_box';
			}
		}
		else if ($this->botData['TYPE'] === self::BOT_TYPE['TYPE_OPENLINE'])
		{
			$type = 'openline';
		}
		else if ($this->botData['TYPE'] === self::BOT_TYPE['TYPE_SUPERVISOR'])
		{
			$type = 'supervisor';
		}
		else if ($this->botData['TYPE'] === self::BOT_TYPE['TYPE_PERSONAL_ASSISTANT'])
		{
			$type = 'personal';
		}

		// TODO remove 'openline' and 'id', added for backward compatibility.
		return [
			'id' => $this->getId(),
			'code' => $code,
			'type' => $type,
			'appId' => $this->botData['APP_ID'],
			'isHidden' => $this->botData['HIDDEN'] === 'Y',
			'isSupportOpenline' => $this->botData['OPENLINE'] === 'Y',
			'openline' => $this->botData['OPENLINE'] === 'Y',
			'backgroundId' => BackgroundId::normalize($this->botData['BACKGROUND_ID'] ?? null),
			'reactionsEnabled' => ($this->botData['REACTIONS_ENABLED'] ?? 'N') === 'Y',
		];
	}

	public function getCommands(): array
	{
		if (empty($this->commands))
		{
			$this->commands = (new Command($this->getId()))->toRestFormat();
		}

		return $this->commands;
	}

	public function exists(): bool
	{
		return !empty($this->botData);
	}

	public function isNetworkBot(): bool
	{
		return $this->getType() === self::BOT_TYPE['TYPE_NETWORK'];
	}

	public function isSupport24Bot(): bool
	{
		return (
			$this->isNetworkBot()
			&& Loader::includeModule('imbot')
			&& (
				$this->getClass() === \Bitrix\ImBot\Bot\Support24::class
				|| $this->getClass() === \Bitrix\ImBot\Bot\Partner24::class
				|| $this->getClass() === \Bitrix\ImBot\Bot\SaleSupport24::class
			)
		);
	}

	private function getSavedCache(int $id): Cache
	{
		$cache = Application::getInstance()->getCache();

		$cacheDir = self::getCacheDir($id);
		$cache->initCache(self::CACHE_TTL, self::getCacheKey($id), $cacheDir);

		return $cache;
	}

	private function getBotDataFromDb(int $id): ?array
	{
		$query = BotTable::query()
			->setSelect(['*'])
			->setLimit(1)
			->where('BOT_ID', $id)
		;

		$result = $query->fetch();

		return $result ?: null;
	}

	private function saveInCache(Cache $cache, array $userData): void
	{
		$cache->startDataCache();
		$cache->endDataCache($userData);
	}

	private static function getCacheDir(int $id): string
	{
		$cacheSubDir = substr(md5(self::getCacheKey($id)),2,2);

		return self::CACHE_PATH . "{$cacheSubDir}/" . self::getCacheKey($id) . "/";
	}

	private static function getCacheKey($id): string
	{
		return self::CACHE_KEY . $id;
	}

	public static function cleanCache(int $id): void
	{
		unset(self::$staticCache[$id]);
		Application::getInstance()->getCache()->cleanDir(self::getCacheDir($id));
	}
}
