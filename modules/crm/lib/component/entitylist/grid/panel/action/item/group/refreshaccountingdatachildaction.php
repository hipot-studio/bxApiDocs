<?php

namespace Bitrix\Crm\Component\EntityList\Grid\Panel\Action\Item\Group;

use Bitrix\Crm\Agent\Accounting\DealAccountSyncAgent;
use Bitrix\Crm\Agent\Accounting\InvoiceAccountSyncAgent;
use Bitrix\Crm\Agent\Accounting\LeadAccountSyncAgent;
use Bitrix\Crm\Agent\Accounting\OrderAccountSyncAgent;
use Bitrix\Crm\Agent\EntityStepwiseAgent;
use Bitrix\Crm\Component\EntityList\Grid\Panel\Event;
use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Error;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Grid\Panel\Action\Group\GroupChildAction;
use Bitrix\Main\Grid\Panel\Actions;
use Bitrix\Main\Grid\Panel\Snippet;
use Bitrix\Main\Grid\Panel\Snippet\Onchange;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\ArrayHelper;

final class RefreshAccountingDataChildAction extends GroupChildAction
{
	private const MAX_ITEMS_TO_PROCESS_ON_HIT = 100;

	private ?EntityStepwiseAgent $agent;

	public function __construct(private Factory $factory, private UserPermissions $userPermissions)
	{
		$this->agent = self::resolveAgent($this->factory->getEntityTypeId());
	}

	public static function isEntityTypeSupported(int $entityTypeId): bool
	{
		return !is_null(self::resolveAgent($entityTypeId));
	}

	private static function resolveAgent(int $entityTypeId): ?EntityStepwiseAgent
	{
		return match ($entityTypeId) {
			\CCrmOwnerType::Lead => LeadAccountSyncAgent::getInstance(),
			\CCrmOwnerType::Deal => DealAccountSyncAgent::getInstance(),
			\CCrmOwnerType::Invoice => InvoiceAccountSyncAgent::getInstance(),
			\CCrmOwnerType::Order => OrderAccountSyncAgent::getInstance(),
			default => null,
		};
	}

	public static function getId(): string
	{
		return 'refresh_accounting_data';
	}

	public function getName(): string
	{
		return (string)Loc::getMessage('CRM_GRID_PANEL_GROUP_ACTION_REFRESH_ACCOUNTING_DATA');
	}

	public function processRequest(HttpRequest $request, bool $isSelectedAllRows, ?Filter $filter): ?Result
	{
		if (!$this->agent)
		{
			return (new Result())->addError(new Error('Entity type is not supported'));
		}

		if ($isSelectedAllRows)
		{
			$this->agent->register();
			$this->agent->enable(true);

			return new Result();
		}

		$entityIds = $request->get('rows');
		if (!is_array($entityIds))
		{
			return null;
		}

		ArrayHelper::normalizeArrayValuesByInt($entityIds);
		if (empty($entityIds))
		{
			return null;
		}

		$this->agent->process($this->getItemIdsFilteredByPermissions($entityIds));

		return new Result();
	}

	/**
	 * @param int[] $entityIds
	 *
	 * @return int[]
	 */
	private function getItemIdsFilteredByPermissions(array $entityIds): array
	{
		$select = [Item::FIELD_NAME_ID];
		if ($this->factory->isCategoriesSupported())
		{
			$select[] = Item::FIELD_NAME_CATEGORY_ID;
		}

		$items = $this->factory->getItems([
			'select' => $select,
			'filter' => [
				'@' . Item::FIELD_NAME_ID => $entityIds,
			],
			'limit' => self::MAX_ITEMS_TO_PROCESS_ON_HIT,
		]);

		$filteredItems = array_filter($items, fn(Item $item) => $this->userPermissions->canUpdateItem($item));

		return array_map(fn(Item $item) => $item->getId(), $filteredItems);
	}

	protected function getOnchange(): Onchange
	{
		$onchange = new Onchange();

		$onchange->addAction([
			'ACTION' => Actions::SHOW,
			'DATA' => [
				['ID' => \Bitrix\Main\Grid\Panel\DefaultValue::FOR_ALL_CHECKBOX_ID],
			],
		]);

		$onchange->addAction([
			'ACTION' => Actions::CREATE,
			'DATA' => [
				(new Snippet())->getApplyButton([
					'ONCHANGE' => [
						[
							'ACTION' => Actions::CALLBACK,
							'DATA' => [
								[
									'JS' => 'Grid.sendSelected()',
								]
							],
						],
						[
							'ACTION' => Actions::CALLBACK,
							'DATA' => [
								[
									'JS' => (new Event('reloadPageAfterGridUpdate'))
										->addParam('isReloadOnlyIfForAll', true)
										->buildJsCallback()
									,
								]
							],
						],
					]
				]),
			]
		]);

		return $onchange;
	}
}
