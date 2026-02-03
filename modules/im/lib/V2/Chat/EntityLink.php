<?php

namespace Bitrix\Im\V2\Chat;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\EntityLink\EntityLinkDto;
use Bitrix\Im\V2\Chat\EntityLink\EntityLinkFactory;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Rest\RestConvertible;
use Bitrix\Main\Application;

class EntityLink implements RestConvertible
{
	use ContextCustomer;

	private const CACHE_TTL = 18144000;

	protected int $chatId;
	protected string $entityId = '';
	protected string $type = '';
	protected ?string $url = null;

	public function __construct(EntityLinkDto $entityLinkDto)
	{
		$this->type = $entityLinkDto->type;
		$this->chatId = $entityLinkDto->chatId;
		$this->entityId = $entityLinkDto->entityId;
		$this->url = $entityLinkDto->url;
	}

	public function getEntityId(): string
	{
		return $this->entityId;
	}

	public static function getInstance(Chat $chat): self
	{
		$factory = EntityLinkFactory::getInstance();
		$instance = $factory->create($chat);
		$instance->fillUrl();

		return $instance;
	}

	protected function fillUrl(): void
	{
		if (isset($this->url))
		{
			return;
		}

		$this->url = $this->getUrl();
	}

	protected function fillUrlWithCache(): void
	{
		$cache = Application::getInstance()->getCache();
		if ($cache->initCache(self::CACHE_TTL, $this->getCacheId(), $this->getCacheDir()))
		{
			$cachedEntityUrl = $cache->getVars();

			if (!is_array($cachedEntityUrl))
			{
				$cachedEntityUrl = [];
			}

			$this->url = $cachedEntityUrl['url'] ?? '';
			return;
		}

		$this->url = $this->getUrl();
		$cache->startDataCache();
		$cache->endDataCache(['url' => $this->url]);
	}

	public static function cleanCache(int $chatId): void
	{
		Application::getInstance()->getCache()->cleanDir(static::getCacheDirByChatId($chatId));
	}

	private function getCacheDir(): string
	{
		return static::getCacheDirByChatId($this->chatId);
	}

	private static function getCacheDirByChatId(int $chatId): string
	{
		$cacheSubDir = $chatId % 100;

		return "/bx/imc/chatentitylink/1/{$cacheSubDir}/{$chatId}";
	}

	private function getCacheId(): string
	{
		return "chat_entity_link_{$this->chatId}";
	}

	protected function getUrl(): string
	{
		return '';
	}

	protected function getRestType(): string
	{
		return $this->type;
	}

	public static function getRestEntityName(): string
	{
		return 'entityLink';
	}

	public function toRestFormat(array $option = []): array
	{
		return [
			'type' => $this->getRestType(),
			'url' => $this->url,
			'id' => $this->entityId,
		];
	}

	/**
	 * @deprecated
	 * @see \Bitrix\Im\V2\Chat\EntityLink::toRestFormat
	 */
	public function toArray(array $options = []): array
	{
		return [
			'TYPE' => $this->getRestType(),
			'URL' => $this->url,
			'ID' => $this->entityId,
		];
	}
}
