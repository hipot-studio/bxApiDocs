<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Measure\Grid\Panel\Action;

use Bitrix\Crm\V2\Internal\Access\Measure\MeasureAccess;
use Bitrix\Crm\V2\Internal\Service\Measure\MeasureMutationService;
use Bitrix\Main\Error;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Grid\Panel\Action\DataProvider;
use Bitrix\Main\Grid\Panel\Action\EditAction;
use Bitrix\Main\Grid\Panel\Action\ForAllCheckboxAction;
use Bitrix\Main\Grid\Panel\Action\RemoveAction;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\HttpRequest;
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
			new class($this->access, $this->mutationService) extends EditAction {
				public function __construct(
					private readonly MeasureAccess $access,
					private readonly MeasureMutationService $mutationService,
				)
				{
				}

				public function processRequest(
					HttpRequest $request,
					bool $isSelectedAllRows,
					?Filter $filter,
				): ?Result
				{
					$result = new Result();
					if (!$request->isPost())
					{
						return $result->addError(new Error('Only POST requests are allowed.', 'INVALID_ARGUMENT'));
					}

					if (!$this->access->canEdit())
					{
						return $result->addError(new Error('Access denied.', 'ACCESS_DENIED'));
					}

					$rows = $request->getPost('FIELDS');
					if (!is_array($rows))
					{
						return $result->addError(new Error('Invalid fields payload.', 'INVALID_ARGUMENT'));
					}

					foreach ($rows as $id => $fields)
					{
						if (!$this->access->canEdit())
						{
							$result->addError(new Error('Access denied.', 'ACCESS_DENIED'));
							break;
						}

						if (!is_array($fields))
						{
							$result->addError(new Error('Invalid fields payload.', 'INVALID_ARGUMENT'));
							continue;
						}

						$updateResult = $this->mutationService->update((int)$id, $fields);
						if (!$updateResult->isSuccess())
						{
							$result->addErrors($updateResult->getErrors());
						}
					}

					return $result;
				}
			},
			new class extends RemoveAction {
				public function getControl(): ?array
				{
					$control = parent::getControl();
					if ($control === null)
					{
						return null;
					}

					$confirmationKeys = [
						'CONFIRM',
						'CONFIRM_MESSAGE',
						'CONFIRM_APPLY_BUTTON',
						'CONFIRM_CANCEL_BUTTON',
					];
					foreach ($control['ONCHANGE'] ?? [] as $index => $onChange)
					{
						$control['ONCHANGE'][$index] = array_diff_key($onChange, array_flip($confirmationKeys));
					}

					return $control;
				}

				public function processRequest(
					HttpRequest $request,
					bool $isSelectedAllRows,
					?Filter $filter,
				): ?Result
				{
					return null;
				}
			},
			new ForAllCheckboxAction(),
		];
	}
}
