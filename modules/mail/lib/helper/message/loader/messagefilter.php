<?php

namespace Bitrix\Mail\Helper\Message\Loader;

use Bitrix\Mail\Helper\Dto\Message\SearchMessagesDto;
use Bitrix\Mail\Helper\Message;
use Bitrix\Mail\Internals\MessageAccessTable;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\Text\Emoji;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UI\Filter\Options;

class MessageFilter
{
	private array $filter = [];

	public function __construct(
		private readonly array $mailboxIds,
		array $filterData,
		bool $checkFilterApplied = false
	)
	{
		$this->addMailboxIds($mailboxIds);
		$this->applyFromArray($filterData, $checkFilterApplied);
	}

	public function applyFromArray(array $filterData, bool $checkFilterApplied = false): self
	{
		if ($checkFilterApplied && empty($filterData['FILTER_APPLIED']))
		{
			return $this;
		}

		if (isset($filterData['BIND']) && $filterData['BIND'] !== '')
		{
			if ($filterData['BIND'] === MessageAccessTable::ENTITY_TYPE_NO_BIND)
			{
				$this->addNoBind();
			}
			else
			{
				$this->addBind($filterData['BIND']);
			}
		}

		if (isset($filterData['ATTACHMENTS']) && $filterData['ATTACHMENTS'] !== '')
		{
			$this->addHasAttachments($filterData['ATTACHMENTS'] === 'Y');
		}

		if (isset($filterData['IS_SEEN']) && $filterData['IS_SEEN'] !== '')
		{
			$this->addIsSeen($filterData['IS_SEEN'] === 'Y');
		}

		if (!empty($filterData['DIR']) && is_scalar($filterData['DIR']))
		{
			$this->addDir($filterData['DIR']);
		}

		try
		{
			if (!empty($filterData['DATE_from']) && $filterData['DATE_from'] !== '')
			{
				$this->addDateFrom(new DateTime($filterData['DATE_from']));
			}

			if (!empty($filterData['DATE_to']) && $filterData['DATE_to'] !== '')
			{
				$this->addDateTo(new DateTime($filterData['DATE_to']));
			}
		}
		catch (\Exception)
		{
		}

		if (!empty($filterData['FIND']) && trim($filterData['FIND']) !== '')
		{
			$search = Emoji::encode($filterData['FIND']);
			$this->addSearchFilter(Message::prepareSearchString($search));
		}

		return $this;
	}

	public function applyFromDto(SearchMessagesDto $dto): self
	{
		if ($dto->searchQuery !== null && trim($dto->searchQuery) !== '')
		{
			$search = Emoji::encode($dto->searchQuery);
			$this->addSearchFilter(Message::prepareSearchString($search));
		}

		if ($dto->dateFrom !== null)
		{
			$this->addDateFrom($dto->dateFrom);
		}

		if ($dto->dateTo !== null)
		{
			$this->addDateTo($dto->dateTo);
		}

		if ($dto->isSeen !== null)
		{
			$this->addIsSeen($dto->isSeen);
		}

		if ($dto->hasAttachments !== null)
		{
			$this->addHasAttachments($dto->hasAttachments);
		}

		if ($dto->folder !== null && trim($dto->folder) !== '')
		{
			$this->addDir($dto->folder);
		}

		return $this;
	}

	/**
	 * @throws ArgumentNullException
	 */
	public function addPreset(?string $presetId, int $mailboxId): self
	{
		if (!$presetId)
		{
			return $this;
		}

		$presetFilter = FilterPreset::getFilterByPresetId($presetId, $mailboxId);
		if ($presetFilter === null || empty($presetFilter['fields']))
		{
			return $this;
		}

		return $this
			->resetFilters()
			->addMailboxIds($this->mailboxIds)
			->applyFromArray($presetFilter['fields'])
		;
	}

	public function addMailboxIds(array $ids): self
	{
		if (count($ids) === 1)
		{
			$this->filter = ['=MAILBOX_ID' => $this->mailboxIds[0]];
		}
		elseif (count($ids) > 1)
		{
			$this->filter = ['@MAILBOX_ID' => $this->mailboxIds];
		}

		return $this;
	}

	public function addIsSeen(bool $isSeen): self
	{
		$key = $isSeen
			? '@MESSAGE_UID.IS_SEEN'
			: '!@MESSAGE_UID.IS_SEEN'
		;

		$this->filter[$key] = ['Y', 'S'];

		return $this;
	}

	public function addHasAttachments(bool $hasAttachments): self
	{
		$key = $hasAttachments ? '!=' : '=';
		$this->filter[$key . 'ATTACHMENTS'] = '0';

		return $this;
	}

	public function addBind(string $entityType): self
	{
		$this->filter['=MESSAGE_ACCESS.ENTITY_TYPE'] = $entityType;

		return $this;
	}

	public function addNoBind(): self
	{
		$this->filter['==MESSAGE_ACCESS.ENTITY_TYPE'] = null;

		return $this;
	}

	public function addDir(string $dir): self
	{
		$this->filter['=MESSAGE_UID.DIR_MD5'] = md5($dir);

		return $this;
	}

	public function addSearchFilter(string $search): self
	{
		$this->filter['*SEARCH_CONTENT'] = $search;

		return $this;
	}

	public function addDateFrom(DateTime $date): self
	{
		$this->filter['>=FIELD_DATE'] = $date;

		return $this;
	}

	public function addDateTo(DateTime $date): self
	{
		$this->filter['<=FIELD_DATE'] = $date;

		return $this;
	}

	public function resetFilters(): self
	{
		$this->filter = [];

		return $this;
	}

	public function getArray(): array
	{
		return $this->filter;
	}
}
