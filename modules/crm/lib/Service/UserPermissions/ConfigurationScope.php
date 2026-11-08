<?php

namespace Bitrix\Crm\Service\UserPermissions;

use Bitrix\Crm\Integration\Catalog\Contractor\CategoryRepository;
use Bitrix\Crm\Security\Role\Manage\Entity\AutomatedSolutionConfig;
use Bitrix\Crm\Security\Role\Manage\Entity\CrmConfig;
use Bitrix\Crm\Service\Container;
use CCrmOwnerType;

/**
 * The configuration contour a configuration capability is decided in: CRM itself, one definite
 * automated solution (digital workplace), or neither.
 *
 * The contour is the existing configuration entity of the role model - `CONFIG` for CRM and
 * `AS_CONFIG_<id>` for an automated solution - so a right of one contour never answers for another.
 * Entities that keep a permission block of their own (suppliers, sign documents, the HR document
 * flow) belong to no contour of this model and are excluded from it.
 *
 * The scope keeps what it was resolved from - the entity type and the category - rather than the
 * contour alone, because the contextual former check,
 * {@see \Bitrix\Crm\Service\UserPermissions\EntityPermissions\Admin::isAdminForEntity()}, needs both.
 * Two paths still take it: a contour excluded from the model and a rollback of the registration
 * ({@see Configuration::legacyDecision()}).
 */
final class ConfigurationScope
{
	private function __construct(
		private readonly ?int $automatedSolutionId,
		private readonly bool $isExcluded,
		private readonly bool $isMissingDynamicType,
		private readonly ?int $entityTypeId,
		private readonly ?int $categoryId,
	)
	{
	}

	/**
	 * The contour of an existing entity. A `null` $categoryId means the entity type as a whole.
	 */
	public static function ofEntityType(int $entityTypeId, ?int $categoryId = null): self
	{
		if (self::hasOwnPermissionModel($entityTypeId, $categoryId))
		{
			return new self(null, true, false, $entityTypeId, $categoryId);
		}

		$automatedSolutionId = null;
		if (CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
		{
			$type = Container::getInstance()->getTypeByEntityTypeId($entityTypeId);
			if ($type === null)
			{
				return new self(null, false, true, $entityTypeId, $categoryId);
			}

			$customSectionId = (int)$type->getCustomSectionId();
			$automatedSolutionId = $customSectionId > 0 ? $customSectionId : null;
		}

		return new self($automatedSolutionId, false, false, $entityTypeId, $categoryId);
	}

	/**
	 * The contour a dynamic type is being created in or moved to. There is no entity type to resolve
	 * the contour from, so the target automated solution comes from the operation itself; `null` and
	 * `0` are CRM.
	 *
	 * Any other identifier is the automated solution it names, a negative one included: the former
	 * checks of these surfaces read the target by its truthiness, and a value they asked an automated
	 * solution about may not be answered by the right of CRM instead.
	 */
	public static function ofDynamicTypeTarget(?int $automatedSolutionId): self
	{
		return new self(
			$automatedSolutionId === 0 ? null : $automatedSolutionId,
			false,
			false,
			null,
			null,
		);
	}

	/**
	 * CRM itself, for a surface that configures CRM without naming an entity.
	 */
	public static function ofCrm(): self
	{
		return new self(null, false, false, null, null);
	}

	/**
	 * The contour the user fields of an `ENTITY_ID` are configured in. It is always read from the
	 * ENTITY_ID, so a right of CRM never answers for a digital workplace and back.
	 *
	 * An ENTITY_ID belonging to no CRM entity stays in the CRM contour: that is the contour the former
	 * global check of those surfaces asked about.
	 */
	public static function ofUserFieldEntityId(string $userFieldEntityId): self
	{
		$entityTypeId = (int)CCrmOwnerType::ResolveIDByUFEntityID($userFieldEntityId);

		return $entityTypeId > 0 ? self::ofEntityType($entityTypeId) : self::ofCrm();
	}

	public function isExcluded(): bool
	{
		return $this->isExcluded;
	}

	public function isMissingDynamicType(): bool
	{
		return $this->isMissingDynamicType;
	}

	public function isAutomatedSolution(): bool
	{
		return $this->automatedSolutionId !== null;
	}

	public function getAutomatedSolutionId(): ?int
	{
		return $this->automatedSolutionId;
	}

	public function getEntityTypeId(): ?int
	{
		return $this->entityTypeId;
	}

	public function getCategoryId(): ?int
	{
		return $this->categoryId;
	}

	/**
	 * The configuration entity of the role model the contour keeps its rights in, `null` for an
	 * excluded scope - such a scope has no rights of this model at all.
	 */
	public function getPermissionEntityCode(): ?string
	{
		if ($this->isExcluded || $this->isMissingDynamicType)
		{
			return null;
		}

		return $this->automatedSolutionId === null
			? CrmConfig::ENTITY_CODE
			: AutomatedSolutionConfig::generateEntity($this->automatedSolutionId)
		;
	}

	private static function hasOwnPermissionModel(int $entityTypeId, ?int $categoryId): bool
	{
		// Sign documents and the HR document flow are configured through permission blocks of their own.
		if (in_array($entityTypeId, [CCrmOwnerType::SmartDocument, CCrmOwnerType::SmartB2eDocument], true))
		{
			return true;
		}

		// The contractor category follows the warehouse configuration right, as isAdminForEntity() has it.
		return $categoryId !== null && CategoryRepository::isContractorCategory($entityTypeId, $categoryId);
	}
}
