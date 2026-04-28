<?php

namespace Bitrix\Crm\Import\Factory;

use Bitrix\Crm\Component\EntityList\UserField\GridHeaders;
use Bitrix\Crm\Import\Collection\FieldCollection;
use Bitrix\Crm\Import\ImportEntityFields\MultiField;
use Bitrix\Crm\Import\ImportEntityFields\RequisiteField;
use Bitrix\Crm\Import\ImportEntityFields\UserField;
use Bitrix\Crm\Import\ImportEntityFields\UtmField;
use Bitrix\Crm\Requisite\ImportHelper;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\UtmTable;
use CCrmFieldMulti;
use CCrmUserType;

final class ImportEntityFieldFactory
{
	private readonly Factory $factory;

	public function __construct(
		private readonly int $entityTypeId,
	)
	{
		$this->factory = Container::getInstance()->getFactory($this->entityTypeId);
	}

	public function getMultiFields(): FieldCollection
	{
		$headers = [];
		$this->getCCrmFieldMulti()->ListAddHeaders($headers);

		$multiFields = new FieldCollection();
		foreach ($headers as $header)
		{
			$multiField = MultiField::tryFromHeader($header);
			if ($multiField === null)
			{
				continue;
			}

			$multiFields->push($multiField);
		}

		return $multiFields;
	}

	public function getUserFields(): FieldCollection
	{
		$userFields = new FieldCollection();

		$userType = $this->getCCrmUserType();

		$headers = [];
		(new GridHeaders($userType))
			->setForImport(true)
			->setWithEnumFieldValues(true)
			->append($headers)
		;

		foreach ($headers as $header)
		{
			$userField = UserField::tryFromHeader($header, $userType);
			if ($userField === null)
			{
				continue;
			}

			$userFields->push($userField);
		}

		return $userFields;
	}

	/**
	 * @see ImportHelper::prepareEntityImportRequisiteInfo() for options
	 * @param array $options
	 * @return FieldCollection
	 */
	public function getRequisiteFields(array $options): FieldCollection
	{
		$headers = ImportHelper::prepareEntityImportRequisiteInfo($this->entityTypeId, $options);
		$headers = $headers['REQUISITE_HEADERS'] ?? [];

		$requisiteFields = new FieldCollection();
		foreach ($headers as $header)
		{
			$requisiteField = RequisiteField::tryFromHeader($header);
			if ($requisiteField === null)
			{
				continue;
			}

			$requisiteFields->push($requisiteField);
		}

		return $requisiteFields;
	}

	public function getUtmFields(): FieldCollection
	{
		$fieldCollection = new FieldCollection();
		foreach (UtmTable::getCodeList() as $code)
		{
			$fieldCollection->push(new UtmField($code),);
		}

		return $fieldCollection;
	}

	private function getCCrmUserType(): CCrmUserType
	{
		global $USER_FIELD_MANAGER;

		return new CCrmUserType($USER_FIELD_MANAGER, $this->factory->getUserFieldEntityId());
	}

	private function getCCrmFieldMulti(): CCrmFieldMulti
	{
		return new CCrmFieldMulti();
	}
}
