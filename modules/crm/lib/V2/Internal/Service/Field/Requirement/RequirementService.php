<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Field\Requirement;

use Bitrix\Crm\Attribute\FieldAttributePhaseGroupType;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions\ConfigurationCallSite;
use Bitrix\Crm\Service\UserPermissions\ConfigurationScope;
use Bitrix\Crm\V2\Public\Entity\Field\Requirement;
use Bitrix\Crm\V2\Public\Entity\Field\RequirementError;
use Bitrix\Crm\V2\Public\Entity\Field\RequirementException;
use Bitrix\Crm\V2\Public\Entity\Field\RequirementTechnicalException;
use Bitrix\Crm\V2\Public\EntityType;

/**
 * @internal
 */
final class RequirementService
{
	private readonly \Closure $permissionChecker;
	private readonly \Closure $contextResolver;
	private readonly \Closure $fieldResolver;
	private readonly \Closure $legacyRequirementReader;
	private readonly \Closure $stagesLoader;

	/**
	 * @param null|\Closure(int, ConfigurationScope): bool $permissionChecker
	 * @param null|\Closure(EntityType, ?int): RequirementContext $contextResolver
	 * @param null|\Closure(EntityType, string): ResolvedRequirementField $fieldResolver
	 * @param null|\Closure(EntityType, ?int, string): LegacyRequirementRule $legacyRequirementReader
	 * @param null|\Closure(EntityType, int): list<array{id: string, sort: int, semantics: string, internalId: int}> $stagesLoader
	 */
	public function __construct(
		?\Closure $permissionChecker = null,
		?\Closure $contextResolver = null,
		?\Closure $fieldResolver = null,
		?\Closure $legacyRequirementReader = null,
		?\Closure $stagesLoader = null,
	)
	{
		$this->permissionChecker = $permissionChecker ?? static fn (int $userId, ConfigurationScope $scope): bool => Container::getInstance()
			->getUserPermissions($userId)
			->configuration()
			->canManageFields(ConfigurationCallSite::RequirementProvider, $scope)
		;
		$this->contextResolver = $contextResolver ?? static fn (EntityType $entityType, ?int $categoryId): RequirementContext => (new RequirementContextResolver())->resolve($entityType, $categoryId);
		$this->fieldResolver = $fieldResolver ?? static fn (EntityType $entityType, string $fieldId): ResolvedRequirementField => (new RequirementFieldResolver())->resolve($entityType, $fieldId);
		$this->legacyRequirementReader = $legacyRequirementReader
			?? static fn (EntityType $entityType, ?int $categoryId, string $fieldName): LegacyRequirementRule => (new LegacyRequirementAdapter())->read($entityType, $categoryId, $fieldName);
		$this->stagesLoader = $stagesLoader ?? static function (EntityType $entityType, int $categoryId): array {
			$factory = Container::getInstance()->getFactory($entityType->getId());
			if ($factory === null)
			{
				throw new \RuntimeException('The CRM entity factory disappeared while reading field requirement.');
			}

			$stages = [];
			foreach ($factory->getStages($categoryId) as $stage)
			{
				$stages[] = [
					'id' => $stage->getStatusId(),
					'sort' => (int)$stage->getSort(),
					'semantics' => (string)($stage->getSemantics() ?? PhaseSemantics::PROCESS),
					'internalId' => (int)$stage->getId(),
				];
			}

			return $stages;
		};
	}

	/**
	 * @throws RequirementException
	 * @throws RequirementTechnicalException
	 */
	public function read(
		EntityType $entityType,
		int $userId,
		string $fieldId,
		?int $categoryId,
	): Requirement
	{
		try
		{
			$this->assertPermission($entityType, $userId, $categoryId);

			$context = ($this->contextResolver)($entityType, $categoryId);
			$field = ($this->fieldResolver)($entityType, $fieldId);
			if (!$field->hasLegacyRequirementKey())
			{
				return Requirement::none($field->fieldId, $context->categoryId, $field->globallyRequired);
			}

			$rule = ($this->legacyRequirementReader)(
				$entityType,
				$context->categoryId,
				$field->legacyRequirementFieldName,
			);
			if ($rule->isNone())
			{
				return Requirement::none($field->fieldId, $context->categoryId, $field->globallyRequired);
			}
			if ($rule->isAll())
			{
				return Requirement::all($field->fieldId, $context->categoryId, $field->globallyRequired);
			}
			if (!$context->supportsStages)
			{
				throw $this->configurationConflict();
			}

			$stageIds = $this->resolveRequiredStageIds(
				$rule,
				($this->stagesLoader)($entityType, $context->stageCategoryId),
			);

			return Requirement::stages(
				$field->fieldId,
				$context->categoryId,
				$stageIds,
				$field->globallyRequired,
			);
		}
		catch (RequirementException|RequirementTechnicalException $exception)
		{
			throw $exception;
		}
		catch (\Exception $exception)
		{
			throw new RequirementTechnicalException($exception);
		}
	}

	private function assertPermission(EntityType $entityType, int $userId, ?int $categoryId): void
	{
		$scope = ConfigurationScope::ofEntityType($entityType->getId(), $categoryId);
		if (($this->permissionChecker)($userId, $scope))
		{
			return;
		}

		if (
			$scope->isMissingDynamicType()
			&& ($this->permissionChecker)($userId, ConfigurationScope::ofCrm())
		)
		{
			return;
		}

		throw new RequirementException(RequirementError::ACCESS_DENIED);
	}

	/**
	 * @param list<array{id: string, sort: int, semantics: string, internalId: int}> $stages
	 * @return non-empty-list<string>
	 */
	private function resolveRequiredStageIds(LegacyRequirementRule $rule, array $stages): array
	{
		$selected = [];
		foreach ($rule->getRanges() as $range)
		{
			$groupStages = array_values(array_filter(
				$stages,
				fn (array $stage): bool => $this->belongsToGroup($stage, $range->getPhaseGroupTypeId()),
			));
			$start = $this->findStage($groupStages, $range->getStartPhaseId());
			$finish = $this->findStage($groupStages, $range->getFinishPhaseId());
			if ($start === null || $finish === null || $start['sort'] > $finish['sort'])
			{
				throw $this->configurationConflict();
			}
			foreach ($groupStages as $stage)
			{
				if ($stage['sort'] >= $start['sort'] && $stage['sort'] <= $finish['sort'])
				{
					$selected[$stage['id']] = $stage;
				}
			}
		}

		if ($selected === [])
		{
			throw $this->configurationConflict();
		}

		usort(
			$selected,
			static fn (array $left, array $right): int => [$left['sort'], $left['internalId'], $left['id']]
				<=> [$right['sort'], $right['internalId'], $right['id']],
		);

		return array_values(array_column($selected, 'id'));
	}

	/**
	 * @param array{id: string, sort: int, semantics: string, internalId: int} $stage
	 */
	private function belongsToGroup(array $stage, int $groupTypeId): bool
	{
		return match ($groupTypeId)
		{
			FieldAttributePhaseGroupType::PIPELINE => !PhaseSemantics::isLost($stage['semantics']),
			FieldAttributePhaseGroupType::JUNK => PhaseSemantics::isLost($stage['semantics']),
			default => throw $this->configurationConflict(),
		};
	}

	/**
	 * @param list<array{id: string, sort: int, semantics: string, internalId: int}> $stages
	 * @return null|array{id: string, sort: int, semantics: string, internalId: int}
	 */
	private function findStage(array $stages, string $stageId): ?array
	{
		foreach ($stages as $stage)
		{
			if ($stage['id'] === $stageId)
			{
				return $stage;
			}
		}

		return null;
	}

	private function configurationConflict(): RequirementException
	{
		return new RequirementException(RequirementError::CONFIGURATION_CONFLICT);
	}
}
