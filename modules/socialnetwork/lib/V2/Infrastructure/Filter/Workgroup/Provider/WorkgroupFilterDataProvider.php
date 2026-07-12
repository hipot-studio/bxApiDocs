<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Filter\Workgroup\Provider;

use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\Internals\Counter\CounterFilter;
use Bitrix\Socialnetwork\V2\Infrastructure\Filter\Project\Provider\CommonProjectFilterDataProvider;

class WorkgroupFilterDataProvider extends CommonProjectFilterDataProvider
{
	public function __construct(
		string $gridId,
		private readonly bool $isCommonMode = true,
		private readonly int $currentUserId = 0,
		private readonly int $contextUserId = 0,
		string $filterId = '',
	)
	{
		parent::__construct($gridId, $filterId);
	}

	public function prepareFields(): array
	{
		$result = parent::prepareFields();

		if ($this->shouldShowCommonCounters())
		{
			$result['COMMON_COUNTERS'] = $this->createField('COMMON_COUNTERS', [
				'name' => Loc::getMessage('SONET_V2_FILTER_WORKGROUP_COLUMN_COMMON_COUNTERS'),
				'type' => 'list',
				'default' => false,
				'partial' => true,
			]);
		}

		return $result;
	}

	public function prepareFieldData($fieldID): ?array
	{
		if ($fieldID === 'COMMON_COUNTERS')
		{
			return [
				'items' => [
					CounterFilter::VALUE_LIVEFEED => Loc::getMessage('SONET_V2_FILTER_WORKGROUP_COUNTERS_LIST_VALUE_LIVEFEED'),
					CounterFilter::VALUE_TASKS => Loc::getMessage('SONET_V2_FILTER_WORKGROUP_COUNTERS_LIST_VALUE_TASKS'),
				],
			];
		}

		return parent::prepareFieldData($fieldID);
	}

	private function shouldShowCommonCounters(): bool
	{
		return (
			$this->isCommonMode
			&& $this->currentUserId > 0
			&& $this->currentUserId === $this->contextUserId
		);
	}
}
