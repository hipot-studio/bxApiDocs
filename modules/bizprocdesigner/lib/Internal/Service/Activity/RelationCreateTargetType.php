<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Service\Activity;

use Bitrix\Bizproc\Internal\Service\Container as BizprocContainer;
use Bitrix\BizprocDesigner\Infrastructure\Enum\ConstructionType;
use Bitrix\BizprocDesigner\Internal\Entity\ActivityData;
use Bitrix\Crm\Integration\BizProc\NodeFilter\DynamicTypeScope;
use Bitrix\Crm\Integration\BizProc\NodeFilter\DynamicTypeScopePolicy;
use Bitrix\Main\Loader;

/**
 * Document type the relation autofill of a node writes its fields for.
 *
 * The node declares one fixed type for the whole area it serves, and that declared type is the first
 * smart process of the area - a stand-in, not the type the user picked. The type actually created is
 * carried by the saved properties of the sub-action flagged CREATES_DOCUMENT, so that is what the
 * autofill map has to be built for.
 *
 * The answer is used by the autofill branch alone: the availability of the filter block and the module
 * support keep asking the class declaration, because there the declared type answers for the node
 * itself and not for what it creates.
 */
final class RelationCreateTargetType
{
	private const RELATIONS_PROPERTY = 'Relations';
	private const RULES_PROPERTY = 'Rules';
	private const DYNAMIC_TYPE_ID_PROPERTY = 'DynamicTypeId';
	private const DYNAMIC_TYPE_SCOPE_PROPERTY = 'DynamicTypeScope';

	/**
	 * @param ActivityData $activity Node whose saved rules carry the creating sub-action.
	 * @param array|null $fixedDocumentType Type the node declares; the answer for a sub-action that
	 *   carries no type of its own.
	 * @return array|null Document type to build the autofill map for; null when the saved type is not a
	 *   valid target - the autofill then stays empty instead of matching against an arbitrary type.
	 */
	public function resolveTargetDocumentType(ActivityData $activity, ?array $fixedDocumentType): ?array
	{
		foreach ($this->iterateSavedActionExpressions($activity) as $expression)
		{
			$activityType = (string)($expression['activityData']['Type'] ?? $expression['actionId'] ?? '');
			if (!$this->createsDocument($activityType))
			{
				continue;
			}

			$properties = $expression['activityData']['Properties'] ?? [];
			$properties = is_array($properties) ? $properties : [];

			$entityTypeId = (int)($properties[self::DYNAMIC_TYPE_ID_PROPERTY] ?? 0);
			if ($entityTypeId <= 0)
			{
				continue;
			}

			return $this->resolveDynamicDocumentType(
				$entityTypeId,
				(string)($properties[self::DYNAMIC_TYPE_SCOPE_PROPERTY] ?? ''),
			);
		}

		return $fixedDocumentType;
	}

	/**
	 * Design-time metadata, so the class of the sub-action is never loaded just to detect it - the same
	 * flag {@see \Bitrix\BizprocDesigner\Infrastructure\Controller\Activity\Complex} reads off the node
	 * action collection.
	 */
	private function createsDocument(string $activityType): bool
	{
		if ($activityType === '')
		{
			return false;
		}

		$description = BizprocContainer::instance()->getActivitySearcherService()->searchByCode($activityType);

		return (bool)($description?->getNodeActionSettings()['CREATES_DOCUMENT'] ?? false);
	}

	private function resolveDynamicDocumentType(int $entityTypeId, string $scopeValue): ?array
	{
		if (!Loader::includeModule('crm') || !\CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
		{
			return null;
		}

		$scope = DynamicTypeScope::tryFrom($scopeValue);
		if ($scope !== null && !DynamicTypeScopePolicy::getRequestInstance()->contains($scope, $entityTypeId))
		{
			return null;
		}

		$documentType = \CCrmBizProcHelper::ResolveDocumentType($entityTypeId);

		return is_array($documentType) ? $documentType : null;
	}

	/**
	 * Action expressions of the node in the order the algorithm answers by: the relation ports first,
	 * then the ordinary ones.
	 *
	 * @return \Generator<array>
	 */
	private function iterateSavedActionExpressions(ActivityData $activity): \Generator
	{
		foreach ([self::RELATIONS_PROPERTY, self::RULES_PROPERTY] as $propertyName)
		{
			$portRules = $activity->properties[$propertyName] ?? [];
			if (!is_array($portRules))
			{
				continue;
			}

			foreach ($portRules as $portRule)
			{
				$ruleCards = is_array($portRule) ? ($portRule['ruleCards'] ?? []) : [];
				foreach (is_array($ruleCards) ? $ruleCards : [] as $ruleCard)
				{
					$constructions = is_array($ruleCard) ? ($ruleCard['constructions'] ?? []) : [];
					foreach (is_array($constructions) ? $constructions : [] as $construction)
					{
						if (
							!is_array($construction)
							|| ($construction['type'] ?? null) !== ConstructionType::ACTION->value
						)
						{
							continue;
						}

						$expression = $construction['expression'] ?? [];
						if (is_array($expression))
						{
							yield $expression;
						}
					}
				}
			}
		}
	}
}
