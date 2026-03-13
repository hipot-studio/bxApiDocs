<?php

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Filter;

use Bitrix\Main\Filter\Settings;

class AiAgentsFilterSettings extends Settings
{
	public const LAUNCHED_BY_FIELD = 'LAUNCHED_BY';

	protected array $filterAvailability = [];
	protected array $whiteList = [];

	public function __construct(array $params)
	{
		parent::__construct($params);
		$this->initFilterAvailability();

		$this->whiteList = isset($params['WHITE_LIST']) && is_array($params['WHITE_LIST'])
			? $params['WHITE_LIST']
			: []
		;
	}

	public function getFilterAvailability(): array
	{
		return $this->filterAvailability;
	}

	public function isFilterAvailable(string $filterField): bool
	{
		return $this->getFilterAvailability()[$filterField] ?? true;
	}

	public function getWhiteList(): array
	{
		return $this->whiteList;
	}

	private function initFilterAvailability(): void
	{
		$this->filterAvailability = [
			self::LAUNCHED_BY_FIELD => true,
		];
	}
}
