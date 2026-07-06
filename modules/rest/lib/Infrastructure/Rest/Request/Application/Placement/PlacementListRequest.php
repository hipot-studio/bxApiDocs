<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Request\Application\Placement;

use Bitrix\Rest\V3\Interaction\Request\Request;

class PlacementListRequest extends Request
{
	public ?string $clientId = null;
	public ?string $scope = null;
	public bool $showAll = false;
}
