<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\LegalRegistry;

use InvalidArgumentException;

final readonly class SubjectTransition
{
	/** @var list<SubjectReference> */
	public array $from;

	/** @var list<SubjectReference> */
	public array $to;

	/**
	 * Captures a confirmed lifecycle operation with a stable replay key.
	 *
	 * @param string $operationKey
	 * @param string $kind
	 * @param array $from
	 * @param list<SubjectReference> $to
	 * @throws InvalidArgumentException
	 */
	public function __construct(public string $operationKey, public string $kind, array $from, array $to)
	{
		if (
			$operationKey === '' || trim($operationKey) !== $operationKey
			|| preg_match('/[\x00-\x1f\x7f]/', $operationKey) === 1
			|| !in_array($kind, ['conversion', 'merge'], true)
		)
		{
			throw new InvalidArgumentException('Invalid subject transition.');
		}
		$this->from = self::snapshotReferences($from);
		$this->to = self::snapshotReferences($to);
	}

	private static function snapshotReferences(array $references): array
	{
		if ($references === [] || !array_is_list($references))
		{
			throw new InvalidArgumentException('Invalid transition subjects.');
		}
		$snapshot = [];
		$identities = [];
		foreach ($references as $reference)
		{
			if (!$reference instanceof SubjectReference)
			{
				throw new InvalidArgumentException('Invalid transition subjects.');
			}
			$key = $reference->entityTypeId . ':' . $reference->entityId;
			if (isset($identities[$key]))
			{
				throw new InvalidArgumentException('Invalid transition subjects.');
			}
			$identities[$key] = true;
			$snapshot[] = $reference;
		}

		return $snapshot;
	}
}
