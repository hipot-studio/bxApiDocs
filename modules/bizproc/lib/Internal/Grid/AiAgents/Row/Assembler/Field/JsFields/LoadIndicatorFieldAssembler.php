<?php

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Row\Assembler\Field\JsFields;

class LoadIndicatorFieldAssembler extends JsExtensionFieldAssembler
{
	protected function getExtensionClassName(): string
	{
		return 'LoadIndicatorField';
	}

	protected function getRenderParams($rawValue): array
	{
		return [
			'percentage' => $rawValue['LOAD_PERCENTAGE'] ?? null,
		];
	}

	protected function prepareColumnForExport($data): string
	{
		$percentage = $data['LOAD_PERCENTAGE'] ?? null;

		return $percentage ? "{$percentage}%" : '';
	}
}