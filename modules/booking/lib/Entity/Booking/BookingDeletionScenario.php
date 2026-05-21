<?php

declare(strict_types=1);

namespace Bitrix\Booking\Entity\Booking;

use Bitrix\Booking\Internals\Service\DictionaryTrait;

enum BookingDeletionScenario: string
{
	use DictionaryTrait;

	case Manager = 'manager';
	case ClientWeb = 'client_web';
	case ClientMcpTool = 'client_mcp_tool';
	case ClientYandex = 'client_yandex';

	public static function isClientDeletion(self|null $type = null): bool
	{
		return in_array(
			$type,
			[
				self::ClientWeb,
				self::ClientMcpTool,
				self::ClientYandex,
			],
			true,
		);
	}
}
