<?php

namespace Bitrix\Mail\Grid\MailboxSettingsGrid\Row\Assembler\Field\JsFields;

class MailboxNameFieldAssembler extends JsExtensionFieldAssembler
{
	private const EXTENSION_CLASS_NAME = 'MailboxNameField';

	/**
	 * @return array{mailboxName: string}
	 */
	protected function getRenderParams(array $rawValue): array
	{
		return [
			'mailboxName' => $rawValue['MAILBOX_NAME'] ?? '',
		];
	}

	protected function getExtensionClassName(): string
	{
		return self::EXTENSION_CLASS_NAME;
	}

	protected function prepareColumnForExport(array $data): string
	{
		return $data['MAILBOX_NAME'] ?? '';
	}
}
