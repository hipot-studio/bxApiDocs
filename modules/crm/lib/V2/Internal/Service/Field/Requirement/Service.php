<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Field\Requirement;

use Bitrix\Crm\Attribute\FieldAttributePhaseGroupType;
use Bitrix\Crm\Attribute\FieldAttributeManager;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions\ConfigurationCallSite;
use Bitrix\Crm\Service\UserPermissions\ConfigurationScope;
use Bitrix\Crm\V2\Public\Entity\Field\Requirement\Exception;
use Bitrix\Crm\V2\Public\Entity\Field\Requirement\Error;
use Bitrix\Crm\V2\Public\Entity\Field\Requirement\Requirement;
use Bitrix\Crm\V2\Public\Entity\Field\Requirement\SetInput;
use Bitrix\Crm\V2\Public\EntityType;

/**
 * @internal
 */
final class Service
{
	private const TECHNICAL_ERROR_MESSAGE = 'Unable to process field requirement.';

	private readonly \Closure $permissionChecker;
	private readonly \Closure $contextResolver;
	private readonly \Closure $fieldResolver;
	private readonly \Closure $legacyRequirementReader;
	private readonly \Closure $stagesLoader;
	private readonly \Closure $fieldPolicy;
	private readonly \Closure $stageRequirementAvailabilityChecker;
	private readonly \Closure $writePlanner;
	private readonly \Closure $legacyRequirementWriter;

	/**
	 * @param null|\Closure(int, ConfigurationScope, ConfigurationCallSite): bool $permissionChecker
	 * @param null|\Closure(EntityType, ?int): Context $contextResolver
	 * @param null|\Closure(EntityType, string): ResolvedRequirementField $fieldResolver
	 * @param null|\Closure(EntityType, ?int, string): LegacyRequirementRule $legacyRequirementReader
	 * @param null|\Closure(EntityType, int): list<array{id: string, sort: int, semantics: string, internalId: int}> $stagesLoader
	 * @param null|\Closure(EntityType, int, Context, ResolvedRequirementField): void $fieldPolicy
	 * @param null|\Closure(): bool $stageRequirementAvailabilityChecker
	 * @param null|\Closure(SetInput, Context, array, bool): WritePlan $writePlanner
	 * @param null|\Closure(EntityType, ?int, string, LegacyRequirementRule, string): void $legacyRequirementWriter
	 */
	public function __construct(
		?\Closure $permissionChecker = null,
		?\Closure $contextResolver = null,
		?\Closure $fieldResolver = null,
		?\Closure $legacyRequirementReader = null,
		?\Closure $stagesLoader = null,
		?\Closure $fieldPolicy = null,
		?\Closure $stageRequirementAvailabilityChecker = null,
		?\Closure $writePlanner = null,
		?\Closure $legacyRequirementWriter = null,
	)
	{
		$this->permissionChecker = $permissionChecker ?? static fn (
			int $userId,
			ConfigurationScope $scope,
			ConfigurationCallSite $callSite,
		): bool => Container::getInstance()
			->getUserPermissions($userId)
			->configuration()
			->canManageFields($callSite, $scope)
		;
		$this->contextResolver = $contextResolver ?? static fn (EntityType $entityType, ?int $categoryId): Context => (new ContextResolver())->resolve($entityType, $categoryId);
		$this->fieldResolver = $fieldResolver ?? static fn (EntityType $entityType, string $fieldId): ResolvedRequirementField => (new FieldResolver())->resolve($entityType, $fieldId);
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
		$this->fieldPolicy = $fieldPolicy
			?? static function (
				EntityType $entityType,
				int $userId,
				Context $context,
				ResolvedRequirementField $field,
			): void {
				(new SetFieldPolicy())->assertEligible($entityType, $userId, $context, $field);
			};
		$this->stageRequirementAvailabilityChecker = $stageRequirementAvailabilityChecker
			?? static fn (): bool => FieldAttributeManager::isPhaseDependent();
		$this->writePlanner = $writePlanner
			?? static fn (
				SetInput $input,
				Context $context,
				array $stages,
				bool $available,
			): WritePlan => (new WritePlanner())->plan($input, $context, $stages, $available);
		$this->legacyRequirementWriter = $legacyRequirementWriter
			?? static function (
				EntityType $entityType,
				?int $categoryId,
				string $fieldName,
				LegacyRequirementRule $rule,
				string $operation,
			): void {
				(new LegacyRequirementAdapter())->replace(
					$entityType,
					$categoryId,
					$fieldName,
					$rule,
					$operation,
				);
			};
	}

	/**
	 * @throws Exception
	 * @throws \RuntimeException
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
			$this->assertPermission(
				$entityType,
				$userId,
				$categoryId,
				ConfigurationCallSite::RequirementProvider,
			);

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
		catch (Exception $exception)
		{
			throw $exception;
		}
		catch (\Exception $exception)
		{
			throw new \RuntimeException(self::TECHNICAL_ERROR_MESSAGE, 0, $exception);
		}
	}

	/**
	 * @throws Exception
	 * @throws \RuntimeException
	 */
	public function set(
		EntityType $entityType,
		int $userId,
		string $fieldId,
		?int $categoryId,
		SetInput $input,
	): Requirement
	{
		if ($input->isClearRequested())
		{
			return $this->clear($entityType, $userId, $fieldId, $categoryId);
		}

		try
		{
			$this->assertPermission(
				$entityType,
				$userId,
				$categoryId,
				ConfigurationCallSite::RequirementCommand,
			);
			$context = ($this->contextResolver)($entityType, $categoryId);
			$field = ($this->fieldResolver)($entityType, $fieldId);
			($this->fieldPolicy)($entityType, $userId, $context, $field);

			$stages = [];
			$available = false;
			$requiredStageIds = $input->getRequiredStageIds();
			if ($context->supportsStages && is_array($requiredStageIds) && $requiredStageIds !== [])
			{
				$stages = ($this->stagesLoader)($entityType, $context->stageCategoryId);
				$available = ($this->stageRequirementAvailabilityChecker)();
			}

			$plan = ($this->writePlanner)($input, $context, $stages, $available);
			($this->legacyRequirementWriter)(
				$entityType,
				$context->categoryId,
				$field->legacyRequirementFieldName,
				$plan->getRule(),
				'set',
			);

			return $plan->getRequiredStageIds() === null
				? Requirement::all($field->fieldId, $context->categoryId, $field->globallyRequired)
				: Requirement::stages(
					$field->fieldId,
					$context->categoryId,
					$plan->getRequiredStageIds(),
					$field->globallyRequired,
				);
		}
		catch (Exception $exception)
		{
			throw $exception;
		}
		catch (\Exception $exception)
		{
			throw new \RuntimeException(self::TECHNICAL_ERROR_MESSAGE, 0, $exception);
		}
	}

	/**
	 * @throws Exception
	 * @throws \RuntimeException
	 */
	public function clear(
		EntityType $entityType,
		int $userId,
		string $fieldId,
		?int $categoryId,
	): Requirement
	{
		try
		{
			$this->assertPermission(
				$entityType,
				$userId,
				$categoryId,
				ConfigurationCallSite::RequirementCommand,
			);
			$context = ($this->contextResolver)($entityType, $categoryId);
			$field = ($this->fieldResolver)($entityType, $fieldId);
			if (!$field->hasLegacyRequirementKey())
			{
				throw new Exception(Error::FIELD_REQUIREMENT_NOT_SUPPORTED);
			}
			($this->legacyRequirementWriter)(
				$entityType,
				$context->categoryId,
				$field->legacyRequirementFieldName,
				LegacyRequirementRule::none(),
				'clear',
			);

			return Requirement::none($field->fieldId, $context->categoryId, $field->globallyRequired);
		}
		catch (Exception $exception)
		{
			throw $exception;
		}
		catch (\Exception $exception)
		{
			throw new \RuntimeException(self::TECHNICAL_ERROR_MESSAGE, 0, $exception);
		}
	}

	private function assertPermission(
		EntityType $entityType,
		int $userId,
		?int $categoryId,
		ConfigurationCallSite $callSite,
	): void
	{
		$scope = ConfigurationScope::ofEntityType($entityType->getId(), $categoryId);
		if (($this->permissionChecker)($userId, $scope, $callSite))
		{
			return;
		}

		if (
			$scope->isMissingDynamicType()
			&& ($this->permissionChecker)($userId, ConfigurationScope::ofCrm(), $callSite)
		)
		{
			return;
		}

		throw new Exception(Error::ACCESS_DENIED);
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
			static fn (array $left, array $right): int => ($left['sort'] <=> $right['sort'])
				?: strcmp($left['id'], $right['id']),
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

	private function configurationConflict(): Exception
	{
		return new Exception(Error::CONFIGURATION_CONFLICT);
	}
}
