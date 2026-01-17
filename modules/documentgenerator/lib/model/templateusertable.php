<?php

namespace Bitrix\DocumentGenerator\Model;

use Bitrix\DocumentGenerator\Infrastructure\Agent\Access\DepartmentAccessCodesMigrateAgent;
use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\Application;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\ORM\EventResult;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\Type\Collection;

/**
 * Class TemplateUserTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_TemplateUser_Query query()
 * @method static EO_TemplateUser_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_TemplateUser_Result getById($id)
 * @method static EO_TemplateUser_Result getList(array $parameters = [])
 * @method static EO_TemplateUser_Entity getEntity()
 * @method static TemplateUser createObject($setDefaultValues = true)
 * @method static TemplateUserCollection createCollection()
 * @method static TemplateUser wakeUpObject($row)
 * @method static TemplateUserCollection wakeUpCollection($rows)
 */
final class TemplateUserTable extends DataManager
{
	public const ALL_USERS = 'UA';

	public static function getTableName(): string
	{
		return 'b_documentgenerator_template_user';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('TEMPLATE_ID'))
				->configurePrimary()
			,

			(new StringField('ACCESS_CODE'))
				->configurePrimary()
			,

			(new Reference(
				'TEMPLATE',
				EO_Template::class,
				['=this.TEMPLATE_ID' => 'ref.ID'],
			)),
		];
	}

	public static function addSocialGroupAccessSuffix(string $code): string
	{
		$accessCode = new AccessCode($code);
		if ($accessCode->getEntityType() === AccessCode::TYPE_SOCNETGROUP && !str_ends_with($code, '_K'))
		{
			$code .= '_K';
		}

		return $code;
	}

	public static function removeSocialGroupAccessSuffix(string $code): string
	{
		$accessCode = new AccessCode($code);
		if ($accessCode->getEntityType() === AccessCode::TYPE_SOCNETGROUP && str_ends_with($code, '_K'))
		{
			$code = mb_substr($code, 0, -2);
		}

		return $code;
	}

	public static function onBeforeAdd(Event $event): EventResult
	{
		$result = new EventResult();

		if (!DepartmentAccessCodesMigrateAgent::isDone())
		{
			$code = $event->getParameter('fields')['ACCESS_CODE'];
			$code = self::addSocialGroupAccessSuffix($code);
			$result->modifyFields(['ACCESS_CODE' => $code]);
		}

		return $result;
	}

	/**
	 * @return class-string<Role>
	 */
	public static function getObjectClass(): string
	{
		return TemplateUser::class;
	}

	/**
	 * @return class-string<RoleCollection>
	 */
	public static function getCollectionClass(): string
	{
		return TemplateUserCollection::class;
	}

	public static function deleteByAccessCodes(array $accessCodes): ?\Bitrix\Main\DB\Result
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$tableName = $helper->quote(self::getTableName());

		$accessCodes = array_map($helper->forSql(...), $accessCodes);

		$accessCodesList = "'" . implode("', '", $accessCodes) . "'";
		if (empty($accessCodesList))
		{
			return null;
		}

		$result = $connection->query("DELETE FROM {$tableName} WHERE ACCESS_CODE IN ({$accessCodesList})");
		self::cleanCache();

		return $result;
	}

	public static function deleteByTemplateIds(array $templateIds): ?\Bitrix\Main\DB\Result
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$tableName = $helper->quote(self::getTableName());

		Collection::normalizeArrayValuesByInt($templateIds);
		if (empty($templateIds))
		{
			return null;
		}

		$templateIdsString = implode(', ', $templateIds);

		$result = $connection->query("DELETE FROM {$tableName} WHERE TEMPLATE_ID IN ({$templateIdsString})");
		self::cleanCache();

		return $result;
	}
}
