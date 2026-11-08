<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Controller\Builder;

use Bitrix\BIConnector\Infrastructure\Rest\Action\Builder\ChartReadActions;
use Bitrix\BIConnector\Infrastructure\Rest\Action\Builder\ChartWriteActions;
use Bitrix\BIConnector\Infrastructure\Rest\Controller\AbstractBuilderController;
use Bitrix\BIConnector\Infrastructure\Rest\Dto\Builder\ChartDto;
use Bitrix\Rest\V3\Attribute\DtoType;

#[DtoType(ChartDto::class)]
final class Chart extends AbstractBuilderController
{
	use ChartReadActions;
	use ChartWriteActions;
}
