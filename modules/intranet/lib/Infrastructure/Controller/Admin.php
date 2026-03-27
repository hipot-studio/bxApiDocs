<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Controller;

use Bitrix\Intranet\ActionFilter\AdminUser;
use Bitrix\Intranet\ActionFilter\IntranetUser;
use Bitrix\Intranet\Public\Command\Admin\SetRightsCommand;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response\AjaxJson;

class Admin extends Controller
{
	public function getDefaultPreFilters(): array
	{
		$prefilters = parent::getDefaultPreFilters();
		$prefilters[] = new IntranetUser();
		$prefilters[] = new AdminUser();

		return $prefilters;
	}

	public function setRightsAction(int $userId): AjaxJson
	{
		$result = (new SetRightsCommand((int)$userId))->run();

		if (!$result->isSuccess())
		{
			return AjaxJson::createError($result->getErrorCollection());
		}

		return AjaxJson::createSuccess($result->getData());
	}
}
