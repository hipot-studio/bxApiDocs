<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Service;

use Bitrix\Bizproc\Internal\Entity\DataView\DataView;
use Bitrix\Bizproc\Internal\Service\DataView\ColumnResolver;
use Bitrix\Bizproc\Internal\Service\DataView\CombineEngine;
use Bitrix\Bizproc\Internal\Service\DataView\DataViewValidator;
use Bitrix\Bizproc\Internal\Service\DataView\ValuePresenter;
use Bitrix\Bizproc\Public\DataView\Exception\KeyTypeMismatchException;
use Bitrix\Bizproc\Public\DataView\Exception\RowLimitExceededException;
use Bitrix\Bizproc\Public\Provider\SourceCatalogProvider;
use Bitrix\Main\DI\ServiceLocator;

final class PreviewService
{
	public const MAX_PREVIEW_ROWS = 50;

	public const DEFAULT_PAGE_SIZE = 20;

	private CombineEngine $combineEngine;
	private DataViewValidator $validator;
	private ValuePresenter $valuePresenter;
	private SourceCatalogProvider $sourceCatalog;

	public function __construct(
		?CombineEngine $combineEngine = null,
		?DataViewValidator $validator = null,
		?ValuePresenter $valuePresenter = null,
	)
	{
		$locator = ServiceLocator::getInstance();
		$this->combineEngine = $combineEngine ?? $locator->get('bizproc.service.dataView.combineEngine');
		$this->validator = $validator ?? $locator->get('bizproc.service.dataView.validator');
		$this->valuePresenter = $valuePresenter ?? $locator->get('bizproc.service.dataView.valuePresenter');
		$this->sourceCatalog = new SourceCatalogProvider();
	}

	public function preview(array $definition, int $limit, int $actorId, ?int $ownerTemplateId = null): array
	{
		$columns = $this->resolveColumns($definition, $actorId, $ownerTemplateId);
		$definition['columns'] = $columns;

		$view = new DataView(id: null, storageTypeId: 0, definition: $definition);
		$result = $this->combineEngine->combine($view, $actorId, withComputedColumns: false);

		$cap = max(1, min($limit, self::MAX_PREVIEW_ROWS));

		$rows = [];
		foreach ($result->getRows() as $index => $row)
		{
			if ($index >= $cap)
			{
				break;
			}

			$rows[] = $row->values;
		}

		$rows = $this->combineEngine->computeFormulas($definition, $rows, $actorId);
		$this->applyPresenterOverlay($definition, $rows);

		return [
			'columns' => $this->mapColumns($columns),
			'rows' => $rows,
			'truncated' => $result->count() > $cap,
		];
	}

	public function previewPage(
		array $definition,
		int $page,
		int $pageSize,
		int $actorId,
		?int $ownerTemplateId = null,
	): array
	{
		$columns = $this->resolveColumns($definition, $actorId, $ownerTemplateId);
		$definition['columns'] = $columns;

		$view = new DataView(id: null, storageTypeId: 0, definition: $definition);
		$result = $this->combineEngine->combine($view, $actorId, withComputedColumns: false);

		$page = max(1, $page);
		$pageSize = max(1, min($pageSize, self::MAX_PREVIEW_ROWS));
		$offset = ($page - 1) * $pageSize;
		$totalRows = $result->count();

		$rows = [];
		foreach ($result->getRows() as $index => $row)
		{
			if ($index < $offset)
			{
				continue;
			}
			if ($index >= $offset + $pageSize)
			{
				break;
			}

			$rows[] = $row->values;
		}

		$rows = $this->combineEngine->computeFormulas($definition, $rows, $actorId);
		$this->applyPresenterOverlay($definition, $rows);

		return [
			'columns' => $this->mapColumns($columns),
			'rows' => $rows,
			'page' => $page,
			'pageSize' => $pageSize,
			'totalRows' => $totalRows,

			'truncated' => $totalRows >= $this->combineEngine->getRowLimit(),
		];
	}

	/**
	 * Preview validates the definition the editor holds, so the template it is being written for is
	 * the only owner its template-scoped sources may belong to.
	 *
	 * @return array<int, array{code: string, source: string, title: string, type: string, multiple: bool}>
	 */
	private function resolveColumns(array $definition, int $actorId, ?int $ownerTemplateId): array
	{
		ColumnResolver::assertTemplateSourcesOwned($definition, $ownerTemplateId);
		$this->validator->assertReferencedViewsOwned($definition, $ownerTemplateId);
		$this->sourceCatalog->assertDefinitionSourcesAvailable($definition, $actorId, $ownerTemplateId);

		return $this->validator->assertValid($definition);
	}

	/**
	 * @param array<int, array{code: string, title: string, type: string, multiple: bool}> $columns
	 * @return array<int, array{code: string, title: string, type: string}>
	 */
	private function mapColumns(array $columns): array
	{
		return array_map(
			static fn(array $column): array => [
				'code' => $column['code'],
				'title' => $column['title'],
				'type' => $column['type'],
			],
			$columns,
		);
	}

	/**
	 * Stamp cells in the shape they are shown in. Presentation may fail on a source that has meanwhile
	 * become unavailable: the preview then keeps the raw values instead of failing as a whole.
	 */
	private function applyPresenterOverlay(array $definition, array &$rows): void
	{
		try
		{
			$overlay = $this->valuePresenter->present($definition, $rows);
		}
		catch (\Throwable)
		{
			return;
		}

		foreach ($overlay as $rowKey => $cells)
		{
			if (!isset($rows[$rowKey]))
			{
				continue;
			}

			foreach ($cells as $columnCode => $label)
			{
				$rows[$rowKey][$columnCode] = $label;
			}
		}
	}
}
