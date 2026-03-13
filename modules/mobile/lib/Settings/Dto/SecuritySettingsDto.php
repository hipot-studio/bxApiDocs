<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Settings\Dto;

use Bitrix\Mobile\Dto\Dto;

final class SecuritySettingsDto extends Dto
{
	public bool $isTakeScreenshotDisabled;
	public bool $isCopyTextDisabled;
	public bool $isOtpEnabled;
	public bool $isOtpEnabledForUser;
	public bool $isOtpMandatory;
	public bool $isHighPushOtpPromote;
	public RightsDto $takeScreenshotRights;
	public RightsDto $copyTextRights;
}