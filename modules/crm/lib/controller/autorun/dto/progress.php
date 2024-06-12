<?php

namespace Bitrix\Crm\Controller\Autorun\Dto;

use Bitrix\Crm\Dto\Dto;

final class Progress extends Dto
{
	public int $lastId = 0;
	public int $processedCount = 0;
	public ?int $totalCount = null;
}
