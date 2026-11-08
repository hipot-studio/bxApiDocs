<?php

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Row\Assembler\Field\JsFields;

use Bitrix\UI\Format\BBCode\Converter;

class AgentInfoFieldAssembler extends JsExtensionFieldAssembler
{
	protected function getExtensionClassName(): string
	{
		return 'AgentInfoField';
	}

	protected function getRenderParams($rawValue): array
	{
		// The field renders the description as text, so the stored BBCode is flattened here
		return [
			'name' => $rawValue['NAME'] ?? '',
			'description' => Converter::toPlainText((string)($rawValue['DESCRIPTION'] ?? '')),
		];
	}

	protected function prepareColumnForExport($data): string
	{
		return $data['NAME'] ?? '';
	}
}