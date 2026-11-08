<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Access\Table;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Service\TableGroupMembershipService;
use Bitrix\BIConnector\Configuration\Feature;

/**
 * The single answer to "which tables of the catalog does this person see".
 *
 * The answer is three-valued on purpose. Null means the axis is not applied on this portal at all and
 * nothing is to be filtered; a list means exactly those codes are allowed; an empty list means nothing
 * is visible. Merging the last two is the one mistake that opens the data, so they are kept apart.
 *
 * The answer belongs to the user it was computed for and is kept on the object, never in a static
 * field: a single hit serving two people would otherwise answer the second one by the first.
 */
final class TableVisibilityService
{
	private static int $generation = 0;

	private ?int $userId;

	private ?AccessController $accessController;

	/** @var string[]|null */
	private ?array $allowedTableCodes = null;

	/** @var array<string, true>|null Index of the answer: the section asks about every table of the catalog. */
	private ?array $allowedTableCodeIndex = null;

	private bool $isComputed = false;

	private int $computedGeneration = -1;

	private bool $isFixed = false;

	/**
	 * @param int|null $userId Null asks about the current user of the portal.
	 * @param AccessController|null $accessController The controller to ask; built from the user id when omitted.
	 */
	public function __construct(?int $userId = null, ?AccessController $accessController = null)
	{
		$this->userId = $userId;
		$this->accessController = $accessController;
	}

	/**
	 * Builds a service with the answer already known.
	 *
	 * The section asks the axis once per level and then reads the catalog through that single answer;
	 * nobody widens it on the way, because the codes of the level were built under the checks of
	 * TableGroupRepository and carry no right of their own.
	 *
	 * @param string[]|null $tableCodes Null keeps the reads unfiltered.
	 */
	public static function forAllowedCodes(?array $tableCodes): self
	{
		$service = new self();
		$service->allowedTableCodes = $tableCodes === null ? null : array_values($tableCodes);
		$service->isComputed = true;
		$service->isFixed = true;

		return $service;
	}

	/**
	 * Returns the table codes the user is allowed to see, or null when the axis is not applied.
	 *
	 * @return string[]|null
	 */
	public function getAllowedTableCodes(): ?array
	{
		if ($this->isFixed)
		{
			return $this->allowedTableCodes;
		}

		if ($this->isComputed && $this->computedGeneration === self::$generation)
		{
			return $this->allowedTableCodes;
		}

		$this->allowedTableCodes = $this->resolveAllowedTableCodes();
		$this->allowedTableCodeIndex = null;
		$this->isComputed = true;
		$this->computedGeneration = self::$generation;

		return $this->allowedTableCodes;
	}

	public function isTableAllowed(string $tableCode): bool
	{
		$allowedTableCodes = $this->getAllowedTableCodes();
		if ($allowedTableCodes === null)
		{
			return true;
		}

		// The question is asked once per table of the catalog, so the answer is kept as an index:
		// a walk through the list would cost the square of the size of the catalog on a single hit.
		if ($this->allowedTableCodeIndex === null)
		{
			$this->allowedTableCodeIndex = array_fill_keys($allowedTableCodes, true);
		}

		return isset($this->allowedTableCodeIndex[$tableCode]);
	}

	/**
	 * Tells whether the caller has to filter anything at all.
	 */
	public function isRestricted(): bool
	{
		return $this->getAllowedTableCodes() !== null;
	}

	/**
	 * Drops the answers computed before the group composition changed.
	 *
	 * Called by the write side of the groups: within a single hit the grid is redrawn after the save,
	 * and the answer computed before it speaks about the previous composition.
	 */
	public static function clearCache(): void
	{
		self::$generation++;
	}

	/**
	 * @return string[]|null
	 */
	private function resolveAllowedTableCodes(): ?array
	{
		if (!Feature::isTablePermissionsEnabled())
		{
			return null;
		}

		$allowedGroupIds = $this->getAccessController()->getAllowedGroupValue(ActionDictionary::ACTION_BIC_TABLE_VIEW) ?? [];
		if (empty($allowedGroupIds))
		{
			return [];
		}

		return TableGroupMembershipService::getTableCodesByGroupIds($allowedGroupIds);
	}

	/**
	 * The controller the answer was asked of: the callers that ask the same person about other parts
	 * of the section take it from here instead of building a second one.
	 */
	public function getAccessController(): AccessController
	{
		if ($this->accessController !== null)
		{
			return $this->accessController;
		}

		return $this->userId === null
			? AccessController::getCurrent()
			: AccessController::getInstance($this->userId)
		;
	}
}
