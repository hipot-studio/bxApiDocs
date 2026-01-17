<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\ExternalChat;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ExternalChat\Event\RegisterTypeEvent;
use Bitrix\Im\V2\Chat\Type;
use Bitrix\Main\EventResult;

class ExternalTypeRegistry
{
	/**
	 * @var array<string, Config>
	 */
	private array $configs = [];
	/**
	 * @var array<string, Type>
	 */
	private array $registry = [];

	public function __construct()
	{
		$this->load();
	}

	/**
	 * @return Type[]
	 */
	public function getTypes(): array
	{
		return $this->registry;
	}

	public function getConfigs(): array
	{
		return $this->configs;
	}

	public function getConfigByExtendedType(string $type): ?Config
	{
		return $this->configs[$type] ?? null;
	}

	private function load(): void
	{
		$event = new RegisterTypeEvent();
		$event->send();

		foreach ($event->getResults() as $eventResult)
		{
			if ($eventResult->getType() === EventResult::ERROR)
			{
				continue;
			}

			$parameters = $eventResult->getParameters();
			if (!is_array($parameters))
			{
				continue;
			}

			$type = $parameters['type'] ?? null;
			$config = $parameters['config'] ?? new Config();

			if (!is_string($type) || !$type)
			{
				continue;
			}

			$this->registerType(new Type(Chat::IM_TYPE_EXTERNAL, $type, $type), $config);
		}
	}

	private function registerType(Type $type, Config $config): void
	{
		$this->registry[$type->extendedType] = $type;
		$this->configs[$type->extendedType] = $config;
	}
}
