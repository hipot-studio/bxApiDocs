<?php

namespace Bitrix\Crm\AutomatedSolution\Entity;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Entity\DataManager;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\Type\DateTime;


class AutomatedSolutionTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_crm_automated_solution';
	}

	public static function getMap(): array
	{
		$fieldsMap = [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
		];

		Container::getInstance()->getLocalization()->loadMessages();

		$fieldsMap[] = (new ORM\Fields\IntegerField('INTRANET_CUSTOM_SECTION_ID'));
		$fieldsMap[] = new ReferenceField(
			'INTRANET_CUSTOM_SECTION',
			\Bitrix\Intranet\CustomSection\Entity\CustomSectionTable::class,
			['=this.INTRANET_CUSTOM_SECTION_ID' => 'ref.ID']
		);
		$fieldsMap[] = (new ORM\Fields\StringField('TITLE'))
			->configureTitle(Loc::getMessage('CRM_COMMON_TITLE'))
			->configureSize(255)
			->configureRequired();
		$fieldsMap[] = (new ORM\Fields\StringField('CODE'))
			->configureTitle(Loc::getMessage('CRM_COMMON_CODE'))
			->configureSize(255);
		$fieldsMap[] = (new ORM\Fields\IntegerField('SORT'))
			->configureTitle(Loc::getMessage('CRM_TYPE_ITEM_FIELD_SORT'))
			->configureRequired()
			->configureDefaultValue(100);
		$fieldsMap[] = (new ORM\Fields\DatetimeField('CREATED_TIME'))
			->configureTitle(Loc::getMessage('CRM_COMMON_CREATED_TIME'))
			->configureRequired()
			->configureDefaultValue(static fn() => new DateTime());
		$fieldsMap[] = (new ORM\Fields\DatetimeField('UPDATED_TIME'))
			->configureTitle(Loc::getMessage('CRM_COMMON_MODIFY_DATE'))
			->configureRequired()
			->configureDefaultValue(static fn() => new DateTime());
		$fieldsMap[] = (new ORM\Fields\IntegerField('CREATED_BY'))
			->configureTitle(Loc::getMessage('CRM_TYPE_ITEM_FIELD_CREATED_BY'))
			->configureRequired()
			->configureDefaultValue(static fn() => Container::getInstance()->getContext()->getUserId());
		$fieldsMap[] = (new ORM\Fields\IntegerField('UPDATED_BY'))
			->configureTitle(Loc::getMessage('CRM_COMMON_UPDATED_BY'))
			->configureRequired()
			->configureDefaultValue(static fn() => Container::getInstance()->getContext()->getUserId());

		return $fieldsMap;
	}
}
