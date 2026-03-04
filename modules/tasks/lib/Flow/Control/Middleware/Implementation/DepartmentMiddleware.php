<?php

namespace Bitrix\Tasks\Flow\Control\Middleware\Implementation;

use Bitrix\Tasks\Flow\AbstractCommand;
use Bitrix\Tasks\Flow\Control\Exception\MiddlewareException;
use Bitrix\Tasks\Flow\Control\Middleware\AbstractMiddleware;
use Bitrix\Tasks\Flow\Internal\DI\Container;
use Bitrix\Tasks\Flow\Provider\DepartmentExistsProvider;

class DepartmentMiddleware extends AbstractMiddleware
{
	private DepartmentExistsProvider $departmentProvider;

	public function __construct()
	{
		$this->departmentProvider = Container::getInstance()->get(DepartmentExistsProvider::class);
	}

	/**
	 * @throws MiddlewareException
	 */
	public function handle(AbstractCommand $request)
	{
		$departmentIds = $request->getDepartmentIdList();
		if (empty($departmentIds))
		{
			return parent::handle($request);
		}

		$existsDepartmentIds = $this->departmentProvider->filterExists($departmentIds);

		$notExistsIds = array_diff($departmentIds, $existsDepartmentIds);
		if (!empty($notExistsIds))
		{
			$firstDepartmentId = reset($notExistsIds);

			throw new MiddlewareException("Department {$firstDepartmentId} doesn't exist");
		}

		return parent::handle($request);
	}
}
