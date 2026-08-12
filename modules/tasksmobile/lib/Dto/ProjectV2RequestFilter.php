<?php

declare(strict_types=1);

namespace Bitrix\TasksMobile\Dto;

use Bitrix\Mobile\Dto\Dto;

final class ProjectV2RequestFilter extends Dto
{
	public string $searchString = '';
	public string $presetId = '';
	public string $counterFilterId = '';
}
