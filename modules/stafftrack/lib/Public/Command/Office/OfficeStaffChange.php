<?php

namespace Bitrix\StaffTrack\Public\Command\Office;

use Bitrix\StaffTrack\Internal\Integration\HumanResources\IdNormalizer;

/**
 * A change of the staff of an existing office, as the caller confirmed it after the preview: whom to add,
 * whom to take off, and which movers stay where they are. Whoever it does not name is left as they are.
 */
final class OfficeStaffChange
{
	/**
	 * @param array $entities codes of the entity selector: the people and departments to add
	 * @param int[] $removedUserIds people to take off this office
	 * @param int[] $excludedUserIds movers taken off the list of the added: they stay in their offices
	 * @param string $movesFingerprint the fingerprint of the moves last shown to the caller
	 */
	public function __construct(
		public readonly array $entities = [],
		public readonly array $removedUserIds = [],
		public readonly array $excludedUserIds = [],
		public readonly string $movesFingerprint = '',
	) {}

	/**
	 * The excluded alone change nothing: they only take movers off what the codes add.
	 */
	public function isEmpty(): bool
	{
		return $this->entities === [] && $this->removedUserIds === [];
	}

	/**
	 * The change folded into one string that does not depend on the order of its parts; empty for an empty
	 * change. The fingerprint of the moves is not a part of it: it describes the state the change met.
	 */
	public function fingerprint(): string
	{
		if ($this->isEmpty())
		{
			return '';
		}

		$codes = array_values(array_unique(array_map($this->describeCode(...), $this->entities)));
		sort($codes, SORT_STRING);

		return sha1(serialize([
			$codes,
			$this->sortIds($this->removedUserIds),
			$this->sortIds($this->excludedUserIds),
		]));
	}

	private function describeCode(mixed $entity): string
	{
		$type = is_array($entity) ? ($entity['type'] ?? '') : '';
		$id = is_array($entity) ? ($entity['id'] ?? '') : '';

		return (is_scalar($type) ? (string)$type : '') . ':' . (is_scalar($id) ? (string)$id : '');
	}

	/**
	 * @return int[]
	 */
	private function sortIds(array $ids): array
	{
		$ids = IdNormalizer::normalize($ids);
		sort($ids);

		return $ids;
	}
}
