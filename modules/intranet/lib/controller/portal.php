<?php

namespace Bitrix\Intranet\Controller;


use Bitrix\Intranet\ActionFilter\UserType;

class Portal extends \Bitrix\Main\Engine\Controller
{
	protected function getDefaultPreFilters(): array
	{
		return array_merge(
			parent::getDefaultPreFilters(),
			[
				new UserType(['employee']),
			]
		);
	}

	public function getLogoAction(): array
	{
		$settings = \Bitrix\Intranet\Portal::getInstance()->getSettings();
		$result['title'] = $settings->getTitle();
		$result['logo'] = $settings->getLogo();
		$result['logo24'] = $settings->getLogo24();

		return $result;
	}
}