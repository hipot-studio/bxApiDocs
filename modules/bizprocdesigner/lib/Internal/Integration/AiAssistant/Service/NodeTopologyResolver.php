<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Dto\NodeSettings;
use Bitrix\Bizproc\Activity\Enum\ActivityNodeType;
use Bitrix\Bizproc\Internal\Service\Container as BizprocContainer;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Catalog\NodeCatalogItemDtoFactory;

/** Resolves the same node topology used by the editor catalog. */
final class NodeTopologyResolver
{
	private ?Searcher $searcher = null;

	/** @var array<string, ActivityDescription|null> */
	private array $descriptionCache = [];

	public function describe(string $activityCode, ?string $presetId = null): ?ActivityDescription
	{
		$code = $this->getSearcher()->normalizeActivityCode($activityCode);
		if ($code === '')
		{
			return null;
		}

		$cacheKey = $code . '#' . ($presetId ?? '');
		if (!array_key_exists($cacheKey, $this->descriptionCache))
		{
			$description = $this->getSearcher()->searchByCode($code);
			if ($description !== null && $presetId !== null && $presetId !== '')
			{
				$description = $description->applyPresetById($presetId);
			}

			$this->descriptionCache[$cacheKey] = $description;
		}

		return $this->descriptionCache[$cacheKey];
	}

	public function resolveNodeSettings(?ActivityDescription $description): NodeSettings
	{
		$nodeSettings = $description?->getNodeSettings();
		if ($nodeSettings !== null)
		{
			return $nodeSettings;
		}

		return NodeCatalogItemDtoFactory::makeDefaultSettingsByType($this->resolveNodeType($description));
	}

	public function resolveNodeType(?ActivityDescription $description): ?string
	{
		if ($description === null)
		{
			return null;
		}

		$nodeType = $description->getNodeType();
		if ($nodeType !== null)
		{
			return $nodeType;
		}

		if (Searcher::declaresTriggerType($description->getType()))
		{
			return ActivityNodeType::TRIGGER->value;
		}

		return null;
	}

	private function getSearcher(): Searcher
	{
		return $this->searcher ??= BizprocContainer::instance()->getActivitySearcherService();
	}
}
