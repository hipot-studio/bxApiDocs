<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Integration\Ui;

use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Integration\UI\EntitySelector\UserProvider;
use Bitrix\Timeman\V2\Internal\DI\Container;

if (!Loader::includeModule('socialnetwork'))
{
	return;
}

final class FullReportUserProvider extends UserProvider
{
	protected const ENTITY_ID = 'timeman-full-report-user';

	public function isAvailable(): bool
	{
		return (
			is_object($GLOBALS['USER'])
			&& $GLOBALS['USER']->isAuthorized()
			&& self::getCurrentUserId() > 0
			&& parent::isAvailable()
		);
	}

	protected function prepareOptions(array $options = []): void
	{
		$currentUserId = self::getCurrentUserId();
		$accessibleUserIds = ($currentUserId > 0)
			? Container::getInstance()
				->getFullReportUserService()
				->getUserIdsAccessibleToRead($currentUserId)
			: [];

		$userOptions = is_array($options['userOptions'] ?? null) ? $options['userOptions'] : [];
		unset($options['userOptions'], $options['userId']);

		parent::prepareOptions([
			...$userOptions,
			...$options,
			'userId' => $accessibleUserIds,
		]);
	}
}
