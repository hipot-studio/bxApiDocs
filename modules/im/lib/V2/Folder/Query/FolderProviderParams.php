<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Query;

use Bitrix\Main\Type\DateTime;

final class FolderProviderParams
{
	public function __construct(
		public readonly ?DateTime $lastMessageDate = null,
		public readonly ?int $limit = null,
	) {}

	public static function fromArray(array $filter = [], ?int $limit = null): self
	{
		$date = null;

		if (isset($filter['lastMessageDate']))
		{
			$raw = $filter['lastMessageDate'];

			if ($raw instanceof DateTime)
			{
				$date = $raw;
			}
			elseif (is_string($raw) && DateTime::isCorrect($raw, \DateTimeInterface::RFC3339))
			{
				$date = new DateTime($raw, \DateTimeInterface::RFC3339);
			}
		}

		return new self(lastMessageDate: $date, limit: $limit);
	}
}
