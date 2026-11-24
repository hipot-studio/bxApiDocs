<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Public\Provider;

use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Integration\Bitrix24\License;
use Bitrix\Mobile\Internal\Integration\Main\EarliestUserRegistration;

/**
 * What the portal states about itself, for the layers outside Internal.
 */
final class PortalProvider
{
	public function __construct(
		private readonly License $license = new License(),
		private readonly EarliestUserRegistration $earliestUserRegistration = new EarliestUserRegistration(),
	) {}

	public function isCloud(): bool
	{
		return $this->license->isCloud();
	}

	/**
	 * The moment the portal was created, to the second; null outside the cloud and when the portal
	 * states none.
	 */
	public function getCreatedAt(): ?DateTime
	{
		return $this->license->getCreatedAt();
	}

	/**
	 * The moment the earliest user of the portal registered, to the second, in both contours; null while
	 * the portal has no users.
	 */
	public function getEarliestUserRegisteredAt(): ?DateTime
	{
		return $this->earliestUserRegistration->get();
	}
}
