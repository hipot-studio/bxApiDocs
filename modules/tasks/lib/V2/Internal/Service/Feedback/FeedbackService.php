<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Feedback;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class FeedbackService
{
	public function __construct(
		private readonly FeedbackFormMatrix $matrix = new FeedbackFormMatrix(),
	)
	{
	}

	public function getParams(FeedbackSource $source): array
	{
		$region = Application::getInstance()->getLicense()->getRegion();
		$lang = LANGUAGE_ID;

		$row = $this->matrix->matchByRegion($region, $lang)
			?? $this->matrix->matchByLang($lang)
			?? $this->matrix->fallbackWestEn();

		$form = [
			'zones' => [$region],
			'id' => $row->id,
			'lang' => $row->lang,
			'sec' => $row->sec,
		];

		return [
			'id' => 'tasks_feedback',
			'forms' => [$form],
			'defaultForm' => ['id' => $row->id, 'lang' => $row->lang, 'sec' => $row->sec],
			'presets' => $this->getPresets() + ['source' => $source->value],
			'title' => Loc::getMessage('TASKS_V2_FEEDBACK_SLIDER_TITLE'),
			'portalUri' => $row->portalUri,
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
