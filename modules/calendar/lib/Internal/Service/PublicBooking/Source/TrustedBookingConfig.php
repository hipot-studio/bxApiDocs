<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Service\PublicBooking\Source;

/**
 * Immutable value object holding server-side resolved booking configuration.
 * Contains only data that originates from trusted server storage, never from the client.
 */
final class TrustedBookingConfig
{
	// $userIds / $resourceIds: allowlisted from server-side settings, never from client.
	// $entityFieldName: CRM entity field name for timezone resolution; may be null.
	public function __construct(
		private readonly array $userIds,
		private readonly array $resourceIds,
		private readonly ?string $entityFieldName,
	)
	{
	}

	public function getUserIds(): array
	{
		return $this->userIds;
	}

	public function getResourceIds(): array
	{
		return $this->resourceIds;
	}

	public function getEntityFieldName(): ?string
	{
		return $this->entityFieldName;
	}
}
