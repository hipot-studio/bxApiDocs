<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Intranet\UserConsent\Access;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

final class WebFormConsentBySubmit extends AbstractCrmFlag
{
	private static ?bool $isIntranetReady = null;

	public function getName(): string
	{
		return (string)Loc::getMessage('CRM_FEATURE_WEB_FORM_CONSENT_BY_SUBMIT_NAME');
	}

	public function getRequirements(): array
	{
		// The method ships with the guard on direct access to the consent journal.
		return [static function (): bool {
			return self::$isIntranetReady ??= Loader::includeModule('intranet')
				&& method_exists(Access::class, 'canView');
		}];
	}
}
