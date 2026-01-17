<?php

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Settings;

class AiAgentsSettings extends \Bitrix\Main\Grid\Settings
{
	private string $extensionName;
	private string $extensionLoadName;
	private ?array $filterFields = null;

	public function __construct(array $params)
	{
		parent::__construct($params);

		$this->extensionName = $params['extensionName'] ?? 'Bizproc.Ai.Agents';
		$this->extensionLoadName = $params['extensionLoadName'] ?? 'bizproc.grid.ai-agents';
	}


	public function getExtensionName(): string
	{
		return $this->extensionName;
	}

	public function getExtensionLoadName(): string
	{
		return $this->extensionLoadName;
	}

	public function getFilterFields(): ?array
	{
		return $this->filterFields;
	}

	public function setFilterFields(array $filterFields): void
	{
		$this->filterFields = $filterFields;
	}
}