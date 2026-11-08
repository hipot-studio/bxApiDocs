<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Controller\Application;

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\Response\AjaxJson;
use Bitrix\Main\Error;
use Bitrix\Rest\Infrastructure\Controller\ActionFilter\AdminUser;
use Bitrix\Rest\Public\Command\Application\AppInstallRequest\DismissAppInstallRequestCommand;
use Bitrix\Rest\Public\Command\Application\AppInstallRequest\RejectAppInstallRequestCommand;

class AppInstallRequest extends Controller
{
	private const ERROR_CODE_INVALID_REQUEST_ID = 'INVALID_REQUEST_ID';

	public function rejectAction(int $requestId): AjaxJson
	{
		if ($requestId <= 0)
		{
			$this->addError(new Error('Parameter requestId must be a positive integer.', self::ERROR_CODE_INVALID_REQUEST_ID));

			return AjaxJson::createError();
		}

		$adminId = (int)CurrentUser::get()->getId();

		$result = (new RejectAppInstallRequestCommand($requestId, $adminId))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return AjaxJson::createError();
		}

		return AjaxJson::createSuccess();
	}

	public function approveAction(int $requestId): AjaxJson
	{
		if ($requestId <= 0)
		{
			$this->addError(new Error('Parameter requestId must be a positive integer.', self::ERROR_CODE_INVALID_REQUEST_ID));

			return AjaxJson::createError();
		}

		$adminId = (int)CurrentUser::get()->getId();

		$result = (new DismissAppInstallRequestCommand($requestId, $adminId))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return AjaxJson::createError();
		}

		return AjaxJson::createSuccess();
	}

	protected function getDefaultPreFilters(): array
	{
		$prefilters = parent::getDefaultPreFilters();
		$prefilters[] = new ActionFilter\Scope(ActionFilter\Scope::NOT_REST);
		$prefilters[] = new AdminUser();

		return $prefilters;
	}
}
