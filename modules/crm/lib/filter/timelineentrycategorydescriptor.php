<?php

declare(strict_types=1);

namespace Bitrix\Crm\Filter;

use Bitrix\Crm\Service\Timeline\Navigation\CandidateExpression;
use Bitrix\Crm\Service\Timeline\Navigation\PredicateCompiler;
use Bitrix\Crm\Service\Timeline\Navigation\SafeTimelineDiscriminator;

final class TimelineEntryCategoryDescriptor
{
	public function __construct(
		private readonly string $id,
		private readonly string $title,
		private readonly ?string $icon,
		private readonly int $order,
		private readonly CandidateExpression $candidate,
		private readonly CandidateExpression $exact,
		private readonly array $reservedExact = [],
	)
	{
		if ($id === '' || $title === '')
		{
			throw new \InvalidArgumentException('Timeline category descriptor needs an id and title');
		}
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function getIcon(): ?string
	{
		return $this->icon;
	}

	public function getOrder(): int
	{
		return $this->order;
	}

	public function getCandidate(): CandidateExpression
	{
		return $this->candidate;
	}

	public function getExact(): CandidateExpression
	{
		return $this->exact;
	}

	public function getReservedExact(): array
	{
		return $this->reservedExact;
	}

	public function matches(SafeTimelineDiscriminator $discriminator): bool
	{
		return (new PredicateCompiler())->matches($this->exact, $discriminator);
	}
}
