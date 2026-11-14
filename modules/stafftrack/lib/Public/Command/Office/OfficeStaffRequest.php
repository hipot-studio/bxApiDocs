<?php

namespace Bitrix\StaffTrack\Public\Command\Office;

/**
 * The staff a new office is created with, as the caller confirmed it after the preview.
 */
final class OfficeStaffRequest
{
	/**
	 * @param array $entities codes of the entity selector
	 * @param int[] $excludedUserIds people taken off the list of movers: they stay in their offices
	 * @param string $movesFingerprint the fingerprint of the moves last shown to the caller
	 */
	public function __construct(
		public readonly array $entities = [],
		public readonly array $excludedUserIds = [],
		public readonly string $movesFingerprint = '',
	) {}

	public function isEmpty(): bool
	{
		return $this->entities === [];
	}
}
