<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Tracking\Ad;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking\AdTreeNodeDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Tracking\AdTreeRequest;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingAccessDeniedException;
use Bitrix\Crm\V2\Internal\Service\Tracking\TrackingAccess;
use Bitrix\Crm\V2\Public\Provider\Tracking\Ad;
use Bitrix\Main\Request;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\McpTool;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Interaction\Response\TailResponse;

#[DtoType(AdTreeNodeDto::class)]
final class Tree extends RestController
{
	public function __construct(
		?Request $request = null,
		private readonly TrackingAccess $access = new TrackingAccess(),
		private readonly Ad\Tree $tree = new Ad\Tree(),
	)
	{
		parent::__construct($request);
	}

	#[McpTool(toolSetCode: 'crm_rest_v3_tracking')]
	public function listAction(AdTreeRequest $request): TailResponse
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
		try
		{
			$page = $this->tree->list(
				$userId,
				$request->getSourceId(),
				$request->getPeriod(),
				$request->getLevel(),
				$request->getParentId(),
				$request->getPagination(),
			);
		}
		catch (TrackingAccessDeniedException)
		{
			throw new AccessDeniedException();
		}
		catch (\DomainException $exception)
		{
			Account::refuse($exception);
		}

		$items = new DtoCollection(AdTreeNodeDto::class);
		foreach ($page['items'] as $node)
		{
			$item = new AdTreeNodeDto();
			foreach (AdTreeNodeDto::OUTPUT_FIELDS as $field)
			{
				$item->{$field} = match ($field) {
					'leads', 'deals', 'successDeals' => (int)$node[$field],
					'cost', 'income', 'roi', 'ctr', 'cpc' => (float)$node[$field],
					default => $node[$field],
				};
			}
			$items->add($item);
		}

		return new TailResponse($items, $page['next'] !== null, 'id', $page['next']);
	}
}
