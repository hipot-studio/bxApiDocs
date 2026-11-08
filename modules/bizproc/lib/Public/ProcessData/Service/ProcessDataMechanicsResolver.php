<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\ProcessData\Service;

use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateSettingsTable;

final class ProcessDataMechanicsResolver
{
	public const MARKER = 'PROCESS_DATA';

	public const MARKER_VALUE = 'Y';

	/** @var array<int, bool> The stored marker of a template, kept for the hit. */
	private static array $markerCache = [];

	/**
	 * The marker of a template created by the new designer. Only the server puts it into the fields, so a
	 * value in memory is trusted; otherwise the stored setting answers, once per template and hit.
	 */
	public function isMarked(array $fields, int $templateId = 0): bool
	{
		if (($fields['TEMPLATE_SETTINGS'][self::MARKER] ?? null) === self::MARKER_VALUE)
		{
			return true;
		}

		if ($templateId <= 0)
		{
			return false;
		}

		return self::$markerCache[$templateId] ??= self::readMarker($templateId);
	}

	/**
	 * Every write able to change the stored marker calls it, so a template marked or unmarked within the
	 * hit is read anew. Without a template it drops the whole memory: a delete by filter names no single
	 * template.
	 */
	public static function clearMarkerCache(?int $templateId = null): void
	{
		if ($templateId === null)
		{
			self::$markerCache = [];

			return;
		}

		unset(self::$markerCache[$templateId]);
	}

	/**
	 * The settings a copy of a template inherits. The marker of the new mechanics is the only one of them: the
	 * copy of a template created by the node designer stays on the new mechanics. The state of the feature does
	 * not enter it - the pairs of the source are initialized by the marker either way, and a copy without it
	 * would run by another semantics than the template it was made of. The rest of the settings belong to the
	 * source alone - the categories it is shown in, the system agent it came from - and a copy earns them anew.
	 */
	public function settingsToCopy(array $sourceFields, int $sourceTemplateId): array
	{
		$settings = (array)($sourceFields['TEMPLATE_SETTINGS'] ?? []);

		if ($this->isMarked($sourceFields, $sourceTemplateId))
		{
			$settings[self::MARKER] = self::MARKER_VALUE;
		}

		return $settings;
	}

	/**
	 * The marker of the new mechanics travels only into a template being created by an import: an import over
	 * an existing template neither sets nor removes it. The marker of the file decides it, neither the feature
	 * nor the kind of the import: an import dropping it would silently turn the process into a legacy one.
	 */
	public function settingsForImport(array $templateFields, int $id): array
	{
		if ($id > 0 || !$this->isMarked($templateFields))
		{
			return [];
		}

		return [self::MARKER => self::MARKER_VALUE];
	}

	public function isNewMechanicsForFields(array $fields, int $templateId = 0): bool
	{
		if ($this->resolveType($fields, $templateId) !== WorkflowTemplateType::Nodes->value)
		{
			return false;
		}

		return $this->isMarked($fields, $templateId);
	}

	public function isNewMechanics(int $templateId): bool
	{
		$row = WorkflowTemplateTable::getRow([
			'select' => ['ID', 'TYPE'],
			'filter' => ['=ID' => $templateId],
		]);

		return $row !== null && $this->isNewMechanicsForFields($row, $templateId);
	}

	private static function readMarker(int $templateId): bool
	{
		$row = WorkflowTemplateSettingsTable::getRow([
			'select' => ['VALUE'],
			'filter' => ['=TEMPLATE_ID' => $templateId, '=NAME' => self::MARKER],
		]);

		return ($row['VALUE'] ?? null) === self::MARKER_VALUE;
	}

	/**
	 * getTemplateType() answers null when the fields do not carry enough data and the template exists: the
	 * stored type stands then, an existing template is never taken for a new one.
	 */
	private function resolveType(array $fields, int $templateId): ?string
	{
		$type = $fields['TYPE'] ?? \CBPWorkflowTemplateLoader::getLoader()->getTemplateType($fields, $templateId);

		if ($type === null && $templateId > 0)
		{
			$type = WorkflowTemplateTable::getRow([
				'select' => ['TYPE'],
				'filter' => ['=ID' => $templateId],
			])['TYPE'] ?? null;
		}

		return $type;
	}
}
