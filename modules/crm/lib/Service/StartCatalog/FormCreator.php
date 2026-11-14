<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\StartCatalog;

use Bitrix\Crm\Model\Dynamic\Type;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\WebForm\Form;
use Bitrix\Crm\WebForm\Manager;
use Bitrix\Crm\WebForm\Options;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * A CRM form for a smart process that has none yet.
 *
 * The form is created already pointed at the smart process, so the employee is not left to find it
 * among the schemes of the portal; everything else is left to the editor, which is opened on it.
 */
class FormCreator
{
	public function create(int $entityTypeId): Result
	{
		$result = new Result();

		$type = $this->getType($entityTypeId);
		if ($type === null)
		{
			return $result->addError(
				new Error(Loc::getMessage('CRM_START_CATALOG_FORM_CREATOR_NO_TYPE')),
			);
		}

		$options = Options::create();
		$settings = $options->getArray();
		$settings['name'] = $this->getFormName($type);
		// the scheme of entities a form fills is how it addresses a smart process
		$settings['document']['scheme'] = $entityTypeId * 10;
		// a form that does not exist yet has no embedding of its own, and merge reads the views of it
		$settings['embedding']['views'] = [];

		$saveResult = $options->merge($settings)->save();
		if (!$saveResult->isSuccess())
		{
			return $result->addErrors($saveResult->getErrors());
		}

		$formId = (int)$options->getForm()->getId();
		if ($formId <= 0)
		{
			return $result->addError(
				new Error(Loc::getMessage('CRM_START_CATALOG_FORM_CREATOR_NOT_CREATED')),
			);
		}

		$this->activate($formId);

		return $result->setData([
			'formId' => $formId,
			'editUrl' => (string)Manager::getEditUrl($formId),
		]);
	}

	/**
	 * A new form is created switched off, and a switched off form accepts nothing and is not offered
	 * for binding. Activation goes through the same guard the form list uses, so a portal that has
	 * spent its limit of active forms keeps the form switched off and the editor tells why.
	 */
	private function activate(int $formId): void
	{
		if (Form::canActivateForm())
		{
			Form::activate($formId, true, Container::getInstance()->getContext()->getUserId());
		}
	}

	private function getFormName(Type $type): string
	{
		return (string)Loc::getMessage(
			'CRM_START_CATALOG_FORM_CREATOR_FORM_NAME',
			['#TITLE#' => (string)$type->getTitle()],
		);
	}

	private function getType(int $entityTypeId): ?Type
	{
		// a snapshot: the iterator of the collection is shared, and the caller may be walking it too
		foreach (Container::getInstance()->getDynamicTypesMap()->getTypesCollection()->getAll() as $type)
		{
			if ($type->getEntityTypeId() === $entityTypeId)
			{
				return $type;
			}
		}

		return null;
	}
}
