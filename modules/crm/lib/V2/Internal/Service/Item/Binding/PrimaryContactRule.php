<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\Binding;

use Bitrix\Main\InvalidOperationException;

/**
 * Decides which of the bound entities of an item is the primary one.
 *
 * The name says contact because that is the pair the rule was extracted for, but it applies to any
 * bindings of this shape and is reached from all of them: its only caller is
 * {@see \Bitrix\Crm\Item\FieldImplementation\Binding}, which every factory of an item with bindings
 * builds - `Service\Factory\Deal`, `Lead`, `Quote`, `Company` and `Dynamic` for the contacts of an
 * item, and `Service\Factory\Contact` for the companies of a contact. A change made here for contacts
 * changes the choice of the primary company of a contact just as much.
 *
 * The rule works on plain data, so that it stays independent of the storage the bindings come from.
 *
 * @internal
 */
final class PrimaryContactRule
{
	/**
	 * @param array<int, bool> $currentPrimaryFlags ordered map "bound entity id => is it primary right now";
	 *                                              the order is the order the bindings are stored in
	 * @param int|null $requestedPrimaryBoundEntityId id explicitly marked as primary in the written bindings,
	 *                                                null when none of them is marked
	 *
	 * @throws InvalidOperationException when there is nothing to make primary
	 */
	public function selectPrimaryBoundEntityId(array $currentPrimaryFlags, ?int $requestedPrimaryBoundEntityId): int
	{
		// an explicitly requested primary wins, even over a binding that is not among the current ones
		if ($requestedPrimaryBoundEntityId > 0)
		{
			return $requestedPrimaryBoundEntityId;
		}

		$firstBoundEntityId = null;
		$primaryBoundEntityId = null;
		// let the current primary binding to remain primary
		foreach ($currentPrimaryFlags as $boundEntityId => $isPrimary)
		{
			$firstBoundEntityId ??= $boundEntityId;

			if ($isPrimary)
			{
				$primaryBoundEntityId = $boundEntityId;

				break;
			}
		}

		if (!($primaryBoundEntityId > 0))
		{
			// if no primary found, simply make the first binding primary
			$primaryBoundEntityId = $firstBoundEntityId;
		}

		if (!($primaryBoundEntityId > 0))
		{
			throw new InvalidOperationException(
				'Could not select a primary bound entity, which should be an impossible case',
			);
		}

		return $primaryBoundEntityId;
	}
}
