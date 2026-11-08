<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Landing;

use Bitrix\Crm\V2\Internal\Exception\WebForm\FormModuleUnavailableException;
use Bitrix\Crm\WebForm\Internals\LandingTable;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

/**
 * The single point CRM V2 asks for the landing page of a web form through.
 *
 * A form of the default type is published as a landing page, and the page is created together with
 * the form and removed together with it. Changing a form never touches its page: the changes reach
 * the visitor through the rebuilt script the page loads at runtime.
 *
 * The module publishing the page is not called from here: what this class touches is LandingTable,
 * the bridge of the crm module, and the call into Bitrix\Landing is made by the legacy integration
 * of crm the bridge itself reaches - on creation directly, on removal through the delete handler of
 * the bridge row. So the only knowledge of the other module kept here is whether it is installed at
 * all.
 *
 * Unlike the legacy write path, an unavailable module is not silently tolerated here: it answers
 * {@see isAvailable()} with false so the scenario can refuse before the form row is written, and
 * a page that could not be created reports the failure instead of leaving a form without a page
 * and without an error.
 *
 * @internal
 */
class FormPage
{
	public const MODULE_ID = 'landing';

	/** Key of the created page id in the data of {@see createPage()}. */
	public const DATA_LANDING_ID = 'landingId';

	/**
	 * Tells whether the module publishing the page is available. A portal without it can not hold a
	 * page for a new form at all.
	 */
	public function isAvailable(): bool
	{
		return Loader::includeModule(self::MODULE_ID);
	}

	/**
	 * Creates the landing page of a newly created form and the bridge row pointing at it. The page
	 * id is in the result data.
	 *
	 * The page needs the script package of the form, so the packages are built before this call.
	 *
	 * @throws FormModuleUnavailableException the module is gone between the check of the scenario
	 *         and the write; a form of the default type without a page is not a valid outcome.
	 */
	public function createPage(int $formId, ?string $formName = null): Result
	{
		$result = new Result();
		if (!$this->isAvailable())
		{
			throw new FormModuleUnavailableException(self::MODULE_ID);
		}

		$landingId = (int)LandingTable::createLanding($formId, $formName);
		if ($landingId <= 0)
		{
			return $result->addError(
				new Error(sprintf('Landing page of the CRM form `%d` was not created', $formId)),
			);
		}

		return $result->setData([self::DATA_LANDING_ID => $landingId]);
	}

	/**
	 * Removes the bridge rows of the form and, through them, the landing pages themselves with
	 * their blocks and files.
	 *
	 * The bridge is a table of the crm module and is cleared even when the module publishing the
	 * page is gone, the way the legacy delete cascade clears it: the removal of the page itself is
	 * guarded by the bridge and bypasses the rights of the landing module.
	 */
	public function deletePages(int $formId): Result
	{
		$result = new Result();
		$rows = LandingTable::getList([
			'select' => ['ID'],
			'filter' => ['=FORM_ID' => $formId],
		]);
		foreach ($rows as $row)
		{
			$deleteResult = LandingTable::delete((int)$row['ID']);
			if (!$deleteResult->isSuccess())
			{
				return $result->addErrors($deleteResult->getErrors());
			}
		}

		return $result;
	}
}
