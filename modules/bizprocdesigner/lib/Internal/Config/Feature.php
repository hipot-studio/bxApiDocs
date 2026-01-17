<?php

namespace Bitrix\BizprocDesigner\Internal\Config;

use Bitrix\Main;
use Bitrix\BizprocDesigner\Internal\Trait\SingletonTrait;

class Feature
{
	use SingletonTrait;

	private const MODULE_NAME = 'bizprocdesigner';

	public function isAiAssistantAvailable(): bool
	{
		return $this->getOptionValue('ai_assistant_available', 'N') === 'Y';
	}

	public function areComplexNodeConnectionsAvailable(): bool
	{
		return $this->getOptionValue('complex_node_connections_available', 'N') === 'Y';
	}

	private function getOptionValue(string $option, mixed $defaultValue)
	{
		return Main\Config\Option::get(self::MODULE_NAME, $option, $defaultValue);
	}

	/**
	 * @return list<string>
	 */
	public function getAvailableFeatureCodes(): array
	{
		$featureCodes = [];
		if ($this->isAiAssistantAvailable())
		{
			$featureCodes[] = 'aiAssistant';
		}

		if ($this->areComplexNodeConnectionsAvailable())
		{
			$featureCodes[] = 'complexNodeConnections';
		}

		return $featureCodes;
	}
}