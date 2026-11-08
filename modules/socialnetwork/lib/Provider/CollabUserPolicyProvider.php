<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Provider;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\EntityIdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Config\Storage;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserGroupTable;
use Bitrix\Main\UserTable;
use Bitrix\Socialnetwork\Helper\InstanceTrait;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\BotService;

class CollabUserPolicyProvider
{
	use InstanceTrait;

	private const ID_BATCH_SIZE = 500;

	/** @return array{internal: int[], external: int[], missing: int[]} */
	public function classify(array $userIds): array
	{
		$userIds = array_values(array_filter(
			array_unique(array_map('intval', $userIds)),
			static fn(int $id): bool => $id > 0,
		));
		if ($userIds === [])
		{
			return ['internal' => [], 'external' => [], 'missing' => []];
		}

		$hasHumanResources = Loader::includeModule('humanresources');
		if ($hasHumanResources && !class_exists(Storage::class))
		{
			throw new LoaderException('The humanresources module does not provide employee classification.');
		}
		$converted = $hasHumanResources && Storage::instance()->isCompanyStructureConverted();
		$legacyDepartments = !$converted && Loader::includeModule('intranet');
		$botExternalAuthId = Container::getInstance()->get(BotService::class)->getExternalAuthId();
		$existingIds = [];
		$internalIds = [];
		foreach (array_chunk($userIds, self::ID_BATCH_SIZE) as $batch)
		{
			$select = ['ID', 'EXTERNAL_AUTH_ID'];
			if ($legacyDepartments)
			{
				$select[] = 'UF_DEPARTMENT';
			}
			$users = UserTable::getList([
				'select' => $select,
				'filter' => ['@ID' => $batch],
				'cache' => ['ttl' => 0],
			]);
			while ($user = $users->fetch())
			{
				$id = (int)$user['ID'];
				$existingIds[] = $id;
				$isBot = $botExternalAuthId !== null && ($user['EXTERNAL_AUTH_ID'] ?? null) === $botExternalAuthId;
				$isEmployee = $legacyDepartments
					&& array_filter((array)($user['UF_DEPARTMENT'] ?? []), static fn($id): bool => (int)$id > 0) !== [];
				if ($isBot || $isEmployee)
				{
					$internalIds[] = $id;
				}
			}
			if ($converted)
			{
				array_push($internalIds, ...$this->getDepartmentEmployeeIds($batch));
			}
			array_push($internalIds, ...$this->getAdministratorIds($batch));
		}

		$existingIds = array_values(array_intersect($userIds, $existingIds));
		$internalIds = array_values(array_intersect($existingIds, $internalIds));

		return [
			'internal' => $internalIds,
			'external' => array_values(array_diff($existingIds, $internalIds)),
			'missing' => array_values(array_diff($userIds, $existingIds)),
		];
	}

	private function getDepartmentEmployeeIds(array $userIds): array
	{
		if (
			!class_exists(NodeMemberDataBuilder::class)
			|| !class_exists(NodeMemberFilter::class)
			|| !class_exists(EntityIdFilter::class)
			|| !class_exists(NodeTypeFilter::class)
		)
		{
			throw new LoaderException('The humanresources module does not provide employee classification.');
		}

		$filter = new NodeMemberFilter(
			entityIdFilter: EntityIdFilter::fromEntityIds($userIds),
			entityType: MemberEntityType::USER,
			nodeFilter: null,
			active: true,
		);
		if (property_exists($filter, 'withVirtualUsers'))
		{
			$filter->withVirtualUsers = true;
		}

		$members = (new NodeMemberDataBuilder())
			->setFilter($filter)
			->addFilter(NodeTypeFilter::createForDepartment()->setCurrentAlias('NODE'))
			->setCacheTtl(0)
			->prepareQuery()
			->setSelect(['ENTITY_ID'])
			->setCacheTtl(0)
			->fetchAll()
		;

		return array_map('intval', array_column($members, 'ENTITY_ID'));
	}

	private function getAdministratorIds(array $userIds): array
	{
		$now = new DateTime();
		$groups = UserGroupTable::getList([
			'select' => ['USER_ID'],
			'filter' => [
				'@USER_ID' => $userIds,
				'=GROUP_ID' => 1,
				'=GROUP.ACTIVE' => 'Y',
				['LOGIC' => 'OR', '=DATE_ACTIVE_FROM' => null, '<=DATE_ACTIVE_FROM' => $now],
				['LOGIC' => 'OR', '=DATE_ACTIVE_TO' => null, '>=DATE_ACTIVE_TO' => $now],
			],
			'cache' => ['ttl' => 0],
		]);

		return array_map('intval', array_column($groups->fetchAll(), 'USER_ID'));
	}
}
