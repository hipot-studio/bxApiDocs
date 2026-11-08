<?php

namespace Bitrix\Crm\Service\UserPermissions;

use Bitrix\Crm\Security\Role\Manage\Entity\CrmConfig;
use Bitrix\Crm\Security\Role\Manage\Enum\Permission;
use Bitrix\Crm\Security\Role\Manage\PermissionEntityBuilder;
use Bitrix\Crm\Security\Role\PermissionsManager;
use Bitrix\Crm\Service\UserPermissions;

/**
 * The granular configuration capabilities of CRM and of every automated solution (digital workplace).
 *
 * The single place the seven configuration operations, tunnels and funnel access are computed from.
 * A capability is always answered inside one contour ({@see ConfigurationScope}): the full
 * configuration right of the contour is a super right over its operations, and neither the full right
 * nor an explicit operation ever carries over between CRM and a digital workplace.
 *
 * Registering the operations in the role model is the only activation point - there is no feature
 * flag - and both configuration entities declare them: {@see CrmConfig} for CRM and
 * {@see \Bitrix\Crm\Security\Role\Manage\Entity\AutomatedSolutionConfig} for a digital workplace. The
 * former check of the asking surface ({@see ConfigurationCallSite}) is still the answer of three
 * paths: a contour excluded from the model, a contour whose entity declares the operations only in
 * part ({@see operationsRegistration()}), and a rollback - taking the operations back out of the model
 * returns every answer to what the surface permitted before.
 *
 * @internal
 * Do not use directly, only through \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions()->configuration()
 */
final class Configuration
{
	/**
	 * Registration state per configuration entity of a contour. A contour missing from the model is
	 * absent here rather than stored as "not registered" - {@see operationsRegistration()}.
	 *
	 * Static because the fact belongs to the role model and not to a user, while this facade lives on
	 * the per-user {@see UserPermissions}: an instance cache would be rebuilt for every user a hit asks
	 * about.
	 *
	 * @var array<string, bool>
	 */
	private static array $registrationByEntityCode = [];

	public function __construct(
		private readonly PermissionsManager $permissionsManager,
		private readonly Admin $admin,
		private readonly AutomatedSolution $automatedSolution,
		private readonly UserPermissions\EntityPermissions\Admin $entityAdmin,
		private readonly Automation $automation,
	)
	{
	}

	/**
	 * CRUD and sorting of funnels, neither their access nor their tunnels.
	 *
	 * The right alone. The product conditions of a surface are not expressed by the capability and stay
	 * at the call site: the funnel buttons of `crm.sales.tunnels` keep their own
	 * `&& $entityTypeId !== CCrmOwnerType::Lead`, because a lead has no funnels to manage at all.
	 */
	public function canManageFunnels(ConfigurationCallSite $callSite, ConfigurationScope $scope): bool
	{
		return $this->can(ConfigurationOperation::ManageFunnels, $callSite, $scope);
	}

	/**
	 * Every operation on the stages of a funnel.
	 */
	public function canManageStages(ConfigurationCallSite $callSite, ConfigurationScope $scope): bool
	{
		return $this->can(ConfigurationOperation::ManageStages, $callSite, $scope);
	}

	/**
	 * Creating and changing user fields, not deleting them.
	 */
	public function canManageFields(ConfigurationCallSite $callSite, ConfigurationScope $scope): bool
	{
		return $this->can(ConfigurationOperation::ManageFields, $callSite, $scope);
	}

	/**
	 * Deleting user fields only.
	 */
	public function canDeleteFields(ConfigurationCallSite $callSite, ConfigurationScope $scope): bool
	{
		return $this->can(ConfigurationOperation::DeleteFields, $callSite, $scope);
	}

	/**
	 * Common views and the common card layout.
	 */
	public function canManageCommonViews(ConfigurationCallSite $callSite, ConfigurationScope $scope): bool
	{
		return $this->can(ConfigurationOperation::ManageCommonViews, $callSite, $scope);
	}

	/**
	 * Business process templates - neither starting a process nor robots.
	 */
	public function canManageBizprocTemplates(ConfigurationCallSite $callSite, ConfigurationScope $scope): bool
	{
		return $this->can(ConfigurationOperation::ManageBizprocTemplates, $callSite, $scope);
	}

	/**
	 * Smart process types of the contour.
	 */
	public function canManageDynamicTypes(ConfigurationCallSite $callSite, ConfigurationScope $scope): bool
	{
		return $this->can(ConfigurationOperation::ManageDynamicTypes, $callSite, $scope);
	}

	public function preloadPermissions(array $scopes): void
	{
		if (!self::modelDeclaresOperations())
		{
			return;
		}

		$entityCodes = [];
		foreach ($scopes as $scope)
		{
			$entityCode = $scope->getPermissionEntityCode();
			if ($entityCode !== null)
			{
				$entityCodes[] = $entityCode;
			}
		}

		$this->permissionsManager->preloadEntities($entityCodes);
	}

	/**
	 * Tunnels of the source funnel. Once the operations are registered this is the robots right of that
	 * funnel and nothing else: the right of the target funnel is deliberately not consulted, and no
	 * granular operation changes what robots permit.
	 *
	 * $scope must be the source funnel - {@see ConfigurationScope::ofEntityType()} of the entity type
	 * and the category the tunnel starts in.
	 */
	public function canManageTunnels(ConfigurationCallSite $callSite, ConfigurationScope $scope): bool
	{
		return $this->can(ConfigurationOperation::ManageTunnels, $callSite, $scope);
	}

	/**
	 * Changing who has access to a funnel. An alias of the full configuration right of the contour: it
	 * is not registered as an operation and is never granted by one.
	 */
	public function canManageCategoryAccess(ConfigurationCallSite $callSite, ConfigurationScope $scope): bool
	{
		return $this->can(ConfigurationOperation::ManageCategoryAccess, $callSite, $scope);
	}

	public function can(
		ConfigurationOperation $capability,
		ConfigurationCallSite $callSite,
		ConfigurationScope $scope
	): bool
	{
		if ($scope->isMissingDynamicType())
		{
			return false;
		}

		if ($scope->isExcluded() || !self::modelDeclaresOperations())
		{
			return $this->legacyDecision($callSite, $scope, $capability);
		}

		$registration = $this->operationsRegistration($scope);
		if ($registration === null)
		{
			// A contour the role model does not know at all: a digital workplace that never existed or was
			// already deleted, named either by the target of a type being created or by an entity whose type
			// still points at it. It is refused - there is no entity to read an operation from, and the full
			// right of a workplace that does not exist is not a right. The former check is not an option
			// here: the model does offer the operations, or this point was not reached at all.
			return false;
		}

		if ($registration === false)
		{
			return $this->legacyDecision($callSite, $scope, $capability);
		}

		if ($capability === ConfigurationOperation::ManageTunnels)
		{
			$entityTypeId = $scope->getEntityTypeId();

			return $entityTypeId !== null && $this->automation->canEdit($entityTypeId, $scope->getCategoryId());
		}

		if ($capability === ConfigurationOperation::ManageCategoryAccess)
		{
			return $this->hasFullConfiguration($scope);
		}

		return $this->hasFullConfiguration($scope) || $this->hasExplicitOperation($scope, $capability);
	}

	/**
	 * Forgets what the activation probe answered.
	 *
	 * The probe reads the role model rather than a user, so its answer is kept for the whole process
	 * ({@see $registrationByEntityCode}) and none of the caches a role write drops reaches it: a role
	 * written in this same process needs it dropped along with the loaded permissions of the users, and
	 * a test that asks about both states of the model needs it dropped between the two.
	 *
	 * @internal
	 */
	public static function resetOperationsRegistrationCache(): void
	{
		self::$registrationByEntityCode = [];
	}

	/**
	 * The full configuration right of the contour, the super right over its operations.
	 */
	private function hasFullConfiguration(ConfigurationScope $scope): bool
	{
		$automatedSolutionId = $scope->getAutomatedSolutionId();

		return $automatedSolutionId === null
			? $this->admin->isCrmAdmin()
			: $this->automatedSolution->isAutomatedSolutionAdmin($automatedSolutionId)
		;
	}

	/**
	 * The former check of the asking surface, the answer while the contour does not declare the
	 * operations.
	 *
	 * The table of the surfaces, which is why it cannot be reduced to the capability: managing stages
	 * was global in `CCrmStatus` and contextual in the category domain and in the mobile controllers.
	 * The whole funnel screen was contextual, tunnels included: the write actions of `crm.sales.tunnels`
	 * pass through `init()` alone, and `TunnelManager` checks nothing of its own - its two live write
	 * entries are that screen and the mobile stage controller.
	 *
	 * The contextual check has two forms of its own and they disagree for the contractor category, so a
	 * surface keeps the form its former point used: the write actions of the mobile funnel controller
	 * passed the category on, the mobile stage controller never did.
	 *
	 * $capability is part of the table as well - it is what tells apart a surface whose actions used to
	 * check different things - even though no surface needs it today.
	 */
	private function legacyDecision(
		ConfigurationCallSite $callSite,
		ConfigurationScope $scope,
		ConfigurationOperation $capability
	): bool
	{
		return match ($callSite)
		{
			ConfigurationCallSite::LegacyRestDealCategory,
			ConfigurationCallSite::StatusDictionary,
			ConfigurationCallSite::FieldsListComponent,
			ConfigurationCallSite::FieldsEditComponent,
			ConfigurationCallSite::EntityEditorPermissions,
			ConfigurationCallSite::EntityEditorConfig,
			ConfigurationCallSite::BizprocDocument,
			ConfigurationCallSite::BizprocTemplateComponent,
			ConfigurationCallSite::TypeControllerRead,
			ConfigurationCallSite::CrmConfigsComponent => $this->admin->isCrmAdmin(),

			// The surfaces that passed the category on as well. The mobile funnel controller did so on its
			// write actions; the paths of it that asked about the entity type alone pass a scope without a
			// category ({@see ConfigurationCallSite}).
			ConfigurationCallSite::CategoryDomainService,
			ConfigurationCallSite::CategoryPermissions,
			ConfigurationCallSite::EntityEditorComponent,
			ConfigurationCallSite::ScopeAccess,
			ConfigurationCallSite::McpPageLayout,
			ConfigurationCallSite::McpStageService,
			ConfigurationCallSite::AiCardViewCreate,
			ConfigurationCallSite::KanbanComponent,
			ConfigurationCallSite::FactoryBasedFieldCreation,
			ConfigurationCallSite::RequirementProvider,
			ConfigurationCallSite::RequirementCommand,
			ConfigurationCallSite::MobileCategoryController =>
				$this->isContourAdmin($scope, $scope->getCategoryId()),

			ConfigurationCallSite::TypeControllerDelete =>
				$this->isContourAdmin($scope) || $this->admin->isCrmAdmin(),

			default => $this->isContourAdmin($scope),
		};
	}

	/**
	 * The contextual administrator check of the former surfaces. With an entity it is exactly
	 * `isAdminForEntity()`; without one - a type being created or moved - it is the administrator of
	 * the target contour, which is what the add path of the type controller used to check.
	 *
	 * $categoryId is what the former point of the surface passed and is deliberately not taken from the
	 * scope: for the contractor category `isAdminForEntity()` answers with the warehouse configuration
	 * right instead of the CRM administrator ({@see UserPermissions\EntityPermissions\Admin}), so a
	 * surface that used to ask about the entity type alone has to keep asking about it alone.
	 */
	private function isContourAdmin(ConfigurationScope $scope, ?int $categoryId = null): bool
	{
		$entityTypeId = $scope->getEntityTypeId();
		if ($entityTypeId !== null)
		{
			return $this->entityAdmin->isAdminForEntity($entityTypeId, $categoryId);
		}

		return $this->hasFullConfiguration($scope);
	}

	private function hasExplicitOperation(ConfigurationScope $scope, ConfigurationOperation $operation): bool
	{
		$entityCode = $scope->getPermissionEntityCode();
		$storedCode = $operation->getStoredCode();
		if ($entityCode === null || $storedCode === null)
		{
			return false;
		}

		return $this->permissionsManager->hasPermissionLevel(
			$entityCode,
			$storedCode,
			UserPermissions::PERMISSION_ALL
		);
	}

	/**
	 * Whether the role model offers the configuration operations at all - the activation point of the
	 * whole model.
	 *
	 * Asked of the CRM configuration entity, and of it alone: it is the one contour the model always
	 * holds and the one whose declaration is read without touching the portal, while the entity of an
	 * automated solution is built out of the workplaces of the portal. Until CRM declares the operations
	 * no contour is looked up at all - an entity that exists without them and a contour the model does
	 * not hold both keep the former check of the asking surface, so the lookup could not change a single
	 * answer and a permission check may not pay for it. The other side of that: a workplace whose entity
	 * declared the operations while the CRM one did not would keep the former checks as well, which is
	 * the rule the model already follows for a partially declared contour
	 * ({@see operationsRegistration()}).
	 */
	private static function modelDeclaresOperations(): bool
	{
		// The read never answers "no such contour" here: the CRM configuration entity is unconditional in
		// PermissionEntityBuilder and CrmConfig always makes it.
		return self::$registrationByEntityCode[CrmConfig::ENTITY_CODE]
			??= self::readRegistration(ConfigurationScope::ofCrm(), CrmConfig::ENTITY_CODE) === true
		;
	}

	/**
	 * Whether the configuration entity of the contour declares the operations, and `null` when the model
	 * holds no such entity at all - a digital workplace that never existed or was already deleted
	 * ({@see can()}).
	 *
	 * Deliberately the declaration of the role model and not a stored row: a role without the new row
	 * is a role that was not granted the operation, while a model without the operation is a model
	 * where the operation does not exist yet, and only the second one may fall back to the former
	 * checks. All the operations are required: a partially declared contour keeps the former behaviour
	 * rather than opening half of the new one.
	 */
	private function operationsRegistration(ConfigurationScope $scope): ?bool
	{
		$entityCode = $scope->getPermissionEntityCode();
		if ($entityCode === null)
		{
			return false;
		}

		if (isset(self::$registrationByEntityCode[$entityCode]))
		{
			return self::$registrationByEntityCode[$entityCode];
		}

		$registration = self::readRegistration($scope, $entityCode);
		if ($registration === null)
		{
			// Not memoized: a workplace created later in this same process would otherwise keep the answer
			// of the moment it did not exist yet.
			return null;
		}

		return self::$registrationByEntityCode[$entityCode] = $registration;
	}

	/**
	 * `null` when the model has no configuration entity of this contour, which is a different state from
	 * an entity that exists without the operations.
	 */
	private static function readRegistration(ConfigurationScope $scope, string $entityCode): ?bool
	{
		$declaredCodes = null;
		foreach (self::configurationEntityBuilder($scope)->buildOfMade() as $entity)
		{
			if ($entity->code() !== $entityCode)
			{
				continue;
			}

			$declaredCodes ??= [];
			foreach ($entity->permissions() as $permission)
			{
				$declaredCodes[$permission->code()] = true;
			}
		}

		if ($declaredCodes === null)
		{
			return null;
		}

		foreach (ConfigurationOperation::storedOperations() as $operation)
		{
			if (!isset($declaredCodes[$operation->getStoredCode()]))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Only the configuration entity of the contour is built: the whole role model enumerates the
	 * categories and the stages of every entity type of the portal, which a permission check may not
	 * pay for.
	 */
	private static function configurationEntityBuilder(ConfigurationScope $scope): PermissionEntityBuilder
	{
		$builder = new PermissionEntityBuilder();
		if (!$scope->isAutomatedSolution())
		{
			return $builder->include(Permission::CrmConfig);
		}

		return $builder
			->include(Permission::AutomatedSolutionConfig)
			->filterByAutomatedSolution(Permission::AutomatedSolutionConfig, $scope->getAutomatedSolutionId())
		;
	}
}
