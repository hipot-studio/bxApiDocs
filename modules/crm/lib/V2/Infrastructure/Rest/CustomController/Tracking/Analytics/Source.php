<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Tracking\Analytics;

use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Tracking\Ad\Account;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking\SourceAnalyticsDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Tracking\SourceAnalyticsRequest;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingAccessDeniedException;
use Bitrix\Crm\V2\Internal\Service\Tracking\TrackingAccess;
use Bitrix\Crm\V2\Public\Provider\Tracking\Ad\SourceAnalytics;
use Bitrix\Main\Request;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\McpTool;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

#[DtoType(SourceAnalyticsDto::class)]
final class Source extends RestController
{
	public function __construct(
		?Request $request = null,
		private readonly TrackingAccess $access = new TrackingAccess(),
		private readonly SourceAnalytics $analytics = new SourceAnalytics(),
	)
	{
		parent::__construct($request);
	}

	#[McpTool(toolSetCode: 'crm_rest_v3_tracking')]
	public function listAction(SourceAnalyticsRequest $request): ListResponse
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		try
		{
			$this->access->assertCanRead($userId);
		}
		catch (TrackingAccessDeniedException)
		{
			throw new AccessDeniedException();
		}

		$sourceIds = $request->getSourceIds();
		$period = $request->getPeriod();
		$pagination = $request->getPagination();

		try
		{
			$rows = $this->analytics->list($userId, $sourceIds, $period);
		}
		catch (TrackingAccessDeniedException)
		{
			throw new AccessDeniedException();
		}
		catch (\DomainException $exception)
		{
			Account::refuse($exception);
		}

		return $this->responsePage($rows, $pagination);
	}

	/** @param array{limit: int, offset: int} $pagination */
	private function responsePage(array $rows, array $pagination): ListResponse
	{
		$items = new DtoCollection(SourceAnalyticsDto::class);
		foreach (array_slice($rows, $pagination['offset'], $pagination['limit']) as $row)
		{
			$item = new SourceAnalyticsDto();
			foreach (SourceAnalyticsDto::OUTPUT_FIELDS as $field)
			{
				$item->{$field} = $row[$field];
			}
			$items->add($item);
		}

		return new ListResponse($items);
	}
}
