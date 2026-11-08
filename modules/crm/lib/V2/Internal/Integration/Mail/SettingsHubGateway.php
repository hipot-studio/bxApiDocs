<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Mail;

use Bitrix\Mail\Integration\Intranet\ToolShowSettings;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Uri;

class SettingsHubGateway
{
	public function isMailAvailable(): bool
	{
		return Loader::includeModule('mail');
	}

	public function isMailToolAvailable(): bool
	{
		return (new ToolShowSettings())->isMailAvailable();
	}

	public function getMailClientUrl(): Uri
	{
		return new Uri(Option::get('intranet', 'path_mail_client', SITE_DIR . 'mail/'));
	}

	public function getCrmMailboxId(): int
	{
		return (int)Option::get('crm', 'mail_box', 0);
	}

	public function mailboxExists(int $mailboxId): bool
	{
		return is_array(\CMailBox::GetById($mailboxId)->Fetch());
	}
}
