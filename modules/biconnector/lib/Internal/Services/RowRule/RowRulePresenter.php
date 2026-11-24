<?php

namespace Bitrix\BIConnector\Internal\Services\RowRule;

use Bitrix\BIConnector\DataSource\FieldKind;
use Bitrix\BIConnector\Internal\Entity\RowRule;
use Bitrix\HumanResources\Enum\NodeActiveFilter;
use Bitrix\HumanResources\Public\Service\Container as HumanResourcesContainer;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;

/**
 * Turns row rules into the model of the API.
 *
 * Names of tables and fields, the kind of a field and the state of a rule come from the current table
 * description; names of employees and departments are loaded once for the whole batch.
 */
final class RowRulePresenter
{
	private const ITEM_TOKEN = 'token';
	private const ITEM_CONSTANT = 'constant';

	/**
	 * @param RowRule[] $rules
	 * @param FieldDescriptorReader $reader
	 * @param \Closure $stateOf Takes a RowRule and returns its state.
	 *
	 * @return array[] Models in the order of the rules.
	 */
	public function present(array $rules, FieldDescriptorReader $reader, \Closure $stateOf): array
	{
		$userIds = [];
		$nodeIds = [];
		foreach ($rules as $rule)
		{
			$userIds[] = (int)$rule->getCreatedById();
			$userIds[] = (int)$rule->getUpdatedById();

			$kind = $reader->getField($rule->getTableCode(), $rule->getFieldCode())?->kind;
			foreach ($this->getConstants($rule) as $value)
			{
				if ($kind === FieldKind::EMPLOYEE)
				{
					$userIds[] = (int)$value;
				}
				elseif ($kind === FieldKind::DEPARTMENT)
				{
					$nodeIds[] = (int)$value;
				}
			}
		}

		$userNames = $this->loadUserNames($userIds);
		$nodeNames = $this->loadNodeNames($nodeIds);

		$models = [];
		foreach ($rules as $rule)
		{
			$models[] = $this->presentRule($rule, $reader, $userNames, $nodeNames, $stateOf($rule));
		}

		return $models;
	}

	/**
	 * @param RowRule $rule
	 * @param FieldDescriptorReader $reader
	 * @param array<int, string> $userNames
	 * @param array<int, string> $nodeNames
	 * @param string $state
	 *
	 * @return array
	 */
	private function presentRule(
		RowRule $rule,
		FieldDescriptorReader $reader,
		array $userNames,
		array $nodeNames,
		string $state,
	): array
	{
		$tableCode = $rule->getTableCode();
		$fieldCode = $rule->getFieldCode();
		$field = $reader->getField($tableCode, $fieldCode);

		$values = [];
		foreach ($rule->getValues() as $item)
		{
			$value = is_array($item) ? $this->presentValue($item, $field?->kind, $userNames, $nodeNames) : null;
			if ($value !== null)
			{
				$values[] = $value;
			}
		}

		return [
			'id' => (int)$rule->getId(),
			'tableCode' => $tableCode,
			'tableName' => $reader->getTableName($tableCode) ?: $tableCode,
			'fieldCode' => $fieldCode,
			'fieldName' => $field?->name ?: $fieldCode,
			'fieldType' => $field?->type,
			'fieldKind' => $field?->kind,
			'operator' => count($values) === 1
				? RuleValueCanonizer::OPERATOR_EQUAL
				: RuleValueCanonizer::OPERATOR_IN,
			'values' => $values,
			'description' => $rule->getDescription(),
			'state' => $state,
			'isUsed' => false,
			'usageCount' => 0,
			'createdBy' => $this->presentUser($rule->getCreatedById(), $userNames),
			'updatedBy' => $this->presentUser($rule->getUpdatedById(), $userNames),
			'dateCreate' => $this->formatDate($rule->getDateCreate()),
			'dateModify' => $this->formatDate($rule->getDateModify()),
		];
	}

	/**
	 * @param array $item Stored item: `['k' => string, 'v' => ?string]`.
	 * @param string|null $kind Kind of the field now, null when the field is gone.
	 * @param array<int, string> $userNames
	 * @param array<int, string> $nodeNames
	 *
	 * @return array|null
	 */
	private function presentValue(array $item, ?string $kind, array $userNames, array $nodeNames): ?array
	{
		$storedKey = $item['k'] ?? null;
		if (!is_string($storedKey))
		{
			return null;
		}

		if ($storedKey === RuleValueCanonizer::STORED_CONSTANT)
		{
			$value = (string)($item['v'] ?? '');
			$title = match ($kind)
			{
				FieldKind::EMPLOYEE => $userNames[(int)$value] ?? null,
				FieldKind::DEPARTMENT => $nodeNames[(int)$value] ?? null,
				default => null,
			};

			return [
				'type' => self::ITEM_CONSTANT,
				'value' => $value,
				'title' => $title ?? $value,
			];
		}

		// a stored key unknown to the dictionary is shown as is; the state of the rule tells it is broken
		$code = TokenDictionary::fromStored($storedKey);

		return [
			'type' => self::ITEM_TOKEN,
			'code' => $code ?? $storedKey,
			'title' => $code !== null ? TokenDictionary::getTitle($code) : $storedKey,
		];
	}

	/**
	 * @return string[]
	 */
	private function getConstants(RowRule $rule): array
	{
		$constants = [];
		foreach ($rule->getValues() as $item)
		{
			if (is_array($item) && ($item['k'] ?? null) === RuleValueCanonizer::STORED_CONSTANT)
			{
				$constants[] = (string)($item['v'] ?? '');
			}
		}

		return $constants;
	}

	/**
	 * @param int|null $userId
	 * @param array<int, string> $userNames
	 *
	 * @return array{id: int, name: string}|null
	 */
	private function presentUser(?int $userId, array $userNames): ?array
	{
		if ($userId === null || $userId <= 0)
		{
			return null;
		}

		return [
			'id' => $userId,
			'name' => $userNames[$userId] ?? '',
		];
	}

	/**
	 * @param int[] $userIds
	 *
	 * @return array<int, string> Not escaped.
	 */
	private function loadUserNames(array $userIds): array
	{
		$userIds = array_values(array_unique(array_filter($userIds, static fn(int $id): bool => $id > 0)));
		if ($userIds === [])
		{
			return [];
		}

		$users = UserTable::getList([
			'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'EMAIL', 'TITLE'],
			'filter' => ['@ID' => $userIds],
		]);

		$nameFormat = \CSite::GetNameFormat(false);
		$names = [];
		while ($user = $users->fetch())
		{
			$names[(int)$user['ID']] = (string)\CUser::FormatName($nameFormat, $user, true, false);
		}

		return $names;
	}

	/**
	 * @param int[] $nodeIds
	 *
	 * @return array<int, string> Not escaped.
	 */
	private function loadNodeNames(array $nodeIds): array
	{
		$nodeIds = array_values(array_unique(array_filter($nodeIds, static fn(int $id): bool => $id > 0)));
		if ($nodeIds === [] || !Loader::includeModule('humanresources'))
		{
			return [];
		}

		$nodes = HumanResourcesContainer::getNodeService()->findAllByIds(
			nodeIds: $nodeIds,
			activeFilter: NodeActiveFilter::ALL,
		);

		$names = [];
		foreach ($nodes as $node)
		{
			$name = (string)$node->name;
			if ($node->id !== null && $name !== '')
			{
				$names[$node->id] = $name;
			}
		}

		return $names;
	}

	/**
	 * ISO 8601 in the time zone of the current user.
	 *
	 * @param DateTime|null $date
	 *
	 * @return string|null
	 */
	private function formatDate(?DateTime $date): ?string
	{
		if ($date === null)
		{
			return null;
		}

		$moment = new \DateTimeImmutable('@' . $date->getTimestamp());
		$serverOffset = (new \DateTimeZone(date_default_timezone_get()))->getOffset($moment);
		$offset = $serverOffset + \CTimeZone::GetOffset(null, false, $date);

		return $moment
			->setTimezone(new \DateTimeZone(DateTime::secondsToOffset($offset, ':')))
			->format(\DateTimeInterface::ATOM)
		;
	}
}
