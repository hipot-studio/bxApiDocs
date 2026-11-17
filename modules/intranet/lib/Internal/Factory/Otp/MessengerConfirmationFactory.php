<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Factory\Otp;

use Bitrix\Intranet\Internal\Integration\Im\Otp\MessengerConfirmationCard;
use Bitrix\Intranet\Internal\Integration\Pull\Otp\MessengerConfirmationPublisher;
use Bitrix\Intranet\Internal\Integration\Security\MessengerConfirmationAudit;
use Bitrix\Intranet\Internal\Integration\Security\SecondFactorCodeProvider;
use Bitrix\Intranet\Internal\Repository\Otp\MessengerConfirmationStateRepository;
use Bitrix\Intranet\Internal\Service\Otp\MessengerConfirmationAvailabilityPolicy;
use Bitrix\Intranet\Internal\Service\Otp\MessengerConfirmationCoordinator;
use Bitrix\Intranet\Internal\Service\Otp\MessengerConfirmationLimits;
use Bitrix\Intranet\Internal\Service\Otp\MessengerConfirmationLock;

final class MessengerConfirmationFactory
{
	public static function create(): MessengerConfirmationCoordinator
	{
		return new MessengerConfirmationCoordinator(
			repository: new MessengerConfirmationStateRepository(),
			lock: new MessengerConfirmationLock(),
			windowSeconds: MessengerConfirmationCoordinator::readWindowSeconds(),
			pollAfterSeconds: MessengerConfirmationCoordinator::readPollAfterSeconds(),
			availability: new MessengerConfirmationAvailabilityPolicy(),
			limits: new MessengerConfirmationLimits(),
			audit: new MessengerConfirmationAudit(),
			codeProvider: new SecondFactorCodeProvider(),
			publisher: new MessengerConfirmationPublisher(),
			card: new MessengerConfirmationCard(),
		);
	}
}
