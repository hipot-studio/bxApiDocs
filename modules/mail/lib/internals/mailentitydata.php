<?php

namespace Bitrix\Mail\Internals;

use Bitrix\Main\Entity;
use Bitrix\Main\ORM;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\Type\DateTime;

class MailEntityDataTable extends Entity\DataManager
{
	public static function getTableName()
	{
		return 'b_mail_entity_data';
	}

	public static function getFilePath()
	{
		return __FILE__;
	}

	public static function deleteList(array $filter)
	{
		$entity = static::getEntity();
		$connection = $entity->getConnection();

		return $connection->query(sprintf(
			'DELETE FROM %s WHERE %s',
			$connection->getSqlHelper()->quote($entity->getDbTableName()),
			ORM\Query\Query::buildFilterSql($entity, $filter)
		));
	}

	public static function getMap()
	{
		return [
			new Fields\IntegerField('MAILBOX_ID', [
				'required' => true,
				'primary' => true,
			]),
			new Fields\EnumField('ENTITY_TYPE', [
				'values' => [
					MailEntityOptionsTable::DIR_TYPE_NAME,
					MailEntityOptionsTable::MAILBOX_TYPE_NAME,
					MailEntityOptionsTable::MESSAGE_TYPE_NAME,
					MailEntityOptionsTable::USER_TYPE_NAME,
				],
				'required' => true,
				'primary' => true,
			]),
			new Fields\StringField('ENTITY_ID', [
				'required' => true,
				'primary' => true,
			]),
			new Fields\StringField('PROPERTY_NAME', [
				'required' => true,
				'primary' => true,
			]),
			new Fields\TextField('VALUE', [
				'required' => true,
			]),
			new Fields\DatetimeField('DATE_INSERT'),
		];
	}
}
