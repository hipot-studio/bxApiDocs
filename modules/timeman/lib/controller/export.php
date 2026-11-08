<?php
namespace Bitrix\Timeman\Controller;

use Bitrix\Main;
use Bitrix\Timeman\V2\Infrastructure\Controller\ActionFilter\ToolEnabledFilter;

class Export extends Main\Controller\Export
{
	protected $module = 'timeman';

	protected function getDefaultPreFilters()
	{
		return [
			new ToolEnabledFilter(),
			...parent::getDefaultPreFilters(),
		];
	}
}
