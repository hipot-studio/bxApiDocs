<?php

namespace Bitrix\Intranet\User\Grid\Row\Assembler\Field\JsFields;

class ConnectFieldAssembler extends JsExtensionFieldAssembler
{
	protected function getExtensionClassName(): string
	{
		return 'ConnectField';
	}

	protected function getRenderParams($rawValue): array
	{
		return [
			'userId' => $rawValue['ID'],
		];
	}

	protected function prepareColumnForExport($data): string
	{
		return '';
	}
}