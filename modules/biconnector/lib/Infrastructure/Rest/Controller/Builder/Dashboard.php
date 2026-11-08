<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Controller\Builder;

use Bitrix\BIConnector\Infrastructure\Rest\Action\Builder\DashboardDeleteCopyActions;
use Bitrix\BIConnector\Infrastructure\Rest\Action\Builder\DashboardReadActions;
use Bitrix\BIConnector\Infrastructure\Rest\Action\Builder\DashboardWriteActions;
use Bitrix\BIConnector\Infrastructure\Rest\Controller\AbstractBuilderController;
use Bitrix\BIConnector\Infrastructure\Rest\Dto\Builder\DashboardDto;
use Bitrix\Rest\V3\Attribute\DtoType;

#[DtoType(DashboardDto::class)]
final class Dashboard extends AbstractBuilderController
{
	use DashboardReadActions;
	use DashboardWriteActions;
	use DashboardDeleteCopyActions;
}
