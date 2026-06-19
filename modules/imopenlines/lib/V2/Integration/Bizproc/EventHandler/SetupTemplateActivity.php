<?php

namespace Bitrix\ImOpenLines\V2\Integration\Bizproc\EventHandler;

use Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity\EntitySelector\SelectorConfiguration;
use Bitrix\ImOpenLines\V2\Feature\AiOpenLinesOperatorAgentFeature;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

final class SetupTemplateActivity
{
	public static function onProvideSelectors(Event $event): EventResult
	{
		if (
			!Loader::includeModule('im')
			|| !Loader::includeModule('imbot')
			|| !Loader::includeModule('imopenlines')
			|| !Loader::includeModule('bizproc')
			|| !ServiceLocator::getInstance()->get(AiOpenLinesOperatorAgentFeature::class)?->isAvailable()
		)
		{
			return new EventResult(EventResult::ERROR);
		}

		$selectors = [
			new SelectorConfiguration(
				id: 'imopenlines-queue-provider',
				title: Loc::getMessage('IMOL_INTEGRATION_BIZPROC_EVENT_HANDLER_SETUP_TEMPLATE_ACTIVITY_QUEUE_PROVIDER'),
				dialogOptions: [
					'entities' => [
						[
							'id' => 'imopenlines-queue',
							'dynamicLoad' => true,
							'dynamicSearch' => true,
						],
					],
				],
			),
		];

		return new EventResult(EventResult::SUCCESS, [
			'selectors' => $selectors,
		]);
	}
}
