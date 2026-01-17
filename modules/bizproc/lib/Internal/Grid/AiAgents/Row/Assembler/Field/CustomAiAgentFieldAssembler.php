<?php

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Row\Assembler\Field;

use Bitrix\Bizproc\Internal\Grid\AiAgents\Settings\AiAgentsSettings;
use Bitrix\Main\Grid\Row\FieldAssembler;

/**
 * @method AiAgentsSettings getSettings()
 */
abstract class CustomAiAgentFieldAssembler extends FieldAssembler
{
	protected function prepareRow(array $row): array
	{
		if (empty($this->getColumnIds()))
		{
			return $row;
		}

		$row['columns'] ??= [];

		foreach ($this->getColumnIds() as $columnId)
		{
			$row['columns'][$columnId] = $this->prepareColumn($row['data']);
		}

		return $row;
	}
}