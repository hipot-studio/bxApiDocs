<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\CustomTemplate;

use Bitrix\Crm\MessageSender\Availability;
use Bitrix\Crm\MessageSender\UI\Editor\Scene\BaseScene;
use Bitrix\Crm\MessageSender\UI\Factory;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Localization\Loc;
use Bitrix\MessageService\Public\Dto\CustomTemplate\CustomTemplateSelectorItem;
use Bitrix\MessageService\Public\Provider\CustomTemplate\Zone\AbstractZoneProvider;
use Bitrix\MessageService\Public\Type\CustomTemplate\BindingDescription;
use Bitrix\MessageService\Public\Type\CustomTemplate\FilterSceneOption;
use Bitrix\MessageService\Public\Type\CustomTemplate\FilterSubjectOption;
use Bitrix\MessageService\Public\Type\CustomTemplate\ReadableFilterOptions;
use Bitrix\MessageService\Public\Type\CustomTemplate\ReadableScope;
use Bitrix\MessageService\Public\Type\CustomTemplate\SelectorBadge;
use Bitrix\MessageService\Public\Type\CustomTemplate\TemplateBinding;
use Bitrix\MessageService\Public\UI\MessageEditor\Context;

final class CrmZoneProvider extends AbstractZoneProvider
{
	/** @var array<int, list<array{entityTypeId: int, categoryId: int}>> per-request memo of readable targets, keyed by userId */
	private array $readableTargetsCache = [];

	public function getId(): string
	{
		return CrmZone::ID;
	}

	/**
	 * Build the canonical TemplateBinding for a CRM scene.
	 *
	 * @param array<string, mixed> $sceneRawContext
	 */
	public function buildBinding(string $scene, array $sceneRawContext): TemplateBinding
	{
		$targetId = CrmTemplateTargetId::fromEditorContext(new Context(customData: $sceneRawContext));

		return new TemplateBinding(CrmZone::ID, $scene, $targetId->toString());
	}

	public function isValidScene(string $scene): bool
	{
		return Factory::getInstance()->getScene($scene) !== null;
	}

	public function describeBinding(TemplateBinding $binding): BindingDescription
	{
		$scene = Factory::getInstance()->getScene($binding->scene);

		return new BindingDescription(
			sceneLabel: $scene instanceof BaseScene ? $scene->getName() : '',
			targetLabel: $this->buildTargetLabel($binding),
		);
	}

	/**
	 * @return list<string>
	 */
	public function getEditorBindingLabels(TemplateBinding $binding): array
	{
		$description = $this->describeBinding($binding);
		$scene = Factory::getInstance()->getScene($binding->scene);

		$labels = [];
		if ($description->targetLabel !== '')
		{
			$labels[] = $description->targetLabel;
		}

		if (
			$scene instanceof BaseScene
			&& $scene->shouldShowInTemplateBindingLabels()
			&& $description->sceneLabel !== ''
		)
		{
			$labels[] = $description->sceneLabel;
		}

		return $labels;
	}

	public function fillSelectorItem(
		CustomTemplateSelectorItem $item,
		TemplateBinding $currentBinding,
	): CustomTemplateSelectorItem
	{
		$itemBinding = $item->binding;

		$badges = [];
		$hasForeignScene = $itemBinding->scene !== $currentBinding->scene;
		$hasForeignTarget = $itemBinding->targetId !== $currentBinding->targetId;
		$description = $hasForeignScene || $hasForeignTarget ? $this->describeBinding($itemBinding) : null;

		if ($hasForeignScene)
		{
			$sceneLabel = $description?->sceneLabel ?? '';
			if ($sceneLabel !== '')
			{
				$badges[] = new SelectorBadge($sceneLabel);
			}
		}
		if ($hasForeignTarget)
		{
			$targetLabel = $description?->targetLabel ?? '';
			if ($targetLabel !== '')
			{
				$badges[] = new SelectorBadge($targetLabel);
			}
		}

		return $badges === [] ? $item : $item->withBadges($badges);
	}

	public function canManageTemplates(int $userId): bool
	{
		return Container::getInstance()
			->getUserPermissions($userId)
			->messageSender()
			->canSendFromSomeItemsInCrmOrAutomatedSolutions()
		;
	}

	public function canReadTemplates(int $userId, TemplateBinding $target): bool
	{
		return $this->canSendFromBinding($userId, $target);
	}

	public function getReadableScope(int $userId): ReadableScope
	{
		if (Container::getInstance()->getUserPermissions($userId)->isCrmAdmin())
		{
			return ReadableScope::all();
		}

		$resolved = $this->resolveReadableTargets($userId);
		if ($resolved['readable'] === [])
		{
			return ReadableScope::none();
		}

		// Collapse a fully-privileged user to all(): the gate then carries no IN list at all.
		if (count($resolved['readable']) === $resolved['total'])
		{
			return ReadableScope::all();
		}

		$targetIds = array_map(
			static fn (array $target): string => $target['entityTypeId'] . ':' . $target['categoryId'],
			$resolved['readable'],
		);

		return ReadableScope::targets($targetIds);
	}

	public function getReadableFilterOptions(int $userId): ReadableFilterOptions
	{
		$scenes = [];
		foreach (Factory::getInstance()->getScenes() as $scene)
		{
			$scenes[] = new FilterSceneOption($scene->getId(), $scene->getName());
		}

		$subjects = [];
		foreach ($this->resolveReadableTargets($userId)['readable'] as $target)
		{
			$targetId = $target['entityTypeId'] . ':' . $target['categoryId'];
			$subjects[] = new FilterSubjectOption(
				$targetId,
				$this->buildTargetLabel(new TemplateBinding(CrmZone::ID, '', $targetId)),
			);
		}

		return new ReadableFilterOptions($scenes, $subjects);
	}

	/**
	 * Enumerate every readable (entityTypeId, categoryId) candidate from CRM configuration and
	 * the count of all candidates, applying the same messageSender predicate as
	 * {@see self::canReadTemplates()}. Memoized per request, keyed by user.
	 *
	 * Types come from {@see TypesMap::getFactories()}, which already folds in both static and
	 * dynamic types — enumerating dynamic types again would double-count them. Categories of a
	 * type are its {@see Factory::getCategories()} when supported, otherwise the single
	 * no-category candidate (categoryId 0).
	 *
	 * @return array{readable: list<array{entityTypeId: int, categoryId: int}>, total: int}
	 */
	private function resolveReadableTargets(int $userId): array
	{
		if (isset($this->readableTargetsCache[$userId]))
		{
			return $this->readableTargetsCache[$userId];
		}

		$perms = Container::getInstance()->getUserPermissions($userId)->messageSender();

		$readable = [];
		$total = 0;
		foreach (Container::getInstance()->getTypesMap()->getFactories() as $factory)
		{
			$entityTypeId = $factory->getEntityTypeId();

			// A valid SMS target is a single predicate: scope and filter must not offer types with no editor.
			if (!Availability::isAvailableForEntityType($entityTypeId))
			{
				continue;
			}

			// Discriminator is isCategoriesSupported(), not isCategoriesEnabled(): a category-backed
			// type (Dynamic SPA) keys its items and stored permissions off real category ids even when
			// the funnel UI is off (isCategoriesEnabled() === false). This mirrors how the targetId
			// category is resolved in ItemIdentifier::getCategoryId(), so scope and bindings stay aligned.
			$categoryIds = [];
			if ($factory->isCategoriesSupported())
			{
				foreach ($factory->getCategories() as $category)
				{
					$categoryId = $category->getId();
					if ($categoryId !== null)
					{
						$categoryIds[] = $categoryId;
					}
				}
			}
			else
			{
				$categoryIds[] = 0;
			}

			foreach ($categoryIds as $categoryId)
			{
				$total++;
				$granted = $factory->isCategoriesSupported()
					? $perms->canSendFromItemsInCategory($entityTypeId, $categoryId)
					: $perms->canSendFromItems($entityTypeId)
				;
				if ($granted)
				{
					$readable[] = ['entityTypeId' => $entityTypeId, 'categoryId' => $categoryId];
				}
			}
		}

		return $this->readableTargetsCache[$userId] = ['readable' => $readable, 'total' => $total];
	}

	public function canAddTemplate(int $userId, TemplateBinding $target): bool
	{
		return $this->canSendFromBinding($userId, $target);
	}

	public function canUpdateTemplate(int $userId, TemplateBinding $target): bool
	{
		return $this->canSendFromBinding($userId, $target);
	}

	public function canDeleteTemplate(int $userId, TemplateBinding $target): bool
	{
		return $this->canSendFromBinding($userId, $target);
	}

	/**
	 * Parse the canonical "{entityTypeId}:{categoryId}" targetId into its parts.
	 *
	 * @return array{entityTypeId: int, categoryId: int}|null
	 */
	public function parseTargetId(string $targetId): ?array
	{
		return CrmTemplateTargetId::fromString($targetId)?->toArray();
	}

	private function canSendFromBinding(int $userId, TemplateBinding $binding): bool
	{
		$parsed = CrmTemplateTargetId::fromString($binding->targetId);
		if ($parsed === null)
		{
			return false;
		}

		// Validity guard before permission checks: a target type with no editor is closed for read/CUD.
		if (!Availability::isAvailableForEntityType($parsed->getEntityTypeId()))
		{
			return false;
		}

		$perms = Container::getInstance()->getUserPermissions($userId)->messageSender();
		$entityTypeId = $parsed->getEntityTypeId();
		$categoryId = $parsed->getCategoryId();
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return false;
		}

		// isCategoriesSupported() (not isCategoriesEnabled()): the binding's categoryId is the item's
		// real category id (ItemIdentifier), and permissions are stored per category id even when the
		// funnel UI is off. The categoryId === 0 branch is only for non-category-backed types.
		if ($factory->isCategoriesSupported())
		{
			return $perms->canSendFromItemsInCategory($entityTypeId, $categoryId);
		}

		return $categoryId === 0 && $perms->canSendFromItems($entityTypeId);
	}

	private function buildTargetLabel(TemplateBinding $binding): string
	{
		$parsed = CrmTemplateTargetId::fromString($binding->targetId);
		if ($parsed === null)
		{
			return '';
		}

		$entityTypeId = $parsed->getEntityTypeId();
		$categoryId = $parsed->getCategoryId();
		$entityName = (string)\CCrmOwnerType::GetDescription($entityTypeId);

		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return $entityName;
		}

		if ($categoryId === 0 || !$factory->isCategoriesEnabled())
		{
			return $entityName;
		}

		$category = $factory->getCategory($categoryId);
		if ($category === null || $category->getIsDefault())
		{
			return $entityName;
		}

		if ($category->getIsSystem())
		{
			return $category->getSingleNameIfPossible();
		}

		return (string)Loc::getMessage('CRM_MS_CUSTOM_TEMPLATE_TARGET_LABEL', [
			'#ENTITY_NAME#' => $entityName,
			'#CATEGORY_NAME#' => $category->getName(),
		]);
	}
}
