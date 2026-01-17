<?php

namespace Bitrix\BizprocDesigner\Infrastructure\Controller;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
use Bitrix\Bizproc\Activity\Enum\ActivityNodeType;
use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Bizproc\Public\Entity\Document\Workflow;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Activities;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Catalog\NodeCatalogItemDtoFactory;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Loader;

class Catalog extends JsonController
{
	protected function init()
	{
		parent::init();
		Loader::requireModule('bizproc');
	}

	public function getAction(): ?array
	{
		/** @var Searcher $searcher */
		$searcher = \Bitrix\Main\DI\ServiceLocator::getInstance()->get('bizproc.runtime.activitysearcher.searcher');

		$documentType = Workflow::getComplexType();

		$activities =
			$searcher->searchByType(ActivityType::NODE->value, $documentType)
				->addCollection($searcher->searchByType(ActivityType::TRIGGER->value, $documentType))
				//->computeDescriptionFilter($documentType)
				->filter(static fn(ActivityDescription $description) => !$description->getExcluded())
				->sort()
		;

		return $this->transformActivities($activities);
	}

	private function transformActivities(Activities $activities): array
	{
		$groups = ActivityGroup::toArray();

		/** @var ActivityDescription $activityData */
		foreach ($activities as $activityData)
		{
			$activityGroups = $activityData->getGroups();

			foreach ($activityGroups as $activityGroup)
			{
				if ($activityData->getName() !== '')
				{
					$groups[$activityGroup]['items'][] = $activityData;
				}

				foreach ($activityData->getPresets() ?? [] as $preset)
				{
					$groups[$activityGroup]['items'][] = $activityData->applyPreset($preset);
				}
			}
		}

		$groups = array_values(array_filter($groups, static fn($group) => !empty($group['items'])));

		$groups = array_map(
			static function ($group) {
				$nodeCatalogItemDtoFactory = new NodeCatalogItemDtoFactory();
				usort(
					$group['items'],
					static fn($a, $b) => ($a->getSort() ?? INF) <=> ($b->getSort() ?? INF)
				);

				$group['items'] = array_map(
					static fn($activityDescription) => $nodeCatalogItemDtoFactory->createByDescription($activityDescription),
					$group['items']
				);

				return $group;
			},
			$groups
		);

		return ['groups' => $groups];
	}



	private function transformTriggers(Activities $triggers): array
	{
		$nodeCatalogItemDtoFactory = new NodeCatalogItemDtoFactory();

		$result = [];

		/**
		 * @var string $activityId
		 * @var ActivityDescription $activityData
		 */
		foreach ($triggers as $activityData)
		{
			$activityData->setNodeType(ActivityNodeType::TRIGGER->value);

			if ($activityData->getName() !== '')
			{
				$result[] = $nodeCatalogItemDtoFactory->createByDescription($activityData);
			}

			if ($activityData->getPresets())
			{
				foreach ($activityData->getPresets() as $preset)
				{
					$presetActivityData = $activityData->applyPreset($preset);
					if ($presetActivityData->getName() !== '')
					{
						$result[] = $nodeCatalogItemDtoFactory->createByDescription($activityData->applyPreset($preset));
					}
				}
			}
		}

		return $result;
	}
}
