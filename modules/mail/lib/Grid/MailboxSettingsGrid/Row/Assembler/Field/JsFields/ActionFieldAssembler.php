<?php

namespace Bitrix\Mail\Grid\MailboxSettingsGrid\Row\Assembler\Field\JsFields;

class ActionFieldAssembler extends JsExtensionFieldAssembler
{
	private const EXTENSION_CLASS_NAME = 'ActionField';

	/**
	 * @param array $rawValue Raw row data with connection request fields.
	 * @return string HTML rendered via parent JS extension with connection request params.
	 */
	protected function prepareConnectionRequestPlaceholder(array $rawValue): string
	{
		return parent::prepareColumn(array_merge($rawValue, [
			'RENDER_AS_JS' => true,
		]));
	}

	/**
	 * @param array $rawValue Raw row data (mailbox or connection request).
	 * @return array{
	 *     isConnectionRequest: true,
	 *     requestId: int,
	 *     requesterId: int,
	 * }
	 * |array{
	 *     url: string,
	 *     hasError: ?bool,
	 *     canEdit: bool,
	 * }
	 */
	protected function getRenderParams(array $rawValue): array
	{
		if (!empty($rawValue['IS_CONNECTION_REQUEST']))
		{
			return [
				'isConnectionRequest' => true,
				'requestId' => (int)($rawValue['REQUEST_ID'] ?? 0),
				'requesterId' => (int)($rawValue['REQUESTER_ID'] ?? 0),
			];
		}

		$mailboxId = (int)$rawValue['ID'];
		$url = sprintf("/mail/config/edit?id=%d", $mailboxId);

		return [
			'url' => $url,
			'hasError' => $rawValue['HAS_ERROR'] ?? null,
			'canEdit' => $rawValue['CAN_EDIT'] ?? false,
		];
	}

	protected function getExtensionClassName(): string
	{
		return self::EXTENSION_CLASS_NAME;
	}

	protected function prepareColumnForExport(array $data): string
	{
		$mailboxId = (int)$data['ID'];

		return sprintf("/mail/config/edit?id=%d", $mailboxId);
	}
}
