<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Measure\Grid\Row\Action;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Access\Measure\MeasureAccess;
use Bitrix\Crm\V2\Internal\Service\Measure\MeasureMutationService;
use Bitrix\Main\Error;
use Bitrix\Main\Grid\Row\Action\Action;
use Bitrix\Main\Grid\Row\Action\BaseAction;
use Bitrix\Main\Grid\Row\Action\DataProvider;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * @internal
 */
final class MeasureDataProvider extends DataProvider
{
	public function __construct(
		Settings $settings,
		private readonly MeasureAccess $access,
		private readonly MeasureMutationService $mutationService,
	)
	{
		parent::__construct($settings);
	}

	public function prepareActions(): array
	{
		if (!$this->access->canEdit())
		{
			return [];
		}

		return [
			$this->createEditAction(),
			$this->createDeleteAction(),
		];
	}

	private function createEditAction(): Action
	{
		return new class($this->getSettings()) extends BaseAction {
			public function __construct(
				private readonly Settings $settings,
			)
			{
			}

			public static function getId(): ?string
			{
				return 'edit';
			}

			public function processRequest(HttpRequest $request): ?Result
			{
				return null;
			}

			protected function getText(): string
			{
				Container::getInstance()->getLocalization()->loadMessages();

				return (string)Loc::getMessage('CRM_COMMON_ACTION_EDIT');
			}

			public function getControl(array $rawFields): ?array
			{
				$id = (int)($rawFields['ID'] ?? 0);
				$href = $rawFields['PATH_TO_MEASURE_EDIT'] ?? null;
				if ($id <= 0 || !is_string($href) || $href === '')
				{
					return null;
				}

				$this->href = $href;
				$safeGridId = \CUtil::JSEscape($this->settings->getID());
				$safeHref = \CUtil::JSEscape($href);
				$this->onclick = "return BX.Crm.Config.MeasureGrid.handleEditAction('{$safeGridId}', '{$safeHref}');";

				return parent::getControl($rawFields);
			}
		};
	}

	private function createDeleteAction(): Action
	{
		return new class(
			$this->getSettings(),
			$this->access,
			$this->mutationService,
		) extends BaseAction {
			public function __construct(
				private readonly Settings $settings,
				private readonly MeasureAccess $access,
				private readonly MeasureMutationService $mutationService,
			)
			{
			}

			public static function getId(): ?string
			{
				return 'delete';
			}

			public function processRequest(HttpRequest $request): ?Result
			{
				if (!$request->isPost())
				{
					return (new Result())->addError(new Error('Only POST requests are allowed.', 'INVALID_ARGUMENT'));
				}

				if (!$this->access->canEdit())
				{
					return (new Result())->addError(new Error('Access denied.', 'ACCESS_DENIED'));
				}

				return $this->mutationService->delete((int)$request->getPost('id'));
			}

			protected function getText(): string
			{
				Container::getInstance()->getLocalization()->loadMessages();

				return (string)Loc::getMessage('CRM_COMMON_ACTION_DELETE');
			}

			public function getControl(array $rawFields): ?array
			{
				$id = (int)($rawFields['ID'] ?? 0);
				if ($id <= 0)
				{
					return null;
				}

				$safeGridId = \CUtil::JSEscape($this->settings->getID());
				$this->onclick =
					"BX.Main.gridManager.getInstanceById('{$safeGridId}')"
					. ".sendRowAction('delete', { id: {$id} });"
				;

				return parent::getControl($rawFields);
			}
		};
	}
}
