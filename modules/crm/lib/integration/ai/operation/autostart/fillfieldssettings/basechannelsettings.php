<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings;

use Bitrix\Crm\Integration\AI\AIManager;

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
}
