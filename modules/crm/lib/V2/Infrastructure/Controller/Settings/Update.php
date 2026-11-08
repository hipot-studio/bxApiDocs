<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Controller\Settings;

use Bitrix\Crm\V2\Public\Command\Settings\UpdateSettingCommand;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\HttpMethod;
use Bitrix\Main\Engine\Controller;

final class Update extends Controller
{
	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new ActionFilter\Scope(ActionFilter\Scope::AJAX),
			new ActionFilter\ContentType([ActionFilter\ContentType::JSON]),
		];
	}

	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function updateAction(string $code, $value): ?array
	{
		$result = (new UpdateSettingCommand(
			$code,
			$value,
			(int)$this->getCurrentUser()->getId(),
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return [
			'presentation' => $result->getData(),
		];
	}
}
