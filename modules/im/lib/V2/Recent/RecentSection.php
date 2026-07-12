<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Recent;

use Bitrix\Im\V2\Rest\RestAdapter;
use Bitrix\Im\V2\Rest\RestConvertible;

class RecentSection implements RestConvertible
{
	public function __construct(
		public readonly Recent $recent,
		public readonly SectionMeta $meta,
		public readonly bool $hasNextPage,
	) {}

	public static function getRestEntityName(): string
	{
		return 'recentSection';
	}

	public function toRestFormat(array $option = []): array
	{
		$rest = (new RestAdapter($this->recent))->toRestFormat($option);
		$rest['hasNextPage'] = $this->hasNextPage;
		$rest[$this->meta::getRestEntityName()] = $this->meta->toRestFormat();

		return $rest;
	}
}
