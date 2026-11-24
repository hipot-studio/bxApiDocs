<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Controller;

use Bitrix\Intranet\Public\Command\MainTool\ApplySelectionCommand;
use Bitrix\Intranet\Public\Provider\MainTool\StateProvider;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response\AjaxJson;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Localization\Loc;


class MainTool extends Controller
{
	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
		];
	}

	public function configureActions(): array
	{
		return [
			'getState' => [
				'+prefilters' => [new ActionFilter\CloseSession()],
			],
			'apply' => [
				'+prefilters' => [new ActionFilter\ContentType([ActionFilter\ContentType::JSON])],
			],
		];
	}

	public function getStateAction(): AjaxJson
	{
		$result = (new StateProvider())->getPickerState();

		if (!$result->isSuccess())
		{
			return AjaxJson::createError($result->getErrorCollection(), $result->getData() ?: null);
		}

		return AjaxJson::createSuccess($result->getData());
	}

	public function applyAction(string $presetId, bool $applyForAll): AjaxJson
	{
		if (!StateProvider::isMainToolPickerEnabled())
		{
			$state = ['isMainToolPickerEnabled' => false];
			$errors = new ErrorCollection([
				new Error(Loc::getMessage('MAIN_TOOL_FEATURE_DISABLED') ?? '', 'MAIN_TOOL_FEATURE_DISABLED', $state),
			]);

			return AjaxJson::createError($errors, $state);
		}

		$result = (new ApplySelectionCommand($presetId, $applyForAll))->run();

		if (!$result->isSuccess())
		{
			return AjaxJson::createError($result->getErrorCollection(), $result->getData() ?: null);
		}

		return AjaxJson::createSuccess(['url' => $result->getData()['url']]);
	}

}
