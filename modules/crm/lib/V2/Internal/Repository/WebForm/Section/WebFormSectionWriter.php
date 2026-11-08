<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\WebForm\Section;

use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormChangeSet;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\Result;

/**
 * Writes one section of a CRM form into its own storage, mirroring WebFormSectionLoader.
 *
 * A writer never rewrites a section the change set does not touch. Errors are returned, never
 * swallowed: the caller stops the write cycle on the first unsuccessful result.
 *
 * @internal
 */
abstract class WebFormSectionWriter
{
	abstract public function getSectionName(): string;

	/**
	 * Writes the section when the change set touches it, otherwise leaves the storage untouched.
	 */
	final public function write(int $formId, WebFormChangeSet $changeSet): Result
	{
		$result = new Result();
		if ($formId <= 0)
		{
			return $result->addError(
				new Error(sprintf('Invalid web form id `%d`', $formId), $this->getSectionName()),
			);
		}

		if (!$changeSet->touches($this->getSectionName()))
		{
			return $result;
		}

		return $this->writeTouched($formId, $changeSet);
	}

	/**
	 * Writes the touched section. A section that also carries form row columns has to tell its own
	 * paths apart with WebFormChangeSet::has(): the base row is written by FormWriteRepository.
	 */
	abstract protected function writeTouched(int $formId, WebFormChangeSet $changeSet): Result;

	/**
	 * Copies errors of a write into the section result and tells whether the write succeeded.
	 */
	final protected static function copyErrors(Result $source, Result $target): bool
	{
		if ($source->isSuccess())
		{
			return true;
		}

		$target->addErrors($source->getErrors());

		return false;
	}

	/**
	 * Removes every row of the form from the table in one statement, the way legacy starts a full
	 * section rewrite.
	 *
	 * None of the tables rewritten this way declares a delete handler, so nothing of the section
	 * depends on the rows leaving one by one. Nothing is returned because nothing can be answered:
	 * a failed statement is raised by exception, the way DataManager::delete() raises it.
	 *
	 * @param class-string<DataManager> $dataManager
	 */
	final protected static function deleteFormRows(string $dataManager, int $formId): void
	{
		$dataManager::deleteByFilter(['=FORM_ID' => $formId]);
	}

	final protected static function toFlag(bool $value): string
	{
		return $value ? 'Y' : 'N';
	}
}
