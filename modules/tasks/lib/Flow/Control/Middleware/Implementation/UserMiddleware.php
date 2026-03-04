<?php

namespace Bitrix\Tasks\Flow\Control\Middleware\Implementation;

use Bitrix\Main\SystemException;
use Bitrix\Tasks\Flow\AbstractCommand;
use Bitrix\Tasks\Flow\Control\Exception\MiddlewareException;
use Bitrix\Tasks\Flow\Control\Middleware\AbstractMiddleware;
use Bitrix\Tasks\Flow\Internal\DI\Container;
use Bitrix\Tasks\Flow\Provider\UserStatusProvider;
use Bitrix\Tasks\Internals\Log\Logger;

class UserMiddleware extends AbstractMiddleware
{
	private UserStatusProvider $userProvider;

	public function __construct()
	{
		$this->userProvider = Container::getInstance()->get(UserStatusProvider::class);
	}

	/**
	 * @throws MiddlewareException
	 */
	public function handle(AbstractCommand $request)
	{
		$userIds = $request->getUserIdList();

		try
		{
			$existsUserIds = $this->userProvider->filterExists($userIds);
		}
		catch (SystemException $e)
		{
			Logger::logThrowable($e);
			throw new MiddlewareException("Error");
		}

		$notExistsIds = array_diff($userIds, $existsUserIds);
		if (!empty($notExistsIds))
		{
			$firstUserIds = reset($notExistsIds);

			throw new MiddlewareException("User {$firstUserIds} doesn't exists");
		}

		return parent::handle($request);
	}
}
