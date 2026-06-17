<?php

namespace Bitrix\Mail\Grid\MailboxSettingsGrid\Row\Assembler\Field\JsFields;

use Bitrix\Main\Localization\Loc;

class EmailWithCounterFieldAssembler extends JsExtensionFieldAssembler
{
	private const EXTENSION_CLASS_NAME = 'EmailWithCounterField';

	/**
	 * @param array $rawValue Raw row data with IS_CONNECTION_REQUEST flag.
	 * @return string HTML with placeholder text.
	 */
	protected function prepareConnectionRequestPlaceholder(array $rawValue): string
	{
		$text = Loc::getMessage('MAIL_MAILBOX_LIST_CONNECTION_REQUEST_PENDING');

		$html = '<div class="mail-mailbox-list-connection-request-email-placeholder">
					<div class="mail__mailbox-list_mailbox-connection-request_placeholder_bordered">
						<i class="ui-icon-set --o-hourglass lalala"></i>
					</div>
					<div class="mail-mailbox-list-connection-request-email-placeholder_text">
						%s
					</div>
				</div>'
		;

		return sprintf($html, htmlspecialcharsbx($text),
		);
	}

	/**
	 * @return array{
	 *     email: string,
	 *     serviceName: string,
	 *     count: int,
	 *     isOverLimit: bool,
	 *     counterHintText: string,
	 * }
	 */
	protected function getRenderParams(array $rawValue): array
	{
		$email = $rawValue['EMAIL'] ?? '';
		$serviceName = $rawValue['SERVICE_NAME'] ?? '';
		$count = $rawValue['COUNTERS']['EMAIL']['value'] ?? 0;
		$isOverLimit = $rawValue['COUNTERS']['EMAIL']['isOverLimit'] ?? false;

		return [
			'email' => $email,
			'serviceName' => $serviceName,
			'count' => (int)$count,
			'isOverLimit' => $isOverLimit,
			'counterHintText' => Loc::getMessage('MAIL_MAILBOX_LIST_ROW_FIELDS_COUNTER_HINT'),
		];
	}

	protected function getExtensionClassName(): string
	{
		return self::EXTENSION_CLASS_NAME;
	}

	protected function prepareColumnForExport(array $data): string
	{
		return $data['EMAIL'] ?? '';
	}
}
