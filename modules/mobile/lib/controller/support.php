<?php

namespace Bitrix\Mobile\Controller;

use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\Controller;
use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Feature\SupportFeature;
use Bitrix\Mobile\Provider\SupportProvider;

class Support extends Controller
{
	public function configureActions(): array
	{
		return [
			'getBotId' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
		];
	}

	public function getBotIdAction()
	{
		$supportProvider = new SupportProvider();

		return [
			'botId' => $supportProvider->getBotId(),
			// the profile menu shows support by the bot AND this switch; reported beside the bot so
			// that callers who need only the bot keep the answer they had before
			'isSupportActive' => Feature::isEnabled(SupportFeature::class),
		];
	}
}
