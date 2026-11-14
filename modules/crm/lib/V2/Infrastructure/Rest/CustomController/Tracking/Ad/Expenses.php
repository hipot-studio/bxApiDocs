<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Tracking\Ad;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking\AdOperationDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Tracking\AdImportRequest;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingAccessDeniedException;
use Bitrix\Crm\V2\Internal\Service\Tracking\TrackingAccess;
use Bitrix\Crm\V2\Public\Command\Tracking\Ad\StartImport;
use Bitrix\Crm\V2\Public\Provider\Tracking\Ad\ImportStatus;
use Bitrix\Main\Request;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\McpTool;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Crm\V2\Infrastructure\Rest\Response\Tracking\AdReportProgressResponse;

#[DtoType(AdOperationDto::class)]
final class Expenses extends RestController
{
	public function __construct(
		?Request $request = null,
		private readonly TrackingAccess $access = new TrackingAccess(),
		private readonly StartImport $start = new StartImport(),
		private readonly ImportStatus $status = new ImportStatus(),
	)
	{
		parent::__construct($request);
	}

	#[McpTool(toolSetCode: 'crm_rest_v3_tracking')]
	public function startAction(AdImportRequest $request): AdReportProgressResponse
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		$this->assertAccess($userId, true);
		try
		{
			$status = $this->start->execute(
				$userId,
				$request->getSourceId(),
				$request->getPeriod(),
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
		return new AdReportProgressResponse($status['complete'], $status['label']);
	}

	#[McpTool(toolSetCode: 'crm_rest_v3_tracking')]
	public function getStatusAction(AdImportRequest $request): AdReportProgressResponse
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		$this->assertAccess($userId, false);
		try
		{
			$status = $this->status->get($userId, $request->getSourceId(), $request->getPeriod());
		}
		catch (TrackingAccessDeniedException)
		{
			throw new AccessDeniedException();
		}
		catch (\DomainException $exception)
		{
			Account::refuse($exception);
		}
		return new AdReportProgressResponse($status['complete'], $status['label']);
	}

	private function assertAccess(int $userId, bool $modify): void
	{
		try
		{
			if ($modify)
			{
				$this->access->assertCanModify($userId);
			}
			else
			{
				$this->access->assertCanRead($userId);
			}
		}
		catch (TrackingAccessDeniedException)
		{
			throw new AccessDeniedException();
		}
	}
}
