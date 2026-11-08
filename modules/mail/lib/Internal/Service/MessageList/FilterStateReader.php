<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MessageList;

use Bitrix\Mail\Helper\MailboxDirectoryHelper;
use Bitrix\Main\UI\Filter\Options;

/**
 * Reads the screen filter out of the saved settings under its filter id. The client sends no filter
 * of its own, so every page of the list narrows by the same state.
 */
class FilterStateReader
{
	/**
	 * Fields that say where the list stands rather than what was looked for in it: the folder of a
	 * preset, the folder set of the all mail mode, the section of the favorites and the marks Options
	 * leaves on its own answer. `FILTER_APPLIED` is among them on purpose - the pinned folder preset
	 * carries it on every page of the inbox, so it names no choice of the reader.
	 */
	private const STRUCTURAL_FIELDS = [
		'DIR',
		'MD5_DIRS',
		'EXCLUDE_MD5_DIRS',
		'IS_FAVORITE',
		'FILTER_APPLIED',
		'PRESET_ID',
		'FILTER_ID',
	];

	public function read(ScreenContext $context, MailboxDirectoryHelper $dirsHelper): FilterState
	{
		$filterData = $this->applyFavoritesSection($this->readMessageFilter($context, $dirsHelper), $context);

		$currentDir = isset($filterData['DIR']) && is_scalar($filterData['DIR'])
			? (string)$filterData['DIR']
			: ''
		;

		// Options::getFilter() returns stored fields as is, so a value saved while its feature was
		// on would still reach MessageFilter.
		if (!$context->labelsEnabled)
		{
			unset($filterData['LABEL_ID']);
		}

		if ($context->isAllMailMode)
		{
			$filterData = $this->applyAllMailMode($filterData, $context);
		}

		return new FilterState($filterData, $currentDir, $this->isNarrowed($filterData));
	}

	/**
	 * Whether anything besides the folder the reader stands in narrows the list. Read on every page
	 * rather than rendered once by the screen: applying a filter and dropping it both reload the list
	 * without reloading the screen, and a flag taken at the moment of mounting would keep saying what
	 * was true then.
	 */
	private function isNarrowed(array $filterData): bool
	{
		foreach ($filterData as $field => $value)
		{
			if (in_array($field, self::STRUCTURAL_FIELDS, true))
			{
				continue;
			}

			if (is_array($value) ? $value !== [] : trim((string)$value) !== '')
			{
				return true;
			}
		}

		return false;
	}

	public function readDraftCriteria(ScreenContext $context): DraftCriteria
	{
		$filterData = $this->storeRequestAndReadFilter($context, $context->filterPresets);

		return new DraftCriteria(
			search: is_string($filterData['FIND'] ?? null) ? trim($filterData['FIND']) : '',
			recipient: is_string($filterData['RECIPIENT'] ?? null)
				? mb_strtolower(trim($filterData['RECIPIENT']))
				: '',
			hasAttachments: match ($filterData['ATTACHMENTS'] ?? null)
			{
				'Y' => true,
				'N' => false,
				default => null,
			},
		);
	}

	/**
	 * A screen that has already read the filter passes the result instead of reading it again. The
	 * controller has no such state and reads here, with the presets the screen declares - Options
	 * resolves the pinned preset out of the declaration, and an empty one leaves the inbox page
	 * without a folder.
	 */
	private function readMessageFilter(ScreenContext $context, MailboxDirectoryHelper $dirsHelper): array
	{
		if ($context->savedFilter !== null)
		{
			return $context->savedFilter;
		}

		return $this->storeRequestAndReadFilter(
			$context,
			(new FilterPresetsDeclaration())->build($dirsHelper, $context->isAllMailMode),
		);
	}

	/**
	 * Options::getFilter() first writes the filter of the request into the saved settings and only
	 * then answers with them, so the field declaration decides what is stored as well as what is
	 * read - an empty one overwrites the settings with emptiness.
	 */
	private function storeRequestAndReadFilter(ScreenContext $context, array $presets): array
	{
		return (new Options($context->filterId, $presets))
			->getFilter($context->filterFields)
		;
	}

	/** The section is addressed by the screen marker only; a saved IS_FAVORITE would revive it. */
	private function applyFavoritesSection(array $filterData, ScreenContext $context): array
	{
		unset($filterData['IS_FAVORITE']);

		if (!$context->isFavoritesSection())
		{
			return $filterData;
		}

		// the section spans the whole mailbox, so the folder and the label give way to it
		unset($filterData['DIR'], $filterData['LABEL_ID']);

		$filterData['IS_FAVORITE'] = 'Y';
		$filterData['EXCLUDE_MD5_DIRS'] = MailboxDirectoryHelper::getSpamAndTrashDirsMd5ForMailboxes(
			$context->getMailboxIdsForFilter(),
		);
		// no preset carries the section, so MessageFilter has to be told the filter is applied
		$filterData['FILTER_APPLIED'] = 'Y';

		return $filterData;
	}

	/**
	 * FILTER_APPLIED is forced so MessageFilter processes MD5_DIRS: the dir set is per-user and built
	 * at runtime, so no static preset can carry the flag for the all mail mode.
	 */
	private function applyAllMailMode(array $filterData, ScreenContext $context): array
	{
		unset($filterData['DIR']);

		$filterData['MD5_DIRS'] = MailboxDirectoryHelper::getSyncDirsMd5ForMailboxes(
			$context->getMailboxIdsForFilter(),
		);
		$filterData['FILTER_APPLIED'] = 'Y';

		return $filterData;
	}
}
