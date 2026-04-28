<?php

namespace Bitrix\Crm\Import;

use Bitrix\Crm\Import\Collection\ImportItemCollection;
use Bitrix\Crm\Import\Contract\File\ReaderInterface;
use Bitrix\Crm\Import\Contract\ImportEntityInterface\HasPostSaveHooksInterface;
use Bitrix\Crm\Import\Dto\ImportItemsCollection\ImportItem;
use Bitrix\Crm\Import\Dto\ImportOperationOptions;
use Bitrix\Crm\Import\Enum\DuplicateControl\DuplicateControlBehavior;
use Bitrix\Crm\Import\Enum\DuplicateControl\DuplicateControlTarget;
use Bitrix\Crm\Import\Factory\DuplicateControlStrategyFactory;
use Bitrix\Crm\Import\File\Row;
use Bitrix\Crm\Import\Result\DuplicateControlProcessResult;
use Bitrix\Crm\Import\Result\Error\RowsErrorPack;
use Bitrix\Crm\Import\Result\ImportOperationResult;
use Bitrix\Crm\Item;
use Bitrix\Crm\Requisite\ImportHelper;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use CCrmFieldMulti;

final class ImportOperation
{
	private readonly DuplicateControlStrategyFactory $duplicateControlProcessorFactory;

	public function __construct(
		private readonly ImportOperationOptions $options,
	)
	{
		$this->duplicateControlProcessorFactory = new DuplicateControlStrategyFactory();
	}

	public function launch(): ImportOperationResult
	{
		$factory = Container::getInstance()->getFactory($this->options->entityTypeId());
		if ($factory === null)
		{
			return ImportOperationResult::failEntityTypeNotSupported($this->options->entityTypeId());
		}

		$importResult = ImportOperationResult::success();
		$importItems = new ImportItemCollection();

		$reader = $this->prepareReader();

		$positionBefore = $reader->getPosition();
		$lineBefore = $reader->getCurrentLine();

		foreach ($reader->read() as $row)
		{
			$positionAfter = $reader->getPosition();
			$importResult->incrementProgressedBytes($positionAfter - $positionBefore);

			$positionBefore = $positionAfter;

			$requisiteImportHelper = null;
			$nextEntityAfterSearch = false;
			$errorOccurred = false;
			$searchNextEntity = 0;

			$requisiteOptions = $this->options->getRequisiteOptions();
			if ($requisiteOptions !== null && $requisiteOptions->isImportRequisite())
			{
				$searchNextEntity = $requisiteOptions->getSearchNextEntity();
				$prevEntityKey = $requisiteOptions->getPrevEntity();

				$requisiteImportHelper = new ImportHelper(
					entityTypeId: $this->options->entityTypeId(),
					headerIndex: $this->options->fieldBindings->getFieldIdColumnIndexMap(),
					headerInfo: $this->options->entity->getFields()->toArray(),
					options: [
						'ROW_LIMIT' => 50,
						'DEF_PRESET_ID' => $requisiteOptions->getDefaultRequisitePresetId(),
						'ASSOC_PRESET' => $requisiteOptions->isRequisitePresetAssociate(),
						'ASSOC_PRESET_BY_ID' => $requisiteOptions->isRequisitePresetAssociateById(),
						'USE_DEF_PRESET' => $requisiteOptions->isRequisitePresetUseDefault(),
					],
				);

				if ($searchNextEntity > 0)
				{
					$requisiteImportHelper->enableSearchNextEntityMode($prevEntityKey);
				}

				do
				{
					$isParseNextRow = false;

					$result = $requisiteImportHelper->parseRow($row->toArray(), $row->getIndex());
					if ($result->isSuccess())
					{
						$lineBefore = $reader->getCurrentLine();
						$row = $reader->readRow($lineBefore + 1);

						if ($row !== null)
						{
							$isParseNextRow = true;
						}
					}
				}
				while ($isParseNextRow);

				if ($result->isSuccess())
				{
					$requisiteImportHelper->setReady(true);
				}
				else
				{
					$errorOccurred = true;
					$errorCode = $requisiteImportHelper->getErrorCode($result);

					if ($errorCode === ImportHelper::ERR_NEXT_ENTITY)
					{
						$requisiteOptions->resetSearchNextEntity();
						$requisiteOptions->resetPrevEntity();

						if ($searchNextEntity > 0)
						{
							$nextEntityAfterSearch = true;
							$searchNextEntity = 0;
						}
						else
						{
							$requisiteImportHelper->setReady(true);
						}

						$reader->setCurrentLine($lineBefore + 1);
						$errorOccurred = false;
					}

					if ($errorCode === ImportHelper::ERR_ROW_LIMIT)
					{
						$requisiteOptions->setSearchNextEntity(++$searchNextEntity);
						$requisiteOptions->setPrevEntity($requisiteImportHelper->getCurrentEntityKey());

						$reader->setCurrentLine($lineBefore + 1);

						if ($searchNextEntity !== 1)
						{
							$errorOccurred = false;
						}
					}
				}

				if (!$errorOccurred && $requisiteImportHelper->isReady())
				{
					$result = $requisiteImportHelper->parseRequisiteData();
					if (!$result->isSuccess())
					{
						$errorOccurred = true;
					}
				}

				if ($errorOccurred)
				{
					 $error = new RowsErrorPack($requisiteImportHelper->getRowIndexes(), $result->getErrors());

					 $importResult->addErrorPack($error);
				}

				if (!$errorOccurred && $requisiteImportHelper->getRowCount() > 0)
				{
					$row = Row::fromArray(
						$requisiteImportHelper->getFirstRowIndex(),
						$requisiteImportHelper->getFirstRow(),
					);
				}
			}

			if ($errorOccurred)
			{
				if (($importItems->count() + $importResult->getFailImportCount()) >= $this->options->limit)
				{
					break;
				}

				continue;
			}

			if ($nextEntityAfterSearch)
			{
				continue;
			}

			unset($errorOccurred);

			if ($searchNextEntity > 0)
			{
				break;
			}

			$errors = [];
			$item = [];
			foreach ($this->options->entityFields() as $field)
			{
				$fieldProcessResult = $field->process(
					importItemFields: $item,
					fieldBindings: $this->options->fieldBindings,
					row: $row->toArray(),
				);

				if (!$fieldProcessResult->isSuccess())
				{
					$errors[] = $fieldProcessResult->getErrors();
				}
			}

			if (!empty($errors))
			{
				$pack = new RowsErrorPack([$row->getIndex()], array_merge(...$errors));
				$importResult->addErrorPack($pack);

				continue;
			}

			$canBreak = !$importItems->hasItem($item)
				&& $importItems->count() >= $this->options->limit;

			if ($canBreak)
			{
				break;
			}

			$importItems->add($row->getIndex(), $item, $requisiteImportHelper);
		}

		$importResult->setCurrentLine($reader->getCurrentLine());
		$importResult->setIsFinished($reader->isEndOfFile());

		foreach ($importItems->getAll() as $importItem)
		{
			CCrmFieldMulti::PrepareFields($importItem->values);

			$duplicateProcessResult = $this->processDuplicateControl($importItem);
			if ($duplicateProcessResult->isDuplicate)
			{
				$importResult->incrementDuplicateImportCount();
				$importResult->addDuplicateRowIndexes($importItem->getRowIndexes());

				if (!$duplicateProcessResult->isSuccess())
				{
					$pack = new RowsErrorPack($importItem->getRowIndexes(), $duplicateProcessResult->getErrors());
					$importResult->addErrorPack($pack);
				}

				continue;
			}

			$item = $this->createItem($factory, $importItem);
			$itemSaveResult = $factory
				->getImportOperation($item)
				->launch()
			;

			if (!$itemSaveResult->isSuccess())
			{
				$pack = new RowsErrorPack($importItem->getRowIndexes(), $itemSaveResult->getErrors());
				$importResult->addErrorPack($pack);

				continue;
			}

			$importResult->incrementSuccessImportCount();

			$entity = $this->options->entity;
			if ($entity instanceof HasPostSaveHooksInterface)
			{
				foreach ($entity->getPostSaveHooks() as $afterSaveProcess)
				{
					$result = $afterSaveProcess->execute($item, $importItem);
					if (!$result->isSuccess())
					{
						$pack = new RowsErrorPack($importItem->getRowIndexes(), $result->getErrors());
						$importResult->addErrorPack($pack);
					}
				}
			}

			if ($importItem->getRequisiteImportHelper() !== null && $importItem->getRequisiteImportHelper()->isReady())
			{
				$duplicateControl = $this->options->getDuplicateControl()?->getBehavior()?->value
					?? DuplicateControlBehavior::NoControl->value;

				$requisiteImportResult = $importItem
					->getRequisiteImportHelper()
					->importParsedRequisites(
						entityTypeId: $item->getEntityTypeId(),
						entityId: $item->getId(),
						dupControlType: $duplicateControl,
				);

				if (!$requisiteImportResult->isSuccess())
				{
					$errors = new RowsErrorPack($importItem->getRequisiteImportHelper()->getRowIndexes(), $requisiteImportResult->getErrors());
					$importResult->addErrorPack($errors);
				}
			}
		}

		return $importResult;
	}

	private function prepareReader(): ReaderInterface
	{
		return $this->options->reader->setCurrentLine($this->options->startFrom);
	}

	private function createItem(Factory $factory, ImportItem $importItem): Item
	{
		$values = $this->options
			->entity
			->getSettings()
			->applyDefaultValues($importItem->values)
		;

		// For compatibility only. Try sync product PRICE
		if (
			isset(
				$values['PRODUCT_ROWS'],
				$values['OPPORTUNITY']
			)
			&& !isset($values['PRODUCT_ROWS'][0]['PRICE'])
			&& count($values['PRODUCT_ROWS']) === 1
		)
		{
			$values['PRODUCT_ROWS'][0]['PRICE'] = (float)$values['OPPORTUNITY'];
		}

		return $factory
			->createItem()
			->setFromCompatibleData($values)
			->unset(Item::FIELD_NAME_ID)
		;
	}

	private function processDuplicateControl(ImportItem $importItem): DuplicateControlProcessResult
	{
		$duplicateControl = $this->options->getDuplicateControl();
		if ($duplicateControl === null)
		{
			return new DuplicateControlProcessResult(
				isDuplicate: false,
				entityTypeId: $this->options->entityTypeId(),
				duplicateIds: [],
			);
		}

		$itemValues = $importItem->values;
		$fieldNames = DuplicateControlTarget::collectFieldNames($duplicateControl->getTargets());

		$requisiteOptions = $this->options->getRequisiteOptions();
		if ($requisiteOptions !== null && $requisiteOptions->isImportRequisite())
		{
			$requisiteDupParams = $importItem
				->getRequisiteImportHelper()
				?->getParsedRequisiteDupParams($requisiteOptions->getRequisiteDupControlFieldMap());

			if (!empty($requisiteDupParams['DUP_PARAM_LIST']) && !empty($requisiteDupParams['DUP_PARAM_FIELDS']))
			{
				$itemValues['RQ'] = $requisiteDupParams['DUP_PARAM_LIST'];
				$fieldNames = array_merge($fieldNames, $requisiteDupParams['DUP_PARAM_FIELDS']);
			}
		}

		$processor = $this->duplicateControlProcessorFactory->create($duplicateControl->getBehavior());

		$duplicateProcessResult = $processor->processDuplicateControl(
			entityTypeId: $this->options->entityTypeId(),
			fieldNames: $fieldNames,
			itemValues: $itemValues,
		);

		if (!$duplicateProcessResult->isDuplicate || $duplicateProcessResult->isSuccess())
		{
			return $duplicateProcessResult;
		}

		$rqImportHelper = $importItem->getRequisiteImportHelper();
		if ($rqImportHelper !== null)
		{
			foreach ($duplicateProcessResult->duplicateIds as $duplicateId)
			{
				$rqImportResult = $rqImportHelper->importParsedRequisites(
					entityTypeId: $duplicateProcessResult->entityTypeId,
					entityId: $duplicateId,
					dupControlType: $duplicateControl->getBehavior()->value,
				);

				if (!$rqImportResult->isSuccess())
				{
					$duplicateProcessResult->addErrors($rqImportResult->getErrors());
				}
			}
		}

		return $duplicateProcessResult;
	}
}
