<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\WebForm;

use Bitrix\Crm\Category\DealCategory;
use Bitrix\Crm\Category\Entity\Category;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\UserConsent\Internals\AgreementTable;
use Bitrix\Main\UserTable;

/**
 * Tells which of the references a CRM form points at may be used: the users a queue of responsible
 * users may hold, the agreements a form may bind, and the funnels a submission may create its
 * entity in.
 *
 * The reads live here and not in the check asking for them: they are reads of tables of their own,
 * and the ORM stays inside the storage layer. The answers about the users and the agreements are
 * subsets of what was asked, so a caller learns nothing about the rest of the portal.
 *
 * Which funnel of a CRM entity type a user may work in is answered here once, for the snapshot of
 * the settings publishing the funnels and for the check of the input accepting them. Two rules would
 * mean refusing a client the value the snapshot has just offered it.
 *
 * @internal
 */
final class FormReferenceRepository
{
	/**
	 * Users that may take a turn in the queue: existing, active and real, unlike legacy, which asked
	 * only whether a row of the user exists.
	 *
	 * @param int[] $userIds
	 * @return int[] the ones of the given users that may be responsible
	 */
	public function getSuitableUserIds(array $userIds): array
	{
		if ($userIds === [])
		{
			return [];
		}

		$rows = UserTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $userIds)
			->where('ACTIVE', true)
			->where('REAL_USER', 'expr', true)
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['ID'], $rows);
	}

	/**
	 * Agreements a form may bind: existing and active.
	 *
	 * An inactive agreement is not offered by the editor and is dropped by the public page of the
	 * form, so binding one would leave a form asking its visitor to confirm something they never see.
	 *
	 * @param int[] $agreementIds
	 * @return int[] the ones of the given agreements that may be bound
	 */
	public function getUsableAgreementIds(array $agreementIds): array
	{
		if ($agreementIds === [])
		{
			return [];
		}

		$rows = AgreementTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $agreementIds)
			->where('ACTIVE', true)
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['ID'], $rows);
	}

	/**
	 * Funnels of the CRM entity type the user may work in, out of the ones given. This is the rule
	 * itself: a funnel counts as available while the user may read the items of it.
	 *
	 * The names travel along because the snapshot of the settings publishes them; the check of the
	 * input asks the same question through {@see self::getAvailableCategoryIds()}.
	 *
	 * @param array<int|string, array<string, mixed>> $categories funnels as the dictionary of the
	 *        form publishes them
	 * @return list<array{id: int, name: string}>
	 */
	public function getAvailableCategories(array $categories, int $entityTypeId, int $userId): array
	{
		$userPermissions = Container::getInstance()->getUserPermissions($userId);

		$available = [];
		foreach ($categories as $category)
		{
			$categoryId = (int)($category['id'] ?? 0);
			if (!$userPermissions->entityType()->canReadItemsInCategory($entityTypeId, $categoryId))
			{
				continue;
			}

			$available[] = [
				'id' => $categoryId,
				'name' => (string)($category['name'] ?? ''),
			];
		}

		return $available;
	}

	/**
	 * Funnels of the CRM entity type the user may work in, by identifier. The set is the one the
	 * snapshot of the settings offers the same user: the funnels the dictionary of the form
	 * publishes for this entity type, less the ones the user may not read.
	 *
	 * @return int[]
	 */
	public function getAvailableCategoryIds(int $entityTypeId, int $userId): array
	{
		// The names belong to the snapshot publishing the funnels; this direction asks the same rule
		// about the identifiers alone, so it reads no name to begin with.
		$published = array_map(
			static fn (int $categoryId): array => ['id' => $categoryId],
			self::readPublishedCategoryIds($entityTypeId),
		);

		return array_column(
			$this->getAvailableCategories($published, $entityTypeId, $userId),
			'id',
		);
	}

	/**
	 * Identifiers of the funnels one CRM entity type offers a form, read type by type rather than out
	 * of the reference of the whole portal: the dictionary of the form builds the funnels and the
	 * stages of every type there is in order to answer about one.
	 *
	 * The selection stays the one the dictionary publishes. A deal answers with the very call the
	 * dictionary makes. A smart process answers with the funnels of its own type, and a type the
	 * dictionary leaves out answers with nothing: the predicate below is the one the map of the
	 * dynamic types collects its funnels by ({@see \Bitrix\Crm\Service\DynamicTypesMap::load()}), so
	 * an entity holding no funnel there - the service ones among them - holds none here either.
	 *
	 * @return int[]
	 */
	private static function readPublishedCategoryIds(int $entityTypeId): array
	{
		if ($entityTypeId === \CCrmOwnerType::Deal)
		{
			return DealCategory::getAllIDs();
		}

		if (!\CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
		{
			return [];
		}

		return array_map(
			static fn (Category $category): int => (int)$category->getId(),
			Container::getInstance()->getFactory($entityTypeId)?->getCategories() ?? [],
		);
	}
}
