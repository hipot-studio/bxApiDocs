<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Controller\Partner;

use Bitrix\Intranet\ActionFilter\AdminUser;
use Bitrix\Intranet\Public\Command\Partner\DeleteCommand;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response\AjaxJson;

class Relation extends Controller
{
	public function getDefaultPreFilters(): array
	{
		$prefilters = parent::getDefaultPreFilters();
		$prefilters[] = new AdminUser();

		return $prefilters;
	}

	public function deleteAction(bool $fromCheckout = false): AjaxJson
	{
		$result = (new DeleteCommand($fromCheckout))->run();

		if (!$result->isSuccess())
		{
			return AjaxJson::createError($result->getErrorCollection());
		}

		return AjaxJson::createSuccess($result->getData());
	}
}
