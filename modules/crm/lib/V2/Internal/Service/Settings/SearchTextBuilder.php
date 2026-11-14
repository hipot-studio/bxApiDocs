<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings;

use Bitrix\Crm\V2\Internal\Entity\Settings\ObjectField;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingDescription;

/** @internal */
final class SearchTextBuilder
{
	public function build(SettingDescription|ObjectField $description): string
	{
		$parts = [
			$description->title,
			$description->optionKey,
			$description->note,
			$description->description,
		];

		return implode(' ', array_filter(
			$parts,
			static fn(?string $part): bool => $part !== null && $part !== '',
		));
	}
}
