<?php
namespace PHPSTORM_META
{
	registerArgumentsSet('bitrix_messageservice_serviceLocator_codes',
		'messageservice.public.ui.factory',
	);

	expectedArguments(\Bitrix\Main\DI\ServiceLocator::get(), 0, argumentsSet('bitrix_messageservice_serviceLocator_codes'));

	override(\Bitrix\Main\DI\ServiceLocator::get(0), map([
		'messageservice.public.ui.factory' => \Bitrix\MessageService\Public\UI\Factory::class,
	]));
}
