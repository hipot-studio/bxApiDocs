<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\LegalRegistry;

use InvalidArgumentException;

final readonly class SubmissionResolution
{
	/** @var list<SubjectReference> */
	public array $personBindings;

	/**
	 * Captures CRM outcome; the server adapter must independently verify the receipt.
	 *
	 * @param SubjectReference $originalSubject
	 * @param list<SubjectReference> $personBindings
	 * @param ?int $resultId
	 * @param ?int $activityId
	 * @param string $crmReceipt
	 * @throws InvalidArgumentException
	 */
	public function __construct(
		public SubjectReference $originalSubject,
		array $personBindings,
		public ?int $resultId,
		public ?int $activityId,
		public string $crmReceipt,
	)
	{
		if (trim($crmReceipt) === '' || !array_is_list($personBindings))
		{
			throw new InvalidArgumentException('Invalid submission resolution.');
		}
		foreach ([$resultId, $activityId] as $id)
		{
			if ($id !== null && ($id <= 0 || $id > 2147483647))
			{
				throw new InvalidArgumentException('Invalid CRM outcome identity.');
			}
		}
		$snapshot = [];
		$identities = [];
		foreach ($personBindings as $reference)
		{
			if (!$reference instanceof SubjectReference)
			{
				throw new InvalidArgumentException('Invalid person bindings.');
			}
			$key = $reference->entityTypeId . ':' . $reference->entityId;
			if (isset($identities[$key]))
			{
				throw new InvalidArgumentException('Invalid person bindings.');
			}
			$identities[$key] = true;
			$snapshot[] = $reference;
		}
		$this->personBindings = $snapshot;
	}
}
