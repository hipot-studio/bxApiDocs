<?php

namespace Bitrix\Crm\Recurring;

use Bitrix\Crm\Field\Collection;
use Bitrix\Crm\Item;
use Bitrix\Crm\Recurring\Entity\Base;
use Bitrix\Crm\Recurring\Entity\Dynamic;
use Bitrix\Crm\Recurring\Entity\ParameterMapper\EntityForm;
use Bitrix\Crm\Restriction\RestrictionManager;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\EditorAdapter\Normalizer\FieldValueNormalizer;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;
use CCrmOwnerType;

final class RecurringFieldEditorAdapter
{
	private ?array $recurringData = null;
	private ?array $createCategoriesList = null;

	public const SECTION_RECURRING = 'recurring';
	public const FIELD_RECURRING = 'RECURRING';
	public const RECURRING_ACTIVE = 'RECURRING_ACTIVE';
	public const RECURRING_COUNTER_REPEAT = 'RECURRING_COUNTER_REPEAT';
	public const RECURRING_NEXT_EXECUTION = 'RECURRING_NEXT_EXECUTION';
	public const RECURRING_START_DATE = 'RECURRING_START_DATE';
	public const RECURRING_LIMIT_DATE = 'RECURRING_LIMIT_DATE';
	public const RECURRING_LIMIT_REPEAT = 'RECURRING_LIMIT_REPEAT';

	public function __construct(
		private readonly Collection $fieldsCollection,
	)
	{
	}

	public function isUsed(Item $item): bool
	{
		return $item->hasField(Item::FIELD_NAME_IS_RECURRING);
	}

	public function getEntityData(Item $item): array
	{
		$data = $this->getRecurringData($item);

		return [
			self::FIELD_RECURRING => $data,
			'recurringV2' => $data,
			Item::FIELD_NAME_IS_RECURRING => $this->getPreparedIsRecurringField($item),
		];
	}

	private function getRecurringData(Item $item): array
	{
		if ($this->recurringData === null)
		{
			$this->recurringData = $this->prepareData($item);
		}

		return $this->recurringData;
	}

	private function prepareData(Item $item): array
	{
		$entityTypeId = $item->getEntityTypeId();
		$factory = Container::getInstance()->getFactory($entityTypeId);

		if (!$item->isNew() && $item->getIsRecurring() && $factory?->isRecurringEnabled())
		{
			$recurringData = Manager::getList(
				['filter' => [
					'=ENTITY_TYPE_ID' => $entityTypeId,
					'=ITEM_ID' => $item->getId(),
				]],
				Manager::DYNAMIC,
			)->fetch();

			$recurringParams = [];
			if (is_array($recurringData))
			{
				$recurringParams = $recurringData['PARAMS'];
				if (isset($recurringParams['EXECUTION_TYPE']) && !isset($recurringParams['MODE']))
				{
					$recurringParams['MODE'] = $recurringParams['EXECUTION_TYPE'];
				}
				if ($recurringData['ACTIVE'] === 'N')
				{
					$recurringParams['MODE'] = Calculator::SALE_TYPE_NON_ACTIVE_DATE;
				}
				$recurringParams['SINGLE_INTERVAL_VALUE'] = (int)$recurringParams['SINGLE_INTERVAL_VALUE'];
				$singleDateBefore = null;
				if (CheckDateTime($recurringParams['SINGLE_DATE_BEFORE']))
				{
					$singleDateBefore = $recurringParams['SINGLE_DATE_BEFORE'];
				}
				$recurringParams['SINGLE_DATE_BEFORE']  = new Date($singleDateBefore);
				if (isset($recurringParams['REPEAT_TILL']) && !isset($recurringParams['MULTIPLE_TYPE_LIMIT']))
				{
					$recurringParams['MULTIPLE_TYPE_LIMIT'] = $recurringParams['REPEAT_TILL'];
				}
				$dateLimit = null;
				if (isset($recurringParams['END_DATE']) && !isset($recurringParams['MULTIPLE_DATE_LIMIT']))
				{
					$recurringParams['MULTIPLE_DATE_LIMIT'] = $recurringParams['END_DATE'];
				}
				if (CheckDateTime($recurringParams['MULTIPLE_DATE_LIMIT']))
				{
					$dateLimit = $recurringParams['MULTIPLE_DATE_LIMIT'];
				}
				$recurringParams['MULTIPLE_DATE_LIMIT']  = new Date($dateLimit);
				if (isset($recurringParams['LIMIT_REPEAT']) && !isset($recurringParams['MULTIPLE_TIMES_LIMIT']))
				{
					$recurringParams['MULTIPLE_TIMES_LIMIT'] = $recurringParams['LIMIT_REPEAT'];
				}
				$startDateValue = null;
				if (CheckDateTime($recurringParams['MULTIPLE_DATE_START']))
				{
					$startDateValue = $recurringParams['MULTIPLE_DATE_START'];
				}
				$recurringParams['MULTIPLE_DATE_START'] = new Date($startDateValue);
				$recurringParams['MULTIPLE_CUSTOM_INTERVAL_VALUE'] = (int)$recurringParams['MULTIPLE_CUSTOM_INTERVAL_VALUE'];
				$recurringParams['OFFSET_BEGINDATE_VALUE'] = (int)$recurringParams['OFFSET_BEGINDATE_VALUE'];
				$recurringParams['OFFSET_CLOSEDATE_VALUE'] = (int)$recurringParams['OFFSET_CLOSEDATE_VALUE'];

				$selectDateTypeFields = ['MULTIPLE_TYPE', 'MULTIPLE_CUSTOM_TYPE', 'SINGLE_TYPE', 'OFFSET_BEGINDATE_TYPE', 'OFFSET_CLOSEDATE_TYPE'];
				foreach ($selectDateTypeFields as $code)
				{
					if ((int)$recurringParams[$code] <= 0)
					{
						$recurringParams[$code] = Calculator::SALE_TYPE_DAY_OFFSET;
					}
				}

				if (isset($recurringData['CATEGORY_ID']) || (int)$recurringData['CATEGORY_ID'] > 0)
				{
					$recurringParams['CATEGORY_ID'] = $recurringData['CATEGORY_ID'];
				}
				else
				{
					$recurringParams['CATEGORY_ID'] = $item->getCategoryId();
				}

				$recurringParams['IS_SEND_EMAIL'] = ($recurringData['IS_SEND_EMAIL'] ?? 'N') === 'Y';
				$recurringParams['EMAIL_IDS'] = $recurringData['EMAIL_IDS'] ?? [];
				$recurringParams['SENDER_ID'] ??= null;
				$recurringParams['EMAIL_TEMPLATE_ID'] ??= null;
				$recurringParams['EMAIL_DOCUMENT_ID'] ??= null;

				$result = [];
				foreach ($recurringParams as $name => $value)
				{
					$changedName = "RECURRING[$name]";
					$result[$changedName] = $value;
				}

				$recurringParams = $result;
			}

			return $recurringParams;
		}

		$today = new Date();

		return [
			'RECURRING[MODE]' => Calculator::SALE_TYPE_NON_ACTIVE_DATE,
			'RECURRING[SINGLE_TYPE]' => Calculator::SALE_TYPE_DAY_OFFSET,
			'RECURRING[SINGLE_INTERVAL_VALUE]' => 0,
			'RECURRING[SINGLE_DATE_BEFORE]' => $today,
			'RECURRING[MULTIPLE_TYPE]' => Calculator::SALE_TYPE_DAY_OFFSET,
			'RECURRING[MULTIPLE_CUSTOM_TYPE]' => Calculator::SALE_TYPE_DAY_OFFSET,
			'RECURRING[MULTIPLE_CUSTOM_INTERVAL_VALUE]' => 1,
			'RECURRING[BEGINDATE_TYPE]' => Base::SETTED_FIELD_VALUE,
			'RECURRING[OFFSET_BEGINDATE_VALUE]' => 0,
			'RECURRING[OFFSET_BEGINDATE_TYPE]' => Calculator::SALE_TYPE_DAY_OFFSET,
			'RECURRING[CLOSEDATE_TYPE]' => Base::SETTED_FIELD_VALUE,
			'RECURRING[OFFSET_CLOSEDATE_VALUE]' => 0,
			'RECURRING[OFFSET_CLOSEDATE_TYPE]' => Calculator::SALE_TYPE_DAY_OFFSET,
			'RECURRING[MULTIPLE_DATE_START]' => $today,
			'RECURRING[MULTIPLE_DATE_LIMIT]' => $today,
			'RECURRING[MULTIPLE_TIMES_LIMIT]' => 1,
			'RECURRING[CATEGORY_ID]' => $item->getCategoryId(),
			'RECURRING[IS_SEND_EMAIL]' => false,
			'RECURRING[EMAIL_TEMPLATE_ID]' => null,
			'RECURRING[EMAIL_DOCUMENT_ID]' => null,
			'RECURRING[SENDER_ID]' => null,
			'RECURRING[EMAIL_IDS]' => [],
		];
	}

	protected function getPreparedIsRecurringField(Item $item): string
	{
		$fieldName = Item::FIELD_NAME_IS_RECURRING;
		$field = $this->fieldsCollection->getField($fieldName);
		if ($field)
		{
			$fieldType = $field->getType();
			$fieldValue = $item->get($fieldName);

			return (new FieldValueNormalizer($fieldType))->normalize($fieldValue);
		}

		return 'N';
	}

	public function prepareFieldByInfo(Item $item, array $field): array
	{
		Container::getInstance()->getLocalization()->loadMessages();

		if ($item->getIsRecurring())
		{
			$params = ['filter' => [
				'=ENTITY_TYPE_ID' => $item->getEntityTypeId(),
				'=ITEM_ID' => $item->getId(),
			]];
			$recurringData = Manager::getList(
				$params,
				Manager::DYNAMIC,
			)->fetch();

			if (!empty($recurringData['NEXT_EXECUTION']) && $recurringData['ACTIVE'] === 'Y')
			{
				$recurringViewText =  Loc::getMessage(
					'CRM_TYPE_RECURRING_FIELD_DATE_NEXT_EXECUTION',
					['#NEXT_DATE#' => $recurringData['NEXT_EXECUTION']],
				);
			}
			else
			{
				$recurringViewText = Loc::getMessage('CRM_TYPE_RECURRING_FIELD_NOTHING_SELECTED');
			}
		}
		elseif (!$item->isNew())
		{
			$params = [
				'filter' => [
					'=ENTITY_TYPE_ID' => $item->getEntityTypeId(),
					'=BASED_ID' => $item->getId(),
				],
				'select' => ['ITEM_ID'],
			];
			$recurringList = Manager::getList(
				$params,
				Manager::DYNAMIC,
			)->fetchAll();

			$isInvoice = $item->getEntityTypeId() === CCrmOwnerType::SmartInvoice;
			$recurringCount = count($recurringList);
			if ($recurringCount === 1)
			{
				$code = $isInvoice ? 'CRM_TYPE_RECURRING_FIELD_CREATED_FROM_CURRENT_INVOICE' : 'CRM_TYPE_RECURRING_FIELD_CREATED_FROM_CURRENT';
				$recurringViewText =  Loc::getMessage(
					$code,
					['#RECURRING_ID#' => $recurringList[0]['ITEM_ID']],
				);
			}
			elseif ($recurringCount > 1)
			{
				$recurringLines = [];
				foreach ($recurringList as $recurringListItem)
				{
					$recurringLines[] = Loc::getMessage(
						'CRM_TYPE_SMART_INVOICE_FIELD_NUM_SIGN',
						['#SMART_INVOICE_ID#' => $recurringListItem['ITEM_ID']],
					);
				}

				if (!empty($recurringLines))
				{
					$code = $isInvoice ? 'CRM_TYPE_RECURRING_FIELD_CREATED_MANY_FROM_CURRENT_INVOICE' : 'CRM_TYPE_RECURRING_FIELD_CREATED_MANY_FROM_CURRENT';
					$recurringViewText =  Loc::getMessage(
						$code,
						['#RECURRING_LIST#' => implode(', ', $recurringLines)],
					);
				}
			}
		}

		if (empty($recurringViewText) && empty($this->getAvailableCategories($item)))
		{
			$recurringViewText = Loc::getMessage('CRM_TYPE_RECURRING_FIELD_RESTRICTED');
		}

		if (empty($recurringViewText))
		{
			$recurringViewText = Loc::getMessage('CRM_TYPE_RECURRING_FIELD_NOTHING_SELECTED');
		}

		// @todo ****recurring need slider for dynamic types?
		$invoiceRecurringRestriction = null;
		if ($item->getEntityTypeId() === CCrmOwnerType::SmartInvoice)
		{
			if (!$this->isRecurringEnabled($item))
			{
				$invoiceRecurringRestriction = RestrictionManager::getInvoiceRecurringRestriction();
			}
		}

		$field['data']['view'] = [
			'text' => $recurringViewText,
		];
		$field['data']['fieldData'] = [
			'MULTIPLE_EXECUTION' => Manager::MULTIPLY_EXECUTION,
			'SINGLE_EXECUTION' => Manager::SINGLE_EXECUTION,
			'NON_ACTIVE' => Calculator::SALE_TYPE_NON_ACTIVE_DATE,
		];

		$field['data']['loaders'] = [
			'url' => UrlManager::getInstance()->create('crm.recurring.hint.get')->getUri(),
		];

		$field['data']['restrictScript'] = (
			$invoiceRecurringRestriction !== null
				? $invoiceRecurringRestriction->prepareInfoHelperScript()
				: ''
		);

		$field['enableRecurring'] = $this->isRecurringEnabled($item);

		$factory = Container::getInstance()->getFactory($item->getEntityTypeId());
		$isCategoriesEnabled = $factory?->isCategoriesEnabled();
		$field['data']['isCategoriesEnabled'] = $isCategoriesEnabled;
		if ($isCategoriesEnabled)
		{
			$field['data']['categories'] = $this->getAvailableCategories($item);
		}

		return $field;
	}

	private function isRecurringEnabled(Item $item): bool
	{
		$entityTypeId = $item->getEntityTypeId();

		return Container::getInstance()->getFactory($entityTypeId)?->isRecurringEnabled() ?? false;
	}

	private function getAvailableCategories(Item $item): array
	{
		if ($this->createCategoriesList === null)
		{
			$result = [];
			$categories = Container::getInstance()->getFactory($item->getEntityTypeId())?->getCategories();
			$availableCategories = Container::getInstance()->getUserPermissions()->category()->filterAvailableForReadingCategories($categories);

			foreach ($availableCategories as $category)
			{
				$result[] = [
					'NAME' => $category->getName(),
					'VALUE' => $category->getId(),
				];
			}

			$this->createCategoriesList = $result;
		}

		return $this->createCategoriesList;
	}

	public function saveRecurringData(Item $item, array $recurringData): Result
	{
		$result = $this->getRecurringDataFromEmbeddedEditor($item, $recurringData);

		if (!$result->isSuccess())
		{
			return $result;
		}

		$entityTypeId = $item->getEntityTypeId();
		$recurring = Manager::getList(
			[
				'filter' => [
					'=ENTITY_TYPE_ID' => $entityTypeId,
					'=ITEM_ID' => $item->getId(),
				],
				'select' => ['ID'],
			],
			Manager::DYNAMIC,
		)->fetch();

		$recurringFields = $result->getData()['recurringFields'] ?? [];
		$recurringFields['ENTITY_TYPE_ID'] = $entityTypeId;

		if (is_array($recurring) && !$item->isNew())
		{
			return Manager::update($recurring['ID'], $recurringFields,Manager::DYNAMIC);
		}

		$fields = $item->getData();

		return Manager::createEntity($fields, $recurringFields, Manager::DYNAMIC);
	}

	private function getRecurringDataFromEmbeddedEditor(Item $item, array $recurringData): Result
	{
		$result = new Result();

		if (
			$recurringData['MODE'] === Calculator::SALE_TYPE_NON_ACTIVE_DATE
			|| (
				(int)$recurringData['MODE'] === Manager::MULTIPLY_EXECUTION
				&& (int)$recurringData['MULTIPLE_TYPE'] === Calculator::SALE_TYPE_CUSTOM_OFFSET
				&& (int)$recurringData['MULTIPLE_CUSTOM_INTERVAL_VALUE'] <= 0
			)
		)
		{
			$recurringFields = [
				'ACTIVE' => 'N',
				'NEXT_EXECUTION' => null,
				'PARAMS' => $recurringData,
			];
		}
		else
		{
			$startDate = null;
			$recurringFields = [
				'CATEGORY_ID' => (int)($recurringData['CATEGORY_ID'] ?? $item->getCategoryId()),
				'PARAMS' => $recurringData,
			];

			if ((int)$recurringData['MODE'] === Manager::SINGLE_EXECUTION)
			{
				$singleCalculationFields = [
					EntityForm::FIELD_MODE_NAME,
					EntityForm::FIELD_SINGLE_TYPE_NAME,
					EntityForm::FIELD_SINGLE_INTERVAL_NAME,
				];

				$limitParams = array_intersect_key($recurringData, array_flip($singleCalculationFields));
				$limitMapper = EntityForm::getInstance();
				$limitMapper->fillMap($limitParams);

				$startDateValue = null;
				if (CheckDateTime($recurringData['SINGLE_DATE_BEFORE']))
				{
					$startDateValue = $recurringData['SINGLE_DATE_BEFORE'];
				}
				$startDate = new Date($startDateValue);
				$recurringFields['START_DATE'] = $startDate;
				$recurringFields['IS_LIMIT'] = Base::LIMITED_BY_DATE;

				$instance = Calculator::getInstance();
				$instance->setStartDate($startDate);
				$instance->setParams($limitMapper->getPreparedMap());

				$recurringFields['LIMIT_DATE'] = $instance->calculateDate();
			}
			elseif ((int)$recurringData['MODE'] === Manager::MULTIPLY_EXECUTION)
			{
				$startDateValue = null;
				if (CheckDateTime($recurringData['MULTIPLE_DATE_START']))
				{
					$startDateValue = $recurringData['MULTIPLE_DATE_START'];
				}
				$startDate = new Date($startDateValue);
				$recurringFields['START_DATE'] = $startDate;
				$recurringFields['IS_LIMIT'] = Base::NO_LIMITED;

				if ($recurringData['MULTIPLE_TYPE_LIMIT'] === Base::LIMITED_BY_TIMES)
				{
					$recurringFields['IS_LIMIT'] = Base::LIMITED_BY_TIMES;
					$recurringFields['LIMIT_REPEAT'] = (int)$recurringData['MULTIPLE_TIMES_LIMIT'];
				}
				elseif ($recurringData['MULTIPLE_TYPE_LIMIT'] === Base::LIMITED_BY_DATE)
				{
					$recurringFields['IS_LIMIT'] = Base::LIMITED_BY_DATE;
					$recurringFields['LIMIT_DATE'] = new Date($recurringData['MULTIPLE_DATE_LIMIT']);
				}
			}

			$today = new Date();

			$nextDate = Dynamic::getNextDate($recurringData, $startDate);
			if ($nextDate->getTimestamp() < $today->getTimestamp())
			{
				$isInvoice = $item->getEntityTypeId() === CCrmOwnerType::SmartInvoice;
				$code = $isInvoice ? 'CRM_RECURRING_EDITOR_ADAPTER_RECURRING_DATE_START_ERROR_INVOICE' : 'CRM_RECURRING_EDITOR_ADAPTER_RECURRING_DATE_START_ERROR';

				return $result->addError(new Error(Loc::getMessage($code)));
			}
		}

		$recurringFields['IS_SEND_EMAIL'] = ($recurringData['IS_SEND_EMAIL'] ?? 'N') === 'Y';
		$recurringFields['EMAIL_IDS'] = $recurringData['EMAIL_IDS'] ?? [];

		return $result->setData(['recurringFields' => $recurringFields]);
	}
}
