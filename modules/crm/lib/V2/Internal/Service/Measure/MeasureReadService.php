<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Measure;

use Bitrix\Crm\V2\Internal\Integration\Catalog\Measure\MeasureGateway;
use Bitrix\Crm\V2\Internal\Model\Measure\Measure;
use Bitrix\Main\UI\PageNavigation;

/**
 * @internal
 */
class MeasureReadService
{
	public function __construct(
		private readonly MeasureGateway $gateway = new MeasureGateway(),
	)
	{
	}

	/**
	 * @param array<string, string> $sort
	 * @return Measure[]
	 */
	public function getList(array $sort = [], ?PageNavigation $pagination = null): array
	{
		return $this->gateway->getList($sort, $pagination);
	}

	public function getCount(): int
	{
		return $this->gateway->getCount();
	}
}
