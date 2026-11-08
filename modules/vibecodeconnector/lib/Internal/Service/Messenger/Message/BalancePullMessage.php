<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Messenger\Message;

use Bitrix\Main\Messenger\Entity\AbstractMessage;

final class BalancePullMessage extends AbstractMessage
{
	public function __construct(
		public string $pairingIss,
		public string $endpointUrl,
		public int $requestedVersion = 0,
		public int $attemptCount = 0,
	)
	{
	}
}
