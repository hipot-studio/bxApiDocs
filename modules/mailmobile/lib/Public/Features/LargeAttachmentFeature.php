<?php

namespace Bitrix\MailMobile\Public\Features;

use Bitrix\Main\Config\Option;
use Bitrix\Mobile\Config\FeatureFlag;

/**
 * Rollout of the large attachment scenario in mobile mail. It belongs to this module alone: rolling the
 * mobile part back must not switch off the web one, which has its own option in the mail module.
 *
 * The flag says whether the scenario is on, not whether the portal is allowed to use it. The tariff is
 * decided by the mail module and stays a separate answer.
 */
final class LargeAttachmentFeature extends FeatureFlag
{
	private const OPTION_NAME = 'large_attachment_enabled';

	/**
	 * The user option that marks the explanation about the space the Disk spends as shown. The scenario the
	 * explanation belongs to exists on mobile only, so the mail module has no name for it; the category is
	 * the one the rest of the compose form guides live in.
	 */
	public const USER_OPTION_STORAGE_NOTICE_NAME = 'large_attachment_mobile_storage_notice_shown';

	public function isEnabled(): bool
	{
		return Option::get('mailmobile', self::OPTION_NAME, 'Y') === 'Y';
	}

	public function enable(): void
	{
		Option::set('mailmobile', self::OPTION_NAME, 'Y');
	}

	public function disable(): void
	{
		Option::set('mailmobile', self::OPTION_NAME, 'N');
	}
}
