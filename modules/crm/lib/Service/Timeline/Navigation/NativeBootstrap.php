<?php

namespace Bitrix\Crm\Service\Timeline\Navigation;

use Bitrix\Crm\Component\EntityDetails\Config\ScopeIdResolver;
use Bitrix\Crm\Filter\Filter;
use Bitrix\Crm\Filter\TimelineDataProvider;
use Bitrix\Crm\Filter\TimelineSettings;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Repository;
use Bitrix\Crm\Service\Timeline\Repository\Query;
use Bitrix\Main\UI\Filter\Options;

final class NativeBootstrap
{
	public function load(ItemIdentifier $owner, string $viewId): array
	{
		$context = new Context($owner, Context::DESKTOP);
		$scopeId = (new ScopeIdResolver($owner->getEntityTypeId(), $owner->getCategoryId()))
			->setUserId($context->getUserId())
			->getScopeId('timeline_history')
		;
		$filter = new Filter(
			$scopeId,
			new TimelineDataProvider(new TimelineSettings([
				'ID' => $scopeId,
				'entityId' => $owner->getEntityId(),
				'entityTypeId' => $owner->getEntityTypeId(),
			])),
		);
		$descriptors = [];
		foreach ($filter->getFields() as $field)
		{
			$descriptors[] = $field->toArray();
		}
		$options = new Options($scopeId, [], '');
		$nativeValues = $options->getFilter($descriptors);
		$filter->prepareListFilterParams($nativeValues);
		$repository = new Repository($context);
		$history = $repository->getNavigationCandidatesPage((new Query())->setFilter($nativeValues)->setLimit(10));
		$fixed = $repository->getNavigationCandidatesPage(
			(new Query())->setSearchForFixedItems(true)->setLimit(20),
		);
		if (!$history->isSuccess() || !$fixed->isSuccess())
		{
			throw new \RuntimeException('Unable to bootstrap native timeline');
		}

		return [
			'available' => (new Availability())->isAvailable($owner->getEntityTypeId(), $owner->getEntityId()),
			'mode' => 'native', 'scopeId' => $scopeId,
			'native' => (new NativeFilterAdapter())->export(
				$nativeValues,
				(string)($nativeValues['FIND'] ?? ''),
			),
			'items' => $history->getItems(), 'fixedItems' => $fixed->getItems(),
			'navigation' => [
				'OFFSET_TIMESTAMP' => $history->getOffsetTime()?->format('Y-m-d H:i:s') ?? '',
				'OFFSET_ID' => $history->getOffsetId(),
			],
		];
	}
}
