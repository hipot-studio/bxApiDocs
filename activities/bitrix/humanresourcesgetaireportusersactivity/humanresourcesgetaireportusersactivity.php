<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\HumanResources\Public\Service\Container;
use Bitrix\HumanResources\Type\NodeMemberRole;
use Bitrix\Main\ErrorCollection;

class CBPHumanResourcesGetAiReportUsersActivity extends BaseActivity
{
	private const RETURN_PARAM_USERS = 'Users';
	private const RETURN_PARAM_HEADS = 'Heads';

	protected static $requiredModules = ['humanresources'];

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			self::RETURN_PARAM_USERS => null,
			self::RETURN_PARAM_HEADS => null,
		];

		$this->setPropertiesTypes([
			self::RETURN_PARAM_USERS => [
				'Type' => FieldType::USER,
				'Multiple' => true,
			],
			self::RETURN_PARAM_HEADS => [
				'Type' => FieldType::USER,
				'Multiple' => true,
			],
		]);
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}

	protected function internalExecute(): ErrorCollection
	{
		$usersByRole = Container::getNodeSettingsService()->getUsersByMaxRoleWithAiReportsEnabled();

		$heads = $usersByRole[NodeMemberRole::Head->value] ?? [];
		$allUsers = array_merge(
			$heads,
			$usersByRole[NodeMemberRole::DeputyHead->value] ?? [],
			$usersByRole[NodeMemberRole::Employee->value] ?? [],
		);

		$toBpUsers = static fn(array $ids): array => array_map(static fn(int $id): string => 'user_' . $id, $ids);

		$this->setProperty(self::RETURN_PARAM_USERS, $toBpUsers($allUsers));
		$this->setProperty(self::RETURN_PARAM_HEADS, $toBpUsers($heads));

		return new ErrorCollection();
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return static::getPropertiesMap([]);
	}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [];
	}
}
