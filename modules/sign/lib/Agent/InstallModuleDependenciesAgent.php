<?php

namespace Bitrix\Sign\Agent;

use Throwable;

final class InstallModuleDependenciesAgent
{
	public static function run(): string
	{
		RegisterModuleDependences(
			FROM_MODULE_ID: 'rest',
			MESSAGE_ID:     'OnRestServiceBuildDescription',
			TO_MODULE_ID:   'sign',
			TO_CLASS:       \Bitrix\Sign\Rest\B2e\CompanyProvider::class,
			TO_METHOD:      'onRestServiceBuildDescription',
		);

		RegisterModuleDependences(
			FROM_MODULE_ID: 'rest',
			MESSAGE_ID:     'OnRestServiceBuildDescription',
			TO_MODULE_ID:   'sign',
			TO_CLASS:       \Bitrix\Sign\Rest\B2e\Document::class,
			TO_METHOD:      'onRestServiceBuildDescription',
		);

		return '';
	}

}
