<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Service;

final class DigestSetupAvailability
{
	public const READY = 'ready';
	public const SUBSCRIPTION_REQUIRED = 'subscription_required';
	public const HIDDEN = 'hidden';

	public function __construct(
		public readonly string $state,
		public readonly ?string $promoterCode = null,
	)
	{
	}

	public function toArray(): array
	{
		$result = ['state' => $this->state];
		if ($this->promoterCode !== null)
		{
			$result['promoterCode'] = $this->promoterCode;
		}

		return $result;
	}
}
