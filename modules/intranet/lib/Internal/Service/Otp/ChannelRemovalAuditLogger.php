<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Public\Type\Otp\OtpChannel;

final class ChannelRemovalAuditLogger
{
	public const AUDIT_TYPE_ID = 'INTRANET_OTP_CHANNEL_REMOVED';
	private const MODULE_ID = 'intranet';

	public function logChannelRemoved(User $user, OtpChannel $channel): bool
	{
		$userId = $user->getId();
		if ($userId === null)
		{
			return false;
		}

		$recordId = \CEventLog::Log(
			\CEventLog::SEVERITY_SECURITY,
			self::AUDIT_TYPE_ID,
			self::MODULE_ID,
			$userId,
			[
				'initiatorId' => $userId,
				'channel' => $channel->value,
			],
		);

		return !empty($recordId);
	}
}
