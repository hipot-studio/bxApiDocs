<?php

namespace Bitrix\StaffTrack\Internal\Integration\Im\Blocks;

use Bitrix\Main\Localization\Loc;

final class CheckInBlocksComposer
{
	/**
	 * @param list<int> $galleryFileIds
	 * @param list<int> $fileBlockFileIds
	 * @return list<CheckInBlock>
	 */
	public static function compose(
		?string $description,
		?string $address,
		string $snapshotUrl,
		array $galleryFileIds,
		array $fileBlockFileIds,
	): array
	{
		$blocks = [
			CheckInBlock::title(
				(string)Loc::getMessage('STAFFTRACK_CHECK_IN_BLOCKS_TITLE'),
				CheckInBlock::TITLE_SIZE_MEDIUM,
			),
		];

		$hasDescription = trim((string)$description) !== '';
		$hasGallery = $galleryFileIds !== [];
		$hasFileBlock = $fileBlockFileIds !== [];

		if ($hasDescription)
		{
			$blocks[] = CheckInBlock::text($description);
		}

		if ($hasGallery)
		{
			if ($hasDescription)
			{
				$blocks[] = CheckInBlock::spaceDivider(CheckInBlock::SPACE_DIVIDER_SIZE_SMALL);
			}

			$blocks[] = CheckInBlock::gallery($galleryFileIds);
		}

		if ($hasFileBlock)
		{
			$blocks[] = CheckInBlock::file($fileBlockFileIds);
		}

		if ($hasDescription || $hasGallery || $hasFileBlock)
		{
			$blocks[] = CheckInBlock::lineDivider();
		}

		$blocks[] = CheckInBlock::map($snapshotUrl, $address === '' ? null : $address);

		return $blocks;
	}
}
