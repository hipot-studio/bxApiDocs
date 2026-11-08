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
		$nodeAvailabilityLocator = \Bitrix\Main\DI\ServiceLocator::getInstance();
		if ($nodeAvailabilityLocator->has(\Bitrix\Bizproc\Public\Service\AiAgent\NodeAvailabilityServiceInterface::class))
		{
			$nodeAvailabilityService = $nodeAvailabilityLocator->get(
				\Bitrix\Bizproc\Public\Service\AiAgent\NodeAvailabilityServiceInterface::class
			);
			if (!$nodeAvailabilityService->isAvailable())
			{
				$errorCollection = new ErrorCollection();
				$errorCollection->setError($nodeAvailabilityService->getUnavailableError());

				return $errorCollection;
			}
		}

		$usersByRole = Container::getNodeSettingsService()->getUsersByMaxRoleWithAiReportsEnabled();

		$heads = $usersByRole[NodeMemberRole::Head->value] ?? [];
		$allUsers = array_merge(
			$heads,
			$usersByRole[NodeMemberRole::DeputyHead->value] ?? [],
			$usersByRole[NodeMemberRole::Employee->value] ?? [],
		);

		// Users who turned the day-plan messages off in the assistant chat opt out of every flow
		// of this agent (welcome, morning plan, evening report), not only of the message nodes.
		$disabled = self::getDisabledPlannerUsers(array_merge($allUsers, $heads));
		$allUsers = self::excludeUserIds($allUsers, $disabled);
		$heads = self::excludeUserIds($heads, $disabled);

		$toBpUsers = static fn(array $ids): array => array_map(static fn(int $id): string => 'user_' . $id, $ids);

		$this->setProperty(self::RETURN_PARAM_USERS, $toBpUsers($allUsers));
		$this->setProperty(self::RETURN_PARAM_HEADS, $toBpUsers($heads));

		return new ErrorCollection();
	}

	private static function getDisabledPlannerUsers(array $userIds): array
	{
		// is_callable guards a staged update where this file is newer than aiassistant.
		if (
			!\Bitrix\Main\Loader::includeModule('aiassistant')
			|| !is_callable(
				[\Bitrix\AiAssistant\Core\Im\ImBot::class, 'getPlannerMessageDisabledUserIds'],
			)
		)
		{
			return [];
		}

		return array_fill_keys(
			\Bitrix\AiAssistant\Core\Im\ImBot::getPlannerMessageDisabledUserIds($userIds),
			true,
		);
	}

	private static function excludeUserIds(array $userIds, array $disabled): array
	{
		return array_values(array_filter(
			$userIds,
			static fn(int $id): bool => !isset($disabled[$id]),
		));
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
