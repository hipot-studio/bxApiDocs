<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Application;

class AppFactory
{
	public static function createLocal(
		?string $clientId,
		string $handlerUrl,
		array $scope,
		string $title,
		string $installUrl,
		bool $mobile,
		?array $menuTitles = null,
		?AppAttributeCollection $externalAttributes = null,
	): App
	{
		return self::createLocalOriginApp(
			$clientId, $handlerUrl, $scope, $title, $installUrl, $mobile, $menuTitles, $externalAttributes,
		);
	}

	public static function createPersonal(
		?string $clientId,
		string $handlerUrl,
		array $scope,
		string $title,
		string $installUrl,
		bool $mobile,
		?array $menuTitles = null,
		?AppAttributeCollection $externalAttributes = null,
	): App
	{
		return self::createLocalOriginApp(
			$clientId, $handlerUrl, $scope, $title, $installUrl, $mobile, $menuTitles, $externalAttributes,
		);
	}

	private static function createLocalOriginApp(
		?string $clientId,
		string $handlerUrl,
		array $scope,
		string $title,
		string $installUrl,
		bool $mobile,
		?array $menuTitles,
		?AppAttributeCollection $externalAttributes,
	): App
	{
		$application = new App(
			clientId: $clientId,
			code: $clientId,
			url: $handlerUrl,
			scope: $scope,
			origin: AppOrigin::Local,
			urlInstall: $installUrl,
			appName: $title,
			mobile: $mobile,
		);

		if ($externalAttributes?->isEmpty() === false)
		{
			$application->setExternalAttributes($externalAttributes);
		}

		if (!empty($menuTitles))
		{
			foreach ($menuTitles as $languageID => $title)
			{
				$application->setLang($languageID, $title);
			}
		}

		return $application;
	}
}
