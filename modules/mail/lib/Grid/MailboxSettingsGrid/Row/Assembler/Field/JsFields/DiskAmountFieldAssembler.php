<?php

namespace Bitrix\Mail\Grid\MailboxSettingsGrid\Row\Assembler\Field\JsFields;

class DiskAmountFieldAssembler extends JsExtensionFieldAssembler
{
	private const EXTENSION_CLASS_NAME = 'DiskAmountField';

	/**
	 * @return array{diskAmount: string}
	 */
	protected function getRenderParams(array $rawValue): array
	{
		return [
			'diskAmount' => $rawValue['VOLUME_MB'] ?? '',
		];
	}

	protected function getExtensionClassName(): string
	{
		return self::EXTENSION_CLASS_NAME;
	}

	protected function prepareColumnForExport(array $data): string
	{
		return $data['VOLUME_MB'] ?? '';
	}
}
