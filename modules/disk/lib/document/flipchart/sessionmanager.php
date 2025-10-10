<?php

namespace Bitrix\Disk\Document\Flipchart;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\SessionManager as BaseSessionManager;

final class SessionManager extends BaseSessionManager
{
	protected DocumentService|null $service = DocumentService::FlipChart;

	protected function buildFilter(): array
	{
		$filter = parent::buildFilter();
		if(!is_null($this->sessionType)){
			$filter['TYPE'] = $this->sessionType;
		}

		return $filter;
	}
}