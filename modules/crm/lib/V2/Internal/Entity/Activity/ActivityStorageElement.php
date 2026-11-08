<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Activity;

use Bitrix\Main\ArgumentException;

/**
 * One file attached to an activity (a row of `b_crm_act_elem`).
 *
 * The storage the element lives in is not part of the model: it is one per activity and stays
 * a storage detail of the repository.
 */
final class ActivityStorageElement
{
	private readonly int $elementId;

	public function __construct(int $elementId)
	{
		if ($elementId <= 0)
		{
			throw new ArgumentException("Storage element ID must be positive, got: {$elementId}");
		}

		$this->elementId = $elementId;
	}

	public function getElementId(): int
	{
		return $this->elementId;
	}

	public function equals(self $other): bool
	{
		return $this->elementId === $other->elementId;
	}
}
