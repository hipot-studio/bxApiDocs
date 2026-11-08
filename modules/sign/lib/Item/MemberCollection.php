<?php

namespace Bitrix\Sign\Item;

use ArrayIterator;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Helper\IterationHelper;
use Bitrix\Sign\Type\Member\Role;
use Bitrix\Sign\Type\MemberStatus;
use Closure;
use Countable;
use Iterator;

class MemberCollection implements Contract\Item, Contract\ItemCollection, Iterator, Countable
{
	/** the form of a composition property the write of it takes */
	private const WRITTEN = 0;

	/** the form a comparison of two compositions sees; a property without it is not compared */
	private const COMPARED = 1;

	protected ?int $queryTotal = null;
	private array $items;
	/** @var ArrayIterator<Member> */
	private ArrayIterator $iterator;

	public function __construct(Member ...$items)
	{
		$this->items = $items;
		$this->iterator = new ArrayIterator($this->items);
	}

	public function add(Member $item): static
	{
		$this->items[] = $item;

		return $this;
	}

	public function clear(): static
	{
		$this->items = [];

		return $this;
	}

	public function toArray(): array
	{
		return $this->items;
	}

	public function current(): ?Member
	{
		return $this->iterator->current();
	}

	public function next(): void
	{
		$this->iterator->next();
	}

	public function key(): int
	{
		return $this->iterator->key();
	}

	public function valid(): bool
	{
		return $this->iterator->valid();
	}

	public function rewind(): void
	{
		$this->iterator = new ArrayIterator($this->items);
	}

	public function count(): int
	{
		return count($this->items);
	}

	public function isEmpty(): bool
	{
		return empty($this->items);
	}

	public function getFirst(): ?Member
	{
		return $this->items[0] ?? null;
	}

	/**
	 * @param Closure(Member): bool $rule
	 * @return static
	 */
	public function filter(Closure $rule): static
	{
		$result = new static();
		foreach ($this as $item)
		{
			if ($rule($item))
			{
				$result->add($item);
			}
		}

		return $result;
	}

	public function filterByParty(int $party): static
	{
		return $this->filter(static fn(Member $member) => $member->party === $party);
	}

	/**
	 * @param array<string> $statusList
	 * @return $this
	 */
	public function filterByStatus(array $statusList): static
	{
		return $this->filter(static fn(Member $member) => in_array($member->status, $statusList));
	}

	/**
	 * @param Closure(Member): bool $rule
	 * @return Member|null
	 */
	final public function findFirst(Closure $rule): ?Member
	{
		foreach ($this as $item)
		{
			if ($rule($item))
			{
				return $item;
			}
		}

		return null;
	}

	/**
	 * @param array<MemberStatus::*> $statuses
	 *
	 * @return Member|null
	 */
	final public function findFirstByStatuses(array $statuses): ?Member
	{
		return $this->findFirst(static fn(Member $member): bool => in_array($member->status, $statuses, true));
	}

	final public function filterByStatuses(array $statuses): static
	{
		return $this->filter(static fn(Member $member): bool => in_array($member->status, $statuses, true));
	}

	final public function findFirstByParty(int $party): ?Member
	{
		foreach ($this as $item)
		{
			if ($item->party === $party)
			{
				return $item;
			}
		}

		return null;
	}

	final public function findFirstByRole(?string $role): ?Member
	{
		foreach ($this as $item)
		{
			if ($item->role === $role)
			{
				return $item;
			}
		}

		return null;
	}

	/**
	 * @return list<?int>
	 */
	final public function getIds(): array
	{
		$result = [];
		foreach ($this as $member)
		{
			$result[] = $member->id;
		}

		return $result;
	}

	final public function sort(Closure $rule): static
	{
		$result = $this->items;
		usort($result, $rule);

		return new static(...$result);
	}

	final public function all(Closure $rule): bool
	{
		return IterationHelper::all($this, $rule);
	}

	public function filterByRole(string $role): static
	{
		return $this->filter(static fn(Member $member) => $member->role === $role);
	}

	/**
	 * @param Role::* ...$roles
	 *
	 * @return $this
	 */
	public function filterByRoles(string... $roles): static
	{
		return $this->filter(static fn(Member $member): bool => in_array($member->role, $roles, true));
	}

	public function filterExcludeRoles(string... $roles): static
	{
		return $this->filter(static fn(Member $member): bool => !in_array($member->role, $roles, true));
	}

	public function filterByEntityTypes(string... $entityTypes): static
	{
		return $this->filter(static fn(Member $member) => in_array($member->entityType, $entityTypes, true));
	}

	public function filterExcludeEntityTypes(string... $entityTypes): static
	{
		return $this->filter(static fn(Member $member) => !in_array($member->entityType, $entityTypes, true));
	}

	public function setQueryTotal(int $total): static
	{
		$this->queryTotal = $total;

		return $this;
	}

	public function getQueryTotal(): ?int
	{
		return $this->queryTotal;
	}

	public function cloneMembers(): static
	{
		$newCollection = new MemberCollection();
		foreach ($this as $member)
		{
			$newCollection->add(clone $member);
		}

		return $newCollection;
	}

	/**
	 * The composition copied the way its write takes it: the domain path creates the members anew,
	 * so neither the identifiers of the rows nor anything else the path fills by itself travels
	 * with the copy.
	 *
	 * @see \Bitrix\Sign\Service\Sign\MemberService::setupB2eMembers() the write itself
	 */
	public function copyForWrite(): static
	{
		$copies = new static();
		foreach ($this as $member)
		{
			$written = array_map(
				static fn(array $property): mixed => $property[self::WRITTEN],
				self::listCompositionProperties($member),
			);

			$copies->add(new Member(...$written));
		}

		return $copies;
	}

	/**
	 * The composition as a comparison of two of them sees it, in an order of its own, because the
	 * row order of a composition carries nothing while the party does. A property nobody has filled
	 * compares as the empty value of its own kind, so a run building its signers without a requisite
	 * preset matches the zero the document keeps. Left out is the employee of the HCM link, the only
	 * property the comparison does not share with the copy: a composition built by a run carries no
	 * employee and would never match one read from the document.
	 *
	 * @return list<array<string, int|string>>
	 */
	public function describeForComparison(): array
	{
		$described = [];
		foreach ($this as $member)
		{
			$compared = [];
			foreach (self::listCompositionProperties($member) as $name => $property)
			{
				if (array_key_exists(self::COMPARED, $property))
				{
					$compared[$name] = $property[self::COMPARED];
				}
			}

			$described[] = $compared;
		}

		sort($described);

		return $described;
	}

	/**
	 * The properties a composition is made of, keyed by the argument its write passes them as and
	 * told in both forms they are used in: the value the write takes, then the value a comparison
	 * of two compositions sees. Both forms of a property are declared here and nowhere else, so a
	 * property joins the composition by one line, with the way it compares alongside it.
	 *
	 * The identifiers of the rows, the communications, the statuses and the dates are left out
	 * entirely: the domain path that writes the composition fills them by itself.
	 *
	 * @return array<string, array{0: mixed, 1?: int|string}>
	 */
	private static function listCompositionProperties(Member $member): array
	{
		return [
			'party' => [$member->party, (int)$member->party],
			'entityType' => [$member->entityType, (string)$member->entityType],
			'entityId' => [$member->entityId, (int)$member->entityId],
			'role' => [$member->role, (string)$member->role],
			'presetId' => [$member->presetId, (int)$member->presetId],
			'reminder' => [
				new Member\Reminder(
					lastSendDate: null,
					plannedNextSendDate: null,
					type: $member->reminder->type,
				),
				$member->reminder->type->value,
			],
			// the employee of the HCM link has no compared form on purpose: a composition built by
			// a run carries none and would never match one read from the document
			'employeeId' => [$member->employeeId],
		];
	}
}
