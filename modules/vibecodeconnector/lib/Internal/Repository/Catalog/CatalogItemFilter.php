<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Repository\Catalog;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemAccessType;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;

final class CatalogItemFilter
{
	private ?int $id = null;
	/** @var int[]|null */
	private ?array $ids = null;
	private ?int $ownerUserId = null;
	private ?int $ownerUserNotId = null;
	private ?CatalogItemAccessType $accessType = null;
	private ?CatalogItemAccessType $accessTypeNot = null;
	/** @var CatalogItemAccessType[]|null */
	private ?array $accessTypeIn = null;
	private ?CatalogItemType $type = null;
	private ?string $query = null;
	private ?int $accessibleToUserId = null;
	/** @var string[]|null */
	private ?array $accessibleToUserCodes = null;
	/** @var string[]|null */
	private ?array $notGrantedToUserCodes = null;
	private ?int $userContextId = null;
	private bool $includeDeactivated = false;
	private ?string $pairingIss = null;
	private ?bool $hiddenState = null;
	private ?bool $notOpenedByUser = null;
	private ?ConditionTree $where = null;

	public function id(?int $id): self
	{
		$this->id = $id;

		return $this;
	}

	/**
	 * @param int[]|null $ids
	 */
	public function ids(?array $ids): self
	{
		$this->ids = $ids === null ? null : array_values(array_unique(array_map('intval', $ids)));

		return $this;
	}

	public function ownerUserId(?int $ownerUserId): self
	{
		$this->ownerUserId = $ownerUserId;

		return $this;
	}

	public function ownerUserNotId(?int $ownerUserNotId): self
	{
		$this->ownerUserNotId = $ownerUserNotId;

		return $this;
	}

	public function accessType(?CatalogItemAccessType $accessType): self
	{
		$this->accessType = $accessType;

		return $this;
	}

	public function accessTypeNot(?CatalogItemAccessType $accessTypeNot): self
	{
		$this->accessTypeNot = $accessTypeNot;

		return $this;
	}

	/**
	 * @param CatalogItemAccessType[]|null $accessTypes
	 */
	public function accessTypeIn(?array $accessTypes): self
	{
		$this->accessTypeIn = $accessTypes;

		return $this;
	}

	public function type(?CatalogItemType $type): self
	{
		$this->type = $type;

		return $this;
	}

	public function query(?string $query): self
	{
		$trimmed = $query !== null ? trim($query) : null;
		$this->query = ($trimmed === null || $trimmed === '') ? null : $trimmed;

		return $this;
	}

	/**
	 * Restrict to items the user can read: owned, public, or ACL granted to their access codes.
	 *
	 * @param string[] $userCodes
	 */
	public function accessibleToUser(int $userId, array $userCodes = []): self
	{
		$this->accessibleToUserId = $userId;
		$this->accessibleToUserCodes = array_values($userCodes);

		return $this;
	}

	/**
	 * Restrict to items NOT granted to the given access codes (used for "discoverable" listings).
	 *
	 * @param string[]|null $userCodes
	 */
	public function notGrantedTo(?array $userCodes): self
	{
		$this->notGrantedToUserCodes = $userCodes === null ? null : array_values($userCodes);

		return $this;
	}

	/**
	 * Bind a user to the query — enables sorting by IS_PINNED, USER_PIN.PINNED_AT, IS_OWNED_BY_USER.
	 */
	public function userContext(?int $userId): self
	{
		$this->userContextId = $userId;

		return $this;
	}

	public function hiddenState(?bool $hiddenState): self
	{
		$this->hiddenState = $hiddenState;

		return $this;
	}

	public function getHiddenState(): ?bool
	{
		return $this->hiddenState;
	}

	public function notOpenedByUser(?bool $notOpenedByUser): self
	{
		$this->notOpenedByUser = $notOpenedByUser;

		return $this;
	}

	public function getNotOpenedByUser(): ?bool
	{
		return $this->notOpenedByUser;
	}

	public function where(?ConditionTree $where): self
	{
		$this->where = $where;

		return $this;
	}

	public function includeDeactivated(bool $include = true): self
	{
		$this->includeDeactivated = $include;

		return $this;
	}

	public function getIncludeDeactivated(): bool
	{
		return $this->includeDeactivated;
	}

	public function pairingIss(?string $iss): self
	{
		$this->pairingIss = $iss;

		return $this;
	}

	public function getPairingIss(): ?string
	{
		return $this->pairingIss;
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	/**
	 * @return int[]|null
	 */
	public function getIds(): ?array
	{
		return $this->ids;
	}

	public function getOwnerUserId(): ?int
	{
		return $this->ownerUserId;
	}

	public function getOwnerUserNotId(): ?int
	{
		return $this->ownerUserNotId;
	}

	public function getAccessType(): ?CatalogItemAccessType
	{
		return $this->accessType;
	}

	public function getAccessTypeNot(): ?CatalogItemAccessType
	{
		return $this->accessTypeNot;
	}

	/**
	 * @return CatalogItemAccessType[]|null
	 */
	public function getAccessTypeIn(): ?array
	{
		return $this->accessTypeIn;
	}

	public function getType(): ?CatalogItemType
	{
		return $this->type;
	}

	public function getQuery(): ?string
	{
		return $this->query;
	}

	public function getAccessibleToUserId(): ?int
	{
		return $this->accessibleToUserId;
	}

	/**
	 * @return string[]|null
	 */
	public function getAccessibleToUserCodes(): ?array
	{
		return $this->accessibleToUserCodes;
	}

	/**
	 * @return string[]|null
	 */
	public function getNotGrantedToUserCodes(): ?array
	{
		return $this->notGrantedToUserCodes;
	}

	public function getUserContextId(): ?int
	{
		return $this->userContextId;
	}

	public function getWhere(): ?ConditionTree
	{
		return $this->where;
	}
}
