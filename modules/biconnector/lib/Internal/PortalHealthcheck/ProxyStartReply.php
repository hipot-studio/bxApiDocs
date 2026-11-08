<?php

namespace Bitrix\BIConnector\Internal\PortalHealthcheck;

final class ProxyStartReply
{
	public function __construct(
		public readonly int $httpStatus,
		public readonly ?string $status = null,
		public readonly ?string $errorCode = null,
		public readonly ?string $diagnosticId = null,
		public readonly ?array $proxy = null,
		public readonly ?array $nodesCheck = null,
		public readonly ?\DateTimeImmutable $nextAllowedAt = null,
		public readonly ?int $retryAfter = null,
		public readonly ?\DateTimeImmutable $resultDeadline = null,
		public readonly bool $requestNotSent = false,
	)
	{
	}
}
