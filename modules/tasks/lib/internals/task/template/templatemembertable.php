<?php

namespace Bitrix\Tasks\Internals\Task\Template;

use Bitrix\Main\ORM\Data\AddStrategy\Trait\AddInsertIgnoreTrait;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\UserTable;
use Bitrix\Tasks\Internals\Task\MemberTable;
use Bitrix\Tasks\Internals\Task\TemplateTable;
use Bitrix\Tasks\Internals\TaskDataManager;

/**
 * Class TemplateMemberTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_TemplateMember_Query query()
 * @method static EO_TemplateMember_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_TemplateMember_Result getById($id)
 * @method static EO_TemplateMember_Result getList(array $parameters = [])
 * @method static EO_TemplateMember_Entity getEntity()
 * @method static \Bitrix\Tasks\Internals\Task\Template\TemplateMemberObject createObject($setDefaultValues = true)
 * @method static \Bitrix\Tasks\Internals\Task\Template\TemplateMemberCollection createCollection()
 * @method static \Bitrix\Tasks\Internals\Task\Template\TemplateMemberObject wakeUpObject($row)
 * @method static \Bitrix\Tasks\Internals\Task\Template\TemplateMemberCollection wakeUpCollection($rows)
 */
class TemplateMemberTable extends TaskDataManager
{
	use DeleteByFilterTrait;
	use AddInsertIgnoreTrait;

	public const MEMBER_TYPE_ORIGINATOR = MemberTable::MEMBER_TYPE_ORIGINATOR;
	public const MEMBER_TYPE_RESPONSIBLE = MemberTable::MEMBER_TYPE_RESPONSIBLE;
	public const MEMBER_TYPE_ACCOMPLICE = MemberTable::MEMBER_TYPE_ACCOMPLICE;
	public const MEMBER_TYPE_AUDITOR = MemberTable::MEMBER_TYPE_AUDITOR;

	public static function getTableName(): string
	{
		return 'b_tasks_template_member';
	}

	public static function getObjectClass(): string
	{
		return TemplateMemberObject::class;
	}

	public static function getCollectionClass(): string
	{
		return TemplateMemberCollection::class;
	}

	public static function getClass(): string
	{
		return static::class;
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary(),

			(new IntegerField('TEMPLATE_ID')),

			(new IntegerField('USER_ID')),

			(new StringField('TYPE'))
				->addValidator(new LengthValidator(null, 1)),

			(new Reference('USER', UserTable::getEntity(), Join::on('this.USER_ID', 'ref.ID'))),

			(new Reference('TEMPLATE', TemplateTable::getEntity(), Join::on('this.TEMPLATE_ID', 'ref.ID'))),
		];
	}
}
