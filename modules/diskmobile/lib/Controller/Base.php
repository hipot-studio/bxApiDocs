<?php

namespace Bitrix\DiskMobile\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\ActionFilter;

class Base extends Controller
{
	protected function getDefaultPreFilters(): array
	{
		return [
			new ActionFilter\Authentication(),
			new ActionFilter\Csrf(),
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			new ActionFilter\Scope(ActionFilter\Scope::NOT_REST),
		];
	}

	protected function trackedToItem($trackedItem): array
	{
		$trackedObject = $trackedItem['trackedObject'];

		return array_merge(
			[
				'trackedId' => (int)$trackedObject['id'],
			],
			$trackedObject['file'],
			[
				'links' => [
					'download' => $trackedObject['links']['download']->getUri(),
					'preview' => $trackedObject['links']['preview']->getUri(),
				]
			],
		);
	}
}