<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Controller\Builder;

use Bitrix\BIConnector\Infrastructure\Rest\Action\Builder\DatasetReadActions;
use Bitrix\BIConnector\Infrastructure\Rest\Action\Builder\DatasetWriteActions;
use Bitrix\BIConnector\Infrastructure\Rest\Controller\AbstractBuilderController;
use Bitrix\BIConnector\Infrastructure\Rest\Dto\Builder\DatasetDto;
use Bitrix\Rest\V3\Attribute\DtoType;

#[DtoType(DatasetDto::class)]
final class Dataset extends AbstractBuilderController
{
	use DatasetReadActions;
	use DatasetWriteActions;
}
