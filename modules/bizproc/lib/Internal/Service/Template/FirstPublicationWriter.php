<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Template;

use Bitrix\Bizproc\Internal\Service\Pilot\CommonRevisionWriter;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\Type\DateTime;

/**
 * The moment a template first became a process an employee can start. The start list calls a process
 * new for a fixed time after that moment, so the date is written once and never again: an edit, a
 * rename, a switch on and off, a repeated publication - none of them may push a template back into the
 * group of the new ones.
 *
 * The field belongs to the server on every path. Neither branch of the loader has a white list of
 * fields - {@see \CBPWorkflowTemplateLoader::prepareTplFields()} only drops TEMPLATE_SETTINGS - so an
 * incoming FIRST_PUBLISHED_AT would reach the ORM exactly as the caller sent it, a date of their
 * choosing, a date in the future or a null erasing the stored one. The field is therefore removed from
 * the fields of the caller first, and only this class is allowed to put one back.
 */
final class FirstPublicationWriter
{
	public const FIELD = 'FIRST_PUBLISHED_AT';

	public function __construct(
		private readonly CommonRevisionWriter $commonRevisionWriter = new CommonRevisionWriter(),
	)
	{
	}

	/**
	 * Fields of a template being created.
	 *
	 * @param bool $systemImport The transfer of a template rather than an act of publishing one.
	 * @param bool|null $markFirstPublication Intent of the caller. Null leaves the decision to
	 * $systemImport, so an ordinary user creation counts as a publication while a transfer does not.
	 */
	public function prepareForInsert(array $fields, bool $systemImport, ?bool $markFirstPublication): array
	{
		unset($fields[self::FIELD]);

		$shouldMark = $markFirstPublication ?? !$systemImport;
		if ($shouldMark && $this->mayAppearInStartList($fields) && $this->hasScheme($fields))
		{
			$fields[self::FIELD] = new DateTime();
		}

		return $fields;
	}

	/**
	 * Fields of a template being rewritten.
	 *
	 * Call it under the row lock of the template and before the write itself: the write stores the
	 * fingerprint of the new common scheme, and afterwards the question whether the template had a
	 * common version before this publication can no longer be answered.
	 */
	public function prepareForUpdate(int $templateId, array $fields, bool $publishesCommonVersion): array
	{
		unset($fields[self::FIELD]);

		if (!$publishesCommonVersion || !$this->hasScheme($fields))
		{
			return $fields;
		}

		$publishedAt = $this->readStoredDate($templateId);
		$hadCommonVersion = $this->commonRevisionWriter->hasCommonVersionForUpdate($templateId);

		if ($publishedAt === null && !$hadCommonVersion)
		{
			$fields[self::FIELD] = new DateTime();
		}

		return $fields;
	}

	/**
	 * The start list is narrowed by AUTO_EXECUTE and not by the type of the template: an auto-executed
	 * process is on the list as well, and a robot is cut off by this very value. The check only keeps
	 * the date away from templates the list can never show; it decides nothing about the order.
	 */
	private function mayAppearInStartList(array $fields): bool
	{
		$autoExecute = (int)($fields['AUTO_EXECUTE'] ?? \CBPDocumentEventType::None);

		return $autoExecute < \CBPDocumentEventType::Automation;
	}

	private function hasScheme(array $fields): bool
	{
		return array_key_exists('TEMPLATE', $fields) && !\CBPHelper::isEmptyValue($fields['TEMPLATE']);
	}

	private function readStoredDate(int $templateId): ?DateTime
	{
		$row = WorkflowTemplateTable::getRow([
			'select' => [self::FIELD],
			'filter' => ['=ID' => $templateId],
		]);

		$storedDate = $row[self::FIELD] ?? null;

		return $storedDate instanceof DateTime ? $storedDate : null;
	}
}
