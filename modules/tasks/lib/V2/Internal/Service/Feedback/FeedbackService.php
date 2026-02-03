<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Feedback;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class FeedbackService
{
	public function getParams(): array
	{
		return [
			'id' => 'tasks_feedback',
			'forms' => [
				['zones' => ['ru', 'by', 'kz', 'uz'], 'id' => '102','lang' => 'ru', 'sec' => 'xsbhvf'],
				['zones' => ['de'], 'id' => '108','lang' => 'de', 'sec' => '9fgkgr'],
				['zones' => ['es'], 'id' => '110','lang' => 'la', 'sec' => 'hj410g'],
				['zones' => ['com.br'], 'id' => '112','lang' => 'br', 'sec' => 'fcujin'],
			],
			'defaultForm' => ['id' => '106','lang' => 'en', 'sec' => 'etwdsc'],
 			'presets' => $this->getPresets(),
		];
	}

	public function getTitle(): string
	{
		return Loc::getMessage('TASKS_V2_FEEDBACK_TITLE');
	}

	private function getPresets(): array
	{
		return [
			'fromDomain' => defined('BX24_HOST_NAME') ? BX24_HOST_NAME : Option::get('main', 'server_name', ''),
			'b24_plan' => Loader::includeModule('bitrix24') ? \CBitrix24::getLicenseType() : '',
			'c_name' => CurrentUser::get()->getFullName(),
		];
	}
}
