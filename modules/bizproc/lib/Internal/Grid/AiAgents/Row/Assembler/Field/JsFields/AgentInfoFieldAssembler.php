<?php

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Row\Assembler\Field\JsFields;

class AgentInfoFieldAssembler extends JsExtensionFieldAssembler
{
	protected function getExtensionClassName(): string
	{
		return 'AgentInfoField';
	}

	protected function getRenderParams($rawValue): array
	{
		return [
			'name' => $rawValue['NAME'] ?? '',
			'description' => $rawValue['DESCRIPTION'] ?? '',
		];
	}

	protected function prepareColumnForExport($data): string
	{
		return $data['NAME'] ?? '';
	}
}