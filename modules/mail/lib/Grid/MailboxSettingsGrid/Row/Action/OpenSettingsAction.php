<?php

namespace Bitrix\Mail\Grid\MailboxSettingsGrid\Row\Action;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class OpenSettingsAction extends JsGridAction
{
	public static function getId(): ?string
	{
		return 'open_settings';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('MAIL_MAILBOX_LIST_ROW_ACTIONS_OPEN_SETTINGS') ?? '';
	}

	public function getActionId(): string
	{
		return 'openSettingsAction';
	}

	protected function getActionParams(array $rawFields): array
	{
		return [
			'mailboxId' => (int)$rawFields['ID'],
		];
	}

	public function isEnabled(array $rawFields): bool
	{
		return (bool)($rawFields['CAN_EDIT'] ?? false);
	}

	public function getControl(array $rawFields): ?array
	{
		if (!($rawFields['CAN_EDIT'] ?? false))
		{
			return null;
		}

		return parent::getControl($rawFields);
	}
}
