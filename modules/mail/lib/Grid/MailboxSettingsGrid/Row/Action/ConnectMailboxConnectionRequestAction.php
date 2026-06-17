<?php

namespace Bitrix\Mail\Grid\MailboxSettingsGrid\Row\Action;

use Bitrix\Main;
use Bitrix\Main\Localization\Loc;

class ConnectMailboxConnectionRequestAction extends JsGridAction
{
	public static function getId(): ?string
	{
		return 'connect_mailbox_connection_request';
	}

	public function processRequest(Main\HttpRequest $request): ?Main\Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('MAIL_MAILBOX_LIST_ROW_ACTIONS_CONNECT_MAILBOX_CONNECTION_REQUEST') ?? '';
	}

	public function getActionId(): string
	{
		return 'connectMailboxConnectionRequestAction';
	}

	protected function getActionParams(array $rawFields): array
	{
		return [
			'requestId' => (int)($rawFields['REQUEST_ID'] ?? 0),
			'requesterId' => (int)($rawFields['REQUESTER_ID'] ?? 0),
		];
	}

	public function isEnabled(array $rawFields): bool
	{
		return !empty($rawFields['IS_CONNECTION_REQUEST']);
	}

	public function getControl(array $rawFields): ?array
	{
		if (empty($rawFields['IS_CONNECTION_REQUEST']))
		{
			return null;
		}

		return parent::getControl($rawFields);
	}
}
