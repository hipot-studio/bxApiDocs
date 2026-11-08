<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\LegalRegistry;

use InvalidArgumentException;

final readonly class SubjectReference
{
	public const LEAD = 1;
	public const CONTACT = 3;

	/**
	 * Captures an original lead or contact without asserting trusted CRM resolution.
	 *
	 * @param int $entityTypeId
	 * @param int $entityId
	 * @throws InvalidArgumentException
	 */
	public function __construct(public int $entityTypeId, public int $entityId)
	{
		if (!in_array($entityTypeId, [self::LEAD, self::CONTACT], true) || $entityId <= 0 || $entityId > 2147483647)
		{
			throw new InvalidArgumentException('Invalid subject reference.');
		}
	}
}
