<?php

namespace Bitrix\Crm\RepeatSale\Sandbox\Entity;

use Bitrix\AI\Model\QueueTable;
use Bitrix\Crm\RepeatSale\Segment\Entity\RepeatSaleSegmentTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\ArrayField;
use Bitrix\Main\ORM\Fields\DateField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Fields\Validators\RangeValidator;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Text\Emoji;
use Bitrix\Main\Type\Date;
use CCrmOwnerType;

class RepeatSaleSandboxTable extends DataManager
{
	private const ALLOWED_CLIENT_TYPES = [
		CCrmOwnerType::Contact,
		CCrmOwnerType::Company,
	];

	public static function getTableName(): string
	{
		return 'b_crm_repeat_sale_sandbox';
	}

	public static function getObjectClass(): string
	{
		return RepeatSaleSandbox::class;
	}

	public static function getMap(): array
	{
		Container::getInstance()->getLocalization()->loadMessages();

		$fieldRepository = ServiceLocator::getInstance()->get('crm.model.fieldRepository');

		return [
			$fieldRepository->getId(),
			(new IntegerField('JOB_ID'))
				->configureRequired()
				->addValidator(new RangeValidator(1))
				->configureTitle(Loc::getMessage('CRM_REPEAT_SALE_SANDBOX_ENTITY_JOB_ID_TITLE'))
			,
			(new IntegerField('SEGMENT_ID'))
				->configureNullable()
				->configureTitle(Loc::getMessage('CRM_REPEAT_SALE_SANDBOX_ENTITY_SEGMENT_ID_TITLE'))
				//->configureRequired()
			,
			(new IntegerField('ITEM_TYPE_ID'))
				->configureDefaultValue(CCrmOwnerType::Deal)
				->configureRequired()
				->addValidator(new RangeValidator(CCrmOwnerType::Deal, CCrmOwnerType::Deal))
				->configureTitle(Loc::getMessage('CRM_REPEAT_SALE_SANDBOX_ENTITY_ITEM_TYPE_ID_TITLE'))
			,
			(new IntegerField('ITEM_ID'))
				->configureRequired()
				->addValidator(new RangeValidator(1))
				->configureTitle(Loc::getMessage('CRM_REPEAT_SALE_SANDBOX_ENTITY_ITEM_ID_TITLE'))
			,
			(new IntegerField('CLIENT_TYPE_ID'))
				->configureDefaultValue(CCrmOwnerType::Contact)
				->configureNullable()
				->addValidator(static fn($value) => in_array($value, self::ALLOWED_CLIENT_TYPES, true) || $value === null)
				->configureTitle(Loc::getMessage('CRM_REPEAT_SALE_SANDBOX_ENTITY_CLIENT_TYPE_ID_TITLE'))
			,
			(new IntegerField('CLIENT_ID'))
				->configureNullable()
				->addValidator(static fn($value) => $value > 0 || $value === null)
				->configureTitle(Loc::getMessage('CRM_REPEAT_SALE_SANDBOX_ENTITY_CLIENT_ID_TITLE'))
			,
			(new DateField('CHECK_DATE'))
				//->configureRequired()
				->configureNullable()
				->configureDefaultValue(static fn($value) => new Date())
				->configureTitle(Loc::getMessage('CRM_REPEAT_SALE_SANDBOX_ENTITY_CHECK_DATE_TITLE'))
			,
			(new TextField('PROMPT'))
				//->configureRequired()
				->configureNullable()
				->addSaveDataModifier([Emoji::class, 'encode'])
				->addFetchDataModifier([Emoji::class, 'decode'])
			,
			(new ArrayField('PAYLOAD'))
				//->configureRequired()
				->configureNullable()
				->configureTitle(Loc::getMessage('CRM_REPEAT_SALE_SANDBOX_ENTITY_PAYLOAD_TITLE'))
			,
			$fieldRepository->getCreatedTime('CREATED_AT'),
			$fieldRepository->getUpdatedTime('UPDATED_AT'),
			new Reference(
				'JOB',
				QueueTable::class,
				Join::on('this.JOB_ID', 'ref.ID'),
			),
			new Reference(
				'SEGMENT',
				RepeatSaleSegmentTable::class,
				Join::on('this.SEGMENT_ID', 'ref.ID'),
			),
		];
	}
}
