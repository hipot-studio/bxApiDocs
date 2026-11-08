<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Controller;

use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\DataView\DataView as DataViewEntity;
use Bitrix\Bizproc\Internal\Entity\StorageType\StorageTypeCollection;
use Bitrix\Bizproc\Internal\Exception\DataView\DataViewMaterializeFailedException;
use Bitrix\Bizproc\Internal\Exception\DataView\DataViewValidationException;
use Bitrix\Bizproc\Internal\Exception\DataView\InvalidDataViewDefinitionException;
use Bitrix\Bizproc\Internal\Service\DataView\ColumnResolver;
use Bitrix\Bizproc\Public\Command\DataView\DeleteDataViewCommand;
use Bitrix\Bizproc\Public\Command\DataView\DeleteDataViewCommandHandler;
use Bitrix\Bizproc\Public\Command\DataView\SaveDataViewCommand;
use Bitrix\Bizproc\Public\Command\DataView\SaveDataViewCommandHandler;
use Bitrix\Bizproc\Public\DataView\Dto\SourceDescriptor;
use Bitrix\Bizproc\Public\DataView\Dto\SourceField;
use Bitrix\Bizproc\Public\DataView\Dto\SourceRef;
use Bitrix\Bizproc\Public\DataView\Dto\SourceRelation;
use Bitrix\Bizproc\Public\DataView\Dto\StampConstantDescriptor;
use Bitrix\Bizproc\Public\DataView\Exception\KeyNotIndexedException;
use Bitrix\Bizproc\Public\DataView\Exception\KeyNotJoinableException;
use Bitrix\Bizproc\Public\DataView\Exception\KeyTypeMismatchException;
use Bitrix\Bizproc\Public\DataView\Exception\RecomputeInProgressException;
use Bitrix\Bizproc\Public\DataView\Exception\RowLimitExceededException;
use Bitrix\Bizproc\Public\DataView\Exception\SourceUnavailableException;
use Bitrix\Bizproc\Public\DataView\Service\PreviewService;
use Bitrix\Bizproc\Public\Feature\DataView\DataViewFlag;
use Bitrix\Bizproc\Public\Provider\DataViewProvider;
use Bitrix\Bizproc\Public\Provider\Params\StorageType\StorageTypeFilter;
use Bitrix\Bizproc\Public\Provider\Params\StorageType\StorageTypeSelect;
use Bitrix\Bizproc\Public\Provider\Params\StorageType\StorageTypeSort;
use Bitrix\Bizproc\Public\Provider\SourceCatalogProvider;
use Bitrix\Bizproc\Public\Provider\StorageTypeProvider;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UI\PageNavigation;

Loc::loadMessages(__FILE__);

final class DataView extends Controller
{
	public const ERROR_ACCESS_DENIED = 'ACCESS_DENIED';
	public const ERROR_VALIDATION_FAILED = 'DATA_VIEW_VALIDATION_FAILED';
	public const ERROR_ROWS_LIMIT_EXCEEDED = 'DATA_VIEW_ROWS_LIMIT_EXCEEDED';
	public const ERROR_SOURCE_UNAVAILABLE = 'DATA_VIEW_SOURCE_UNAVAILABLE';
	public const ERROR_RECOMPUTE_IN_PROGRESS = 'DATA_VIEW_RECOMPUTE_IN_PROGRESS';
	public const ERROR_UNEXPECTED = 'DATA_VIEW_UNEXPECTED_ERROR';

	protected function processBeforeAction(Action $action): bool
	{
		if (!Feature::isEnabled(DataViewFlag::class))
		{
			$this->addError($this->accessDeniedError());

			return false;
		}

		if (!(new TemplateAccessService())->canAccessTemplates())
		{
			$this->addError($this->accessDeniedError());

			return false;
		}

		return parent::processBeforeAction($action);
	}

	/**
	 * Every action carrying a template id takes it from the client, so the actor must hold the edit right
	 * on that template and the template must exist: an unlimited edit scope grants the right without
	 * reading the row. A missing or non-positive id means "no template context" and stays open.
	 */
	private function checkTemplateAccess(?int $templateId): bool
	{
		if ($templateId === null || $templateId <= 0)
		{
			return true;
		}

		if ($this->templateExists($templateId))
		{
			return $this->checkTemplateEditRight($templateId);
		}

		$this->addError($this->accessDeniedError());

		return false;
	}

	/**
	 * The owner id stored on a view is not client input: an owner template deleted since must not lock the
	 * view out of reading and deletion, so existence is not asked of it.
	 */
	private function checkTemplateEditRight(?int $templateId): bool
	{
		if ($templateId === null || $templateId <= 0 || (new TemplateAccessService())->canEdit($templateId))
		{
			return true;
		}

		$this->addError($this->accessDeniedError());

		return false;
	}

	private function templateExists(int $templateId): bool
	{
		return (bool)Container::getWorkflowTemplateRepository()?->exists($templateId);
	}

	private function checkStorageEditRight(int $storageTypeId): bool
	{
		if ((new TemplateAccessService())->canEditStorage($storageTypeId))
		{
			return true;
		}

		$this->addError($this->accessDeniedError());

		return false;
	}

	/**
	 * A save answers for both owners it touches: the one sent by the client and the one already stored on
	 * the view it overwrites, which SaveDataViewCommandHandler inherits when the client sends none.
	 */
	private function checkSaveAccess(?int $ownerTemplateId, ?int $storageTypeId): bool
	{
		return $this->checkTemplateAccess($ownerTemplateId)
			&& $this->checkStorageEditRight((int)$storageTypeId)
		;
	}

	/**
	 * @return array{id: ?int, storageTypeId: int, status: string, materializedAt: ?string, rowsCount: int}|null
	 */
	public function saveAction(
		array $definition,
		string $title = '',
		?string $code = null,
		?int $storageTypeId = null,
		?string $description = null,
		?int $ownerTemplateId = null,
		?string $ownerActivityName = null,
	): ?array
	{
		if (!$this->checkSaveAccess($ownerTemplateId, $storageTypeId))
		{
			return null;
		}

		$actorId = (int)CurrentUser::get()->getId();

		try
		{
			$data = (new SaveDataViewCommandHandler())(
				new SaveDataViewCommand(
					actorId: $actorId,
					title: $title,
					code: $code,
					storageTypeId: $storageTypeId,
					definition: $definition,
					description: $description,
					ownerTemplateId: $ownerTemplateId,
					ownerActivityName: $ownerActivityName,
				)
			);
		}
		catch (DataViewMaterializeFailedException $exception)
		{
			$this->addError($this->materializeFailedError($exception));

			return null;
		}
		catch (DataViewValidationException $exception)
		{
			$this->addErrors($this->validationErrors($exception->getErrors()));

			return null;
		}
		catch (KeyNotIndexedException)
		{
			$this->addError($this->keyNotIndexedError());

			return null;
		}
		catch (KeyNotJoinableException)
		{
			$this->addError($this->keyNotJoinableError());

			return null;
		}
		catch (KeyTypeMismatchException)
		{
			$this->addError($this->keyMismatchError());

			return null;
		}
		catch (InvalidDataViewDefinitionException $exception)
		{
			$this->addError($this->definitionError($exception));

			return null;
		}
		catch (RowLimitExceededException)
		{
			$this->addError($this->rowsLimitError());

			return null;
		}
		catch (SourceUnavailableException)
		{
			$this->addError($this->sourceUnavailableError());

			return null;
		}
		catch (RecomputeInProgressException)
		{
			$this->addError($this->recomputeInProgressError());

			return null;
		}
		catch (\Throwable $exception)
		{
			$this->addError($this->unexpectedError($exception));

			return null;
		}

		/** @var DataViewEntity $view */
		$view = $data['view'];

		return [
			'id' => $view->getId(),
			'storageTypeId' => $view->getStorageTypeId(),
			'status' => $view->getStatus()->value,
			'materializedAt' => $this->formatTimestamp($view->getMaterializedAt()),
			'rowsCount' => (int)$data['rowsCount'],
		];
	}

	public function previewAction(
		array $definition,
		int $limit = PreviewService::MAX_PREVIEW_ROWS,
		?int $page = null,
		int $pageSize = PreviewService::DEFAULT_PAGE_SIZE,
		?int $ownerTemplateId = null,
	): ?array
	{
		if (!$this->checkTemplateAccess($ownerTemplateId))
		{
			return null;
		}

		$actorId = (int)CurrentUser::get()->getId();

		try
		{
			$service = new PreviewService();

			return $page !== null
				? $service->previewPage($definition, $page, $pageSize, $actorId, $ownerTemplateId)
				: $service->preview($definition, $limit, $actorId, $ownerTemplateId);
		}
		catch (DataViewValidationException $exception)
		{
			$this->addErrors($this->validationErrors($exception->getErrors()));
		}
		catch (KeyNotIndexedException)
		{
			$this->addError($this->keyNotIndexedError());
		}
		catch (KeyNotJoinableException)
		{
			$this->addError($this->keyNotJoinableError());
		}
		catch (KeyTypeMismatchException)
		{
			$this->addError($this->keyMismatchError());
		}
		catch (InvalidDataViewDefinitionException $exception)
		{
			$this->addError($this->definitionError($exception));
		}
		catch (RowLimitExceededException)
		{
			$this->addError($this->rowsLimitError());
		}
		catch (SourceUnavailableException)
		{
			$this->addError($this->sourceUnavailableError());
		}
		catch (\Throwable $exception)
		{
			$this->addError($this->unexpectedError($exception));
		}

		return null;
	}

	public function listAction(int $templateId, string $activityName): ?array
	{
		if (!$this->checkTemplateAccess($templateId))
		{
			return null;
		}

		try
		{
			return ['items' => (new DataViewProvider())->getListByOwner($templateId, $activityName)];
		}
		catch (\Throwable $exception)
		{
			$this->addError($this->unexpectedError($exception));

			return null;
		}
	}

	/**
	 * Lists storages allowed as data view sources: the storage catalog without data view
	 * result storages (including the edited one) — ColumnResolver rejects them on preview/save.
	 */
	public function listSourceStoragesAction(PageNavigation $navigation): ?StorageTypeCollection
	{
		try
		{
			$filter = [];
			$dataViewStorageTypeIds = Container::getDataViewRepository()?->getStorageTypeIds() ?? [];
			if ($dataViewStorageTypeIds !== [])
			{
				$filter['!ID'] = $dataViewStorageTypeIds;
			}

			return (new StorageTypeProvider())->getList(new GridParams(
				pager: Pager::buildFromPageNavigation($navigation),
				filter: new StorageTypeFilter($filter),
				sort: new StorageTypeSort(['ID' => 'ASC']),
				select: new StorageTypeSelect([]),
			));
		}
		catch (\Throwable $exception)
		{
			$this->addError($this->unexpectedError($exception));

			return null;
		}
	}

	public function getAction(int $storageTypeId): ?array
	{
		if (!$this->checkStorageEditRight($storageTypeId))
		{
			return null;
		}

		try
		{
			$data = (new DataViewProvider())->getByStorageTypeId($storageTypeId);
		}
		catch (InvalidDataViewDefinitionException)
		{
			$this->addError($this->notAViewError());

			return null;
		}
		catch (\Throwable $exception)
		{
			$this->addError($this->unexpectedError($exception));

			return null;
		}

		if ($data === null)
		{
			$this->addError($this->notAViewError());

			return null;
		}

		return [
			'id' => $data['id'],
			'storageTypeId' => $data['storageTypeId'],
			'title' => $data['title'],
			'description' => $data['description'],
			'definition' => $data['definition'],
			'status' => $data['status'],
			'errorText' => $data['errorText'],
			'materializedAt' => $data['materializedAt']?->toString(),
		];
	}

	public function deleteAction(int $storageTypeId): ?array
	{
		if (!$this->checkStorageEditRight($storageTypeId))
		{
			return null;
		}

		try
		{
			(new DeleteDataViewCommandHandler())(new DeleteDataViewCommand($storageTypeId));
		}
		catch (InvalidDataViewDefinitionException)
		{
			$this->addError($this->notAViewError());

			return null;
		}
		catch (RecomputeInProgressException)
		{
			$this->addError($this->recomputeInProgressError());

			return null;
		}
		catch (\Throwable $exception)
		{
			$this->addError($this->unexpectedError($exception));

			return null;
		}

		return ['deleted' => true];
	}

	public function getSourcesAction(?int $templateId = null): ?array
	{
		if (!$this->checkTemplateAccess($templateId))
		{
			return null;
		}

		try
		{
			$sources = (new SourceCatalogProvider())->getSources((int)CurrentUser::get()->getId(), $templateId);
		}
		catch (\Throwable $exception)
		{
			$this->addError($this->unexpectedError($exception));

			return null;
		}

		return ['sources' => array_map(static fn (SourceDescriptor $source): array => $source->toArray(), $sources)];
	}

	public function getStampConstantsAction(?int $templateId = null): ?array
	{
		if (!$this->checkTemplateAccess($templateId))
		{
			return null;
		}

		try
		{
			$constants = (new SourceCatalogProvider())->getStampConstants(
				(int)CurrentUser::get()->getId(),
				$templateId,
			);
		}
		catch (\Throwable $exception)
		{
			$this->addError($this->unexpectedError($exception));

			return null;
		}

		return [
			'constants' => array_map(
				static fn (StampConstantDescriptor $constant): array => $constant->toArray(),
				$constants,
			),
		];
	}

	public function getSourceSchemaAction(
		string $module,
		string $entity,
		array $params = [],
		?int $templateId = null,
	): ?array
	{
		if (!$this->checkTemplateAccess($templateId))
		{
			return null;
		}

		try
		{
			$source = SourceRef::fromArray(['module' => $module, 'entity' => $entity, 'params' => $params]);

			$schema = (new SourceCatalogProvider())->getSchema(
				$source,
				(int)CurrentUser::get()->getId(),
				$templateId,
			);
		}
		catch (SourceUnavailableException)
		{
			$this->addError($this->sourceUnavailableError());

			return null;
		}
		catch (\Throwable $exception)
		{
			$this->addError($this->unexpectedError($exception));

			return null;
		}

		return [
			'fields' => array_map(static fn (SourceField $field): array => $field->toArray(), $schema['fields']),
			'relations' => array_map(
				static fn (SourceRelation $relation): array => $relation->toArray(),
				$schema['relations'],
			),
		];
	}

	private function accessDeniedError(): Error
	{
		return new Error(
			Loc::getMessage('BIZPROC_INFRASTRUCTURE_CONTROLLER_DATAVIEW_ACCESS_DENIED') ?? '',
			self::ERROR_ACCESS_DENIED,
		);
	}

	/**
	 * @param array<int, array{code: string, field: string, message: string}> $errors
	 * @return Error[]
	 */
	private function validationErrors(array $errors): array
	{
		$mapped = [];
		foreach ($errors as $error)
		{
			$mapped[] = new Error(
				(string)($error['message'] ?? ''),
				self::ERROR_VALIDATION_FAILED,
				[
					'field' => $error['field'] ?? null,
					'code' => $error['code'] ?? null,
				],
			);
		}

		return $mapped !== [] ? $mapped : [$this->validationError()];
	}

	/**
	 * Definition violations share the validation error code and differ only by the message the user
	 * can act upon: a foreign template source names the reason, anything else stays generic.
	 */
	private function definitionError(InvalidDataViewDefinitionException $exception): Error
	{
		return $exception->getViolation() === InvalidDataViewDefinitionException::VIOLATION_TEMPLATE_SOURCE_FOREIGN
			? $this->templateSourceForeignError()
			: $this->validationError();
	}

	private function validationError(): Error
	{
		return new Error(
			Loc::getMessage('BIZPROC_INFRASTRUCTURE_CONTROLLER_DATAVIEW_VALIDATION_FAILED'),
			self::ERROR_VALIDATION_FAILED,
		);
	}

	private function templateSourceForeignError(): Error
	{
		return new Error(
			Loc::getMessage('BIZPROC_INFRASTRUCTURE_CONTROLLER_DATAVIEW_ERR_006') ?? '',
			self::ERROR_VALIDATION_FAILED,
		);
	}

	private function notAViewError(): Error
	{
		return new Error(
			Loc::getMessage('BIZPROC_INFRASTRUCTURE_CONTROLLER_DATAVIEW_NOT_A_VIEW'),
			self::ERROR_VALIDATION_FAILED,
		);
	}

	private function rowsLimitError(): Error
	{
		return new Error(
			Loc::getMessage('BIZPROC_INFRASTRUCTURE_CONTROLLER_DATAVIEW_ERR_002'),
			self::ERROR_ROWS_LIMIT_EXCEEDED,
		);
	}

	private function keyMismatchError(): Error
	{
		return new Error(
			Loc::getMessage('BIZPROC_INFRASTRUCTURE_CONTROLLER_DATAVIEW_ERR_001'),
			self::ERROR_VALIDATION_FAILED,
		);
	}

	private function keyNotJoinableError(): Error
	{
		return new Error(
			Loc::getMessage('BIZPROC_INFRASTRUCTURE_CONTROLLER_DATAVIEW_KEY_NOT_JOINABLE'),
			self::ERROR_VALIDATION_FAILED,
		);
	}

	private function keyNotIndexedError(): Error
	{
		return new Error(
			Loc::getMessage('BIZPROC_INFRASTRUCTURE_CONTROLLER_DATAVIEW_KEY_NOT_INDEXED'),
			self::ERROR_VALIDATION_FAILED,
		);
	}

	private function sourceUnavailableError(): Error
	{
		return new Error(
			Loc::getMessage('BIZPROC_INFRASTRUCTURE_CONTROLLER_DATAVIEW_ERR_004'),
			self::ERROR_SOURCE_UNAVAILABLE,
		);
	}

	private function materializeFailedError(DataViewMaterializeFailedException $exception): Error
	{
		$cause = $exception->getPrevious();
		$error = match (true)
		{
			$cause instanceof RowLimitExceededException => $this->rowsLimitError(),
			$cause instanceof SourceUnavailableException => $this->sourceUnavailableError(),
			$cause instanceof RecomputeInProgressException => $this->recomputeInProgressError(),
			default => $this->unexpectedError($exception),
		};

		return new Error(
			(string)$error->getMessage(),
			$error->getCode(),
			['storageTypeId' => $exception->getView()->getStorageTypeId()],
		);
	}

	private function recomputeInProgressError(): Error
	{
		return new Error(
			Loc::getMessage('BIZPROC_INFRASTRUCTURE_CONTROLLER_DATAVIEW_RECOMPUTE_IN_PROGRESS'),
			self::ERROR_RECOMPUTE_IN_PROGRESS,
		);
	}

	private function unexpectedError(\Throwable $exception): Error
	{
		Application::getInstance()->getExceptionHandler()->writeToLog($exception);

		return new Error(
			Loc::getMessage('BIZPROC_INFRASTRUCTURE_CONTROLLER_DATAVIEW_UNEXPECTED_ERROR'),
			self::ERROR_UNEXPECTED,
		);
	}

	private function formatTimestamp(?int $timestamp): ?string
	{
		if ($timestamp === null)
		{
			return null;
		}

		return DateTime::createFromTimestamp($timestamp)->toString();
	}
}
