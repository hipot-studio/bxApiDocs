<?php

namespace Bitrix\MailMobile\Provider;

use Bitrix\Mail\Public\Service\LargeAttachment\LargeAttachmentAvailability;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

/**
 * Publishes the tariff restriction of the large attachment scenario into the standard mobile channel, so
 * that the application knows the answer before it tries to convert anything.
 */
final class TariffPlanRestrictionProvider
{
	/**
	 * The name of the tariff feature of the mail module. It is private there, so the name is restated here;
	 * the answer itself is never computed here and always comes from the mail module.
	 */
	private const FEATURE_CODE = 'mail_large_attachment_disk_upload';

	/**
	 * Handler for mobile event onTariffRestrictionsCollect
	 */
	public static function getTariffPlanRestrictions(): EventResult
	{
		return new EventResult(
			EventResult::SUCCESS,
			[
				'restrictions' => [
					self::FEATURE_CODE => [
						'code' => self::FEATURE_CODE,
						'title' => Loc::getMessage('MAILMOBILE_TARIFF_PLAN_RESTRICTION_LARGE_ATTACHMENT'),
						'isRestricted' => !self::isAutoUploadEnabled(),
						'isPromo' => false,
					],
				],
			],
			'mailmobile',
		);
	}

	/**
	 * A mail module released before the scenario knows nothing about the feature. There the restriction
	 * reads as imposed, which costs nothing: the form asks about it only while the scenario is switched on,
	 * and it cannot be switched on without that very mail module.
	 */
	private static function isAutoUploadEnabled(): bool
	{
		return Loader::includeModule('mail')
			&& class_exists(LargeAttachmentAvailability::class)
			&& LargeAttachmentAvailability::isAutoUploadAllowedByTariff()
		;
	}
}
