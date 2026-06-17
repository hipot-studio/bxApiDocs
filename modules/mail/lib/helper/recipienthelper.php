<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper;

use Bitrix\HumanResources\Public\Service\NodeMemberService;
use Bitrix\Mail\Internals\MailContactTable;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Search\Content;
use Bitrix\Main\SystemException;
use Bitrix\Main\UserIndexTable;
use Bitrix\Main\UserTable;

class RecipientHelper
{
	private const MIN_TOKEN_SIZE = 2;

	/**
	 * Resolves recipient strings (emails or contact names) to email addresses.
	 *
	 * @param string[] $recipients email addresses or contact names
	 * @return string[] resolved email addresses
	 * @throws SystemException if a name cannot be resolved or is ambiguous
	 */
	public function resolveRecipients(array $recipients, int $userId): array
	{
		$resolved = [];

		foreach ($recipients as $recipient)
		{
			$recipient = trim($recipient);
			if ($recipient === '')
			{
				continue;
			}

			if (check_email($recipient))
			{
				$resolved[] = $recipient;

				continue;
			}

			$resolved[] = $this->resolveByName($recipient, $userId);
		}

		return $resolved;
	}

	/**
	 * Searches user's mail contacts (address book) by name or email.
	 *
	 * @return array<int, array{id: int, email: string, name: string}>
	 */
	public function searchRecipients(string $query, int $userId, int $limit = 50, int $offset = 0): array
	{
		$filter = [
			'=USER_ID' => $userId,
		];

		if ($query !== '')
		{
			$filter[] = [
				'LOGIC' => 'OR',
				'%NAME' => $query,
				'%EMAIL' => $query,
			];
		}

		$contacts = MailContactTable::getList([
			'filter' => $filter,
			'select' => ['ID', 'NAME', 'EMAIL'],
			'order' => ['ID' => 'DESC'],
			'limit' => $limit,
			'offset' => max(0, $offset),
		])->fetchAll();

		$result = [];

		foreach ($contacts as $contact)
		{
			$result[] = [
				'id' => (int)($contact['ID'] ?? 0),
				'email' => $contact['EMAIL'] ?? '',
				'name' => $contact['NAME'] ?? '',
			];
		}

		return $result;
	}

	/**
	 * Searches portal employees by name or email.
	 *
	 * @return array<int, array{id: int, email: string, name: string, position: string}>
	 */
	public function searchEmployees(string $query, int $userId, int $limit = 50, int $offset = 0): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		$queryBuilder = UserTable::query()
			->setSelect(['ID', 'NAME', 'LAST_NAME', 'EMAIL', 'WORK_POSITION'])
			->setDistinct()
			->where('ACTIVE', 'Y')
			->whereNot('EMAIL', '')
			->setLimit($limit)
			->setOffset(max(0, $offset))
		;

		(new NodeMemberService())->injectUserNodeSubquery($queryBuilder);

		$searchTokens = $this->expandSearchQuery($query);

		if (!empty($searchTokens))
		{
			$queryBuilder->registerRuntimeField(
				new Reference(
					'USER_INDEX',
					UserIndexTable::class,
					Join::on('this.ID', 'ref.USER_ID'),
					['join_type' => 'INNER'],
				),
			);

			$searchFilter = $queryBuilder::filter()->logic('or');

			foreach ($searchTokens as $token)
			{
				$searchFilter->whereMatch(
					'USER_INDEX.SEARCH_USER_CONTENT',
					Filter\Helper::matchAgainstWildcard(
						Content::prepareStringToken($token), '*', self::MIN_TOKEN_SIZE,
					),
				);
			}

			$queryBuilder->where($searchFilter);
		}

		$users = $queryBuilder->fetchAll();

		$result = [];

		foreach ($users as $user)
		{
			$name = trim(($user['LAST_NAME'] ?? '') . ' ' . ($user['NAME'] ?? ''));

			$result[] = [
				'id' => (int)$user['ID'],
				'email' => (string)$user['EMAIL'],
				'name' => $name,
				'position' => (string)($user['WORK_POSITION'] ?? ''),
			];
		}

		return $result;
	}

	private function expandSearchQuery(string $query): array
	{
		$query = trim($query);
		if ($query === '')
		{
			return [];
		}

		$tokens = [$query];

		$words = preg_split('/\s+/', $query);
		if ($words === false)
		{
			return $tokens;
		}

		foreach ($words as $word)
		{
			if (mb_strlen($word) >= self::MIN_TOKEN_SIZE)
			{
				$tokens[] = $word;
			}
		}

		return array_unique($tokens);
	}

	/**
	 * @throws SystemException
	 */
	private function resolveByName(string $name, int $userId): string
	{
		$contacts = $this->searchRecipients($name, $userId);

		if (count($contacts) === 1)
		{
			return $contacts[0]['email'];
		}

		if (count($contacts) > 1)
		{
			$options = array_map(
				static fn(array $c) => "{$c['name']} <{$c['email']}>",
				$contacts,
			);

			throw new SystemException(
				"Ambiguous recipient \"{$name}\". Found multiple matches: "
				. implode(', ', $options)
				. '. Please specify the exact email address.',
			);
		}

		// Fallback: search among portal employees
		$employees = $this->searchEmployees($name, $userId);

		if (empty($employees))
		{
			throw new SystemException("Recipient \"{$name}\" not found in address book or among employees.");
		}

		if (count($employees) === 1)
		{
			return $employees[0]['email'];
		}

		$options = array_map(
			static fn(array $e) => "{$e['name']} <{$e['email']}>",
			$employees,
		);

		throw new SystemException(
			"Ambiguous recipient \"{$name}\". Found multiple employees: "
			. implode(', ', $options)
			. '. Please specify the exact email address.',
		);
	}
}
