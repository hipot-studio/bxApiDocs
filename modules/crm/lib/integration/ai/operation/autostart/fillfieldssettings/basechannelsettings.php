<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Main\Application;

abstract class BaseChannelSettings implements ChannelSettingsInterface
{
	protected array $operationTypes = [];

	public function __construct(array $operationTypes)
	{
		$this->operationTypes = $operationTypes;
	}

	public function getOperationTypes(): array
	{
		return $this->operationTypes;
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}

	protected function validateOperationTypes(array $types): array
	{
		$validTypes = AIManager::getAllOperationTypes();

		return array_filter(
			array_map('intval', $types),
			static fn(int $type) => in_array($type, $validTypes, true)
		);
	}

	final protected static function isRuZone(): bool
	{
		static $zone = null;
		if ($zone === null)
		{
			$zone = Application::getInstance()->getLicense()->getRegion() ?? 'ru';
		}

		return $zone === 'ru';
	}
}
