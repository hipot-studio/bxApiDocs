<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Provider;

use Bitrix\Crm\Service\Timeline\Navigation\CandidateExpression;

final class ActivityProviderTypeDescriptor
{
	public function __construct(
		private readonly string $id,
		private readonly string $title,
		private readonly ?string $icon,
		private readonly int $order,
		private readonly CandidateExpression $candidate,
		private readonly CandidateExpression $exact,
		private readonly string $version = '1',
	)
	{
		if ($id === '' || $title === '' || $version === '')
		{
			throw new \InvalidArgumentException('Timeline provider type descriptor is incomplete');
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

	public function getVersion(): string
	{
		return $this->version;
	}
}
