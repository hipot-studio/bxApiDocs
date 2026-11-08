<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\SignatureEditorConfig;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class CMailClientVacationConfigComponent extends CBitrixComponent
{
	private const EDITOR_PREFIX = 'vacation';
	private const CONTAINER_ID = 'mail-vacation-auto-reply-form';

	public function executeComponent()
	{
		global $APPLICATION;

		if (!Loader::includeModule('mail'))
		{
			ShowError(Loc::getMessage('MAIL_VACATION_CONFIG_MODULE_ERROR'));

			return;
		}

		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			$APPLICATION->authForm('');

			return;
		}

		if (!Feature::isVacationAutoReplyAvailable())
		{
			ShowError(Loc::getMessage('MAIL_VACATION_CONFIG_DISABLED'));

			return;
		}

		// CHTMLEditor of the text lives in fileman
		Loader::includeModule('fileman');

		$editorConfig = SignatureEditorConfig::getHtmlEditorConfig(
			self::EDITOR_PREFIX,
			'',
			includeImageControl: Feature::isSignatureImagesAvailable(),
		);

		$this->arResult['TITLE'] = Loc::getMessage('MAIL_VACATION_CONFIG_TITLE');
		$this->arResult['EDITOR_CONFIG'] = $editorConfig;

		$this->arResult['SCREEN_PARAMS'] = [
			'containerId' => self::CONTAINER_ID,
			'mailboxId' => $this->resolveMailboxId($userId),
			'timezoneId' => $this->resolveTimezoneId(),
			'editorId' => self::editorInstanceId((string)$editorConfig['id']),
			'mailboxListPath' => (string)($this->arParams['PATH_TO_MAIL_MBX_LIST'] ?? ''),
		];

		$APPLICATION->setTitle($this->arResult['TITLE']);

		$this->includeComponentTemplate();
	}

	/**
	 * The mailbox the screen opens on. The one last looked at is only a preselection; when it is gone
	 * or was never remembered, the first mailbox available to the user takes its place, so that null
	 * means what the screen says about it - the user has no mailbox to set an auto-reply of.
	 *
	 * The choice is made here and not on the screen: the client is given the mailboxes by the setting
	 * request and picks none of them on its own.
	 */
	private function resolveMailboxId(int $userId): ?int
	{
		// the list is keyed by the identifier of the mailbox
		$accessibleMailboxes = MailboxTable::getUserMailboxes($userId, true);
		if ($accessibleMailboxes === [])
		{
			return null;
		}

		$mailboxId = (int)CUserOptions::GetOption('mail', 'previous_seen_mailbox_id', 0, $userId);

		return isset($accessibleMailboxes[$mailboxId]) ? $mailboxId : (int)array_key_first($accessibleMailboxes);
	}

	/**
	 * Timezone the period is shown and sent in. Resolved the same way the setting request resolves it,
	 * so the screen and the stored bounds speak of the same wall clock time.
	 */
	private function resolveTimezoneId(): string
	{
		global $USER;

		$timezoneId = '';
		if (is_object($USER))
		{
			$timezoneId = (string)$USER->GetParam('TIME_ZONE');
			if ($timezoneId === '' && CTimeZone::IsAutoTimeZone($USER->GetParam('AUTO_TIME_ZONE')))
			{
				$timezoneId = (string)(CTimeZone::getTzCookie() ?? '');
			}
		}

		return self::isKnownTimezone($timezoneId) ? $timezoneId : date_default_timezone_get();
	}

	private static function isKnownTimezone(string $timezoneId): bool
	{
		if ($timezoneId === '')
		{
			return false;
		}

		try
		{
			new DateTimeZone($timezoneId);
		}
		catch (Exception)
		{
			return false;
		}

		return true;
	}

	/**
	 * CHTMLEditor drops everything outside [a-zA-Z0-9_:.] from the id it registers itself under,
	 * so the screen needs the stripped one to reach the editor instance.
	 */
	private static function editorInstanceId(string $configId): string
	{
		return (string)preg_replace('/[^a-zA-Z0-9_:.]/', '', $configId);
	}
}
