<?php

namespace Bitrix\Crm\Import\Strategy\ValueMapper;

use Bitrix\Crm\Format\PersonNameFormatter;
use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Enum\NameFormat;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\UserTable;

final class UserIdValueMapper
{
	private static array $userIdsByNames = [];

	public function __construct(
		private readonly string $fieldId,
		private readonly NameFormat $nameFormat,
	)
	{
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		$columnIndex = $fieldBindings->getColumnIndexByFieldId($this->fieldId);
		if ($columnIndex === null)
		{
			return FieldProcessResult::skip();
		}

		$possibleUserId = $row[$columnIndex] ?? null;
		if (empty($possibleUserId))
		{
			return FieldProcessResult::skip();
		}

		if (is_numeric($possibleUserId))
		{
			$importItemFields[$this->fieldId] = $this->processUserIdAsInt((int)$possibleUserId);

			return FieldProcessResult::success();
		}

		if (preg_match('/^.+\[\s*(\d+)\s*]$/', $possibleUserId, $matches) === 1)
		{
			$importItemFields[$this->fieldId] = $this->processUserIdAsInt((int)$matches[1]);

			return FieldProcessResult::success();
		}

		$userId = $this->processUserIdAsFullName($possibleUserId);
		if ($userId !== null)
		{
			$importItemFields[$this->fieldId] = $userId;

			return FieldProcessResult::success();
		}

		return FieldProcessResult::skip();
	}

	private function processUserIdAsInt(int $userId): ?int
	{
		if ($userId <= 0)
		{
			return null;
		}

		$user = Container::getInstance()->getUserBroker()->getById($userId);
		if ($user === null)
		{
			return null;
		}

		return $userId;
	}

	private function processUserIdAsFullName(string $fullName): ?int
	{
		if (isset(self::$userIdsByNames[$fullName]))
		{
			return self::$userIdsByNames[$fullName];
		}

		$isNameParsed = PersonNameFormatter::tryParseName($fullName, $this->nameFormat->value, $nameParts);
		if ($isNameParsed)
		{
			$query = UserTable::query()
				->setSelect(['ID'])
				->where('IS_REAL_USER', 'Y')
				->setOrder(['ID' => 'ASC'])
				->setLimit(1)
			;

			$userFields = [
				'NAME',
				'SECOND_NAME',
				'LAST_NAME'
			];

			foreach ($userFields as $userField)
			{
				if (!empty($nameParts[$userField]))
				{
					$query->where($userField, $nameParts[$userField]);
				}
			}

			$userId = $query
				->fetchObject()
				?->getId()
			;

			if (is_int($userId))
			{
				self::$userIdsByNames[$fullName] = $userId;

				return $userId;
			}
		}

		self::$userIdsByNames[$fullName] = null;

		return null;
	}
}
