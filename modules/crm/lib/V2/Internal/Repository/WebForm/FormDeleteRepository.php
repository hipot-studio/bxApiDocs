<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\WebForm;

use Bitrix\Crm\Ads\AdsForm;
use Bitrix\Crm\UI\Webpack;
use Bitrix\Crm\V2\Internal\Integration\Landing\FormPage;
use Bitrix\Crm\WebForm\Form;
use Bitrix\Crm\WebForm\Internals\FieldDependenceTable;
use Bitrix\Crm\WebForm\Internals\FieldTable;
use Bitrix\Crm\WebForm\Internals\FormCounterDailyTable;
use Bitrix\Crm\WebForm\Internals\FormCounterTable;
use Bitrix\Crm\WebForm\Internals\FormStartEditTable;
use Bitrix\Crm\WebForm\Internals\FormTable;
use Bitrix\Crm\WebForm\Internals\PresetFieldTable;
use Bitrix\Crm\WebForm\Internals\ResultTable;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\Result;

/**
 * Removes a CRM form and the data the legacy cascade removes with it.
 *
 * The composition of the cascade is reproduced literally, orphans included: what is not listed
 * here stays behind - the agreements, the responsible queue, the dependency groups, the rotation
 * option and the background image. The built package goes too, the way the legacy delete removes
 * it after the row: the only thing naming that package is the form, so a package left behind is a
 * file served for a form nobody can reach any more.
 *
 * The two steps are told apart on purpose. The cascade runs before the row the way the legacy
 * delete event does, and the row survives an unsuccessful cascade, so the delete can be repeated.
 * Which of the two failed is what the service turns into the operation of its refusal.
 *
 * Not final: the write scenario takes the repository through the constructor and a test replaces it.
 *
 * @internal
 */
class FormDeleteRepository
{
	/**
	 * The page of the form belongs to the composition the cascade removes, so the module publishing
	 * it is reached from here and not from the scenario.
	 */
	private readonly FormPage $formPage;

	public function __construct(?FormPage $formPage = null)
	{
		$this->formPage = $formPage ?? new FormPage();
	}

	/**
	 * Removes everything the form owns except its own row.
	 *
	 * The cascade runs to its end and only then answers: stopping halfway would leave more of the
	 * form behind than finishing does. An unsuccessful result carries the errors of the first step
	 * that failed.
	 */
	public function deleteCascade(int $formId): Result
	{
		$result = new Result();

		// The links of the form in the ad accounts go through the legacy mechanism owning their
		// external subscriptions, the way IntegrationSectionWriter does: the same transitional
		// exception, bounded to these two places and lifted when the mechanism moves into the layer.
		// It answers with nothing, the way legacy leaves it: whether a failed unsubscribe should stop
		// a delete is an open question of the audit, not a decision of this task.
		$form = new Form();
		$form->setId($formId);
		$form->getIntegration()->delete();
		AdsForm::unlinkForm($formId);

		// The steps keep the order of the legacy cascade, the package of the form after them. Only
		// the ones that can answer join the list: a set removal and the daily statistics report a
		// failure by raising instead.
		$results = [];
		$results[] = $this->formPage->deletePages($formId);
		self::deleteRowsOfForm(FieldTable::class, $formId);
		self::deleteRowsOfForm(FieldDependenceTable::class, $formId);
		$results[] = PresetFieldTable::delete(['FORM_ID' => $formId]);
		FormCounterDailyTable::deleteByFormId($formId);
		$results[] = FormStartEditTable::delete(['FORM_ID' => $formId]);
		self::deleteRowsOfForm(FormCounterTable::class, $formId);
		$results[] = self::deleteResultsOfForm($formId);
		$results[] = self::deletePackageOfForm($formId);

		foreach ($results as $stepResult)
		{
			if (!$stepResult->isSuccess())
			{
				return $result->addErrors($stepResult->getErrors());
			}
		}

		return $result;
	}

	/**
	 * Removes the row of the form. Called after the cascade, the way the legacy delete does it.
	 */
	public function deleteFormRow(int $formId): Result
	{
		return FormTable::delete($formId);
	}

	/**
	 * Removes every row the form owns in the table in one statement. None of the tables reached this
	 * way declares a delete handler, so the composition of the cascade does not depend on the rows
	 * leaving one by one. Nothing is returned because nothing can be answered: a failed statement is
	 * raised by exception, the way DataManager::delete() raises it.
	 *
	 * @param class-string<DataManager> $dataManager
	 */
	private static function deleteRowsOfForm(string $dataManager, int $formId): void
	{
		$dataManager::deleteByFilter(['=FORM_ID' => $formId]);
	}

	/**
	 * Removes the package the form is published as, through the same removal the legacy delete
	 * calls: the file first, then the registry entry pointing at it. A form that was never
	 * published carries no entry, and that is not a failure.
	 */
	private static function deletePackageOfForm(int $formId): Result
	{
		return Webpack\Form::instance($formId)->delete();
	}

	/**
	 * Removes the results of the form one by one: ResultTable answers a delete with a handler of its
	 * own, and that handler is what clears the entities of the result, so a set removal would leave
	 * them behind.
	 *
	 * The rows are read in a stream and removed as they arrive, the way legacy reads them. Building
	 * the whole list of identifiers first would spend the memory of the selection before removing a
	 * single row, and a form with a large number of submissions would stop being deletable at all:
	 * the refusal would come before any progress, and every repeat would start from the same place.
	 */
	private static function deleteResultsOfForm(int $formId): Result
	{
		$result = new Result();
		$rows = ResultTable::query()
			->setSelect(['ID'])
			->where('FORM_ID', $formId)
			->exec()
		;
		while ($row = $rows->fetch())
		{
			$deleteResult = ResultTable::delete((int)$row['ID']);
			if (!$deleteResult->isSuccess())
			{
				return $result->addErrors($deleteResult->getErrors());
			}
		}

		return $result;
	}
}
