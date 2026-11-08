<?php

namespace Bitrix\Mail\Helper\Message\Loader;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\Dto\Message\SearchMessagesDto;
use Bitrix\Mail\Helper\Label\LabelsFeature;
use Bitrix\Mail\Helper\MailboxDirectoryHelper;
use Bitrix\Mail\Helper\Message;
use Bitrix\Mail\Internal\Service\Message\ClassificationLabel;
use Bitrix\Mail\Internals\MessageAccessTable;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Text\Emoji;
use Bitrix\Main\Type\DateTime;

class MessageFilter
{
	/** No mark carries this code: real ones start at 1, see {@see \Bitrix\Mail\Internals\MailMessageMarkTable}. */
	private const NO_MATCH_MARK_CODE = 0;

	private const RECIPIENT_COLUMNS = ['FIELD_TO', 'FIELD_CC', 'FIELD_BCC'];

	/** As long as every column the address fields are matched against: varchar(255) each. */
	private const ADDRESS_VALUE_MAX_LENGTH = 255;

	/** Every word of the value is a condition of its own, three of them for the recipient. */
	private const ADDRESS_VALUE_MAX_WORDS = 5;

	private array $filter = [];

	/** Sticky: a preset applied over the same request resets the filter, not the scope it belongs to. */
	private bool $isWideScope = false;

	public function __construct(
		private readonly array $mailboxIds,
		array $filterData,
		bool $checkFilterApplied = false,
		private readonly ?int $userId = null,
		private readonly bool $withAttachmentsStack = false,
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

		if (isset($filterData['IS_FAVORITE']) && $filterData['IS_FAVORITE'] !== '')
		{
			$this->addIsFavorite($filterData['IS_FAVORITE'] === 'Y');
		}

		$this->isWideScope = $this->isWideScope || $this->detectWideScope($filterData);

		// Gated in the shared layer: mailmobile passes request filter params through as is.
		$labelId = LabelsFeature::isEnabled() && isset($filterData['LABEL_ID'])
			? (int)$filterData['LABEL_ID']
			: 0;
		if ($labelId > 0)
		{
			$this->addLabel($labelId);
		}
		elseif (self::detectAllMailMode($filterData))
		{
			$this->filter['@MESSAGE_UID.DIR_MD5'] = $filterData['MD5_DIRS'];
		}
		elseif (isset($filterData['DIR']) && is_scalar($filterData['DIR']))
		{
			$this->addDir($filterData['DIR']);
		}

		if (isset($filterData['EXCLUDE_MD5_DIRS']) && is_array($filterData['EXCLUDE_MD5_DIRS']))
		{
			$this->excludeDirs($filterData['EXCLUDE_MD5_DIRS']);
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
			$this->addSearchQueryFilter($search);
		}

		// Gated in the shared layer: mailmobile passes request filter params through as is.
		// Kept out of the wide scopes: there the list spans every mailbox and folder of the user,
		// which is exactly where matching the address columns costs the most.
		if (!$this->isWideScope && Feature::isMessageAddressFilterAvailable())
		{
			$sender = self::readTrimmedString($filterData, 'SENDER');
			if ($sender !== '')
			{
				$this->addSender($sender);
			}

			$recipient = self::readTrimmedString($filterData, 'RECIPIENT');
			if ($recipient !== '')
			{
				$this->addRecipient($recipient);
			}
		}

		return $this;
	}

	public function applyFromDto(SearchMessagesDto $dto): self
	{
		if ($dto->searchQuery !== null && trim($dto->searchQuery) !== '')
		{
			$search = Emoji::encode($dto->searchQuery);
			$this->addSearchQueryFilter($search);
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

		if ($dto->bindings !== null && $dto->bindings !== [])
		{
			$this->addIncludeBindings($dto->bindings);
		}

		if ($dto->excludeBindings !== null && $dto->excludeBindings !== [])
		{
			$this->addExcludeBindings($dto->excludeBindings);
		}

		if ($dto->classification !== null && trim($dto->classification) !== '')
		{
			$this->addClassification($dto->classification);
		}

		if ($dto->unanswered !== null)
		{
			$this->addUnanswered($dto->unanswered);
		}

		return $this;
	}

	/**
	 * MD5_DIRS marks the all-mail mode: it is the only one scoped by the folders of every user mailbox
	 * at once, while the regular mode passes a single DIR.
	 */
	private static function detectAllMailMode(array $filterData): bool
	{
		return isset($filterData['MD5_DIRS']) && is_array($filterData['MD5_DIRS']);
	}

	/**
	 * A scope the address columns are too expensive for: the list spans more than one mailbox. The folder
	 * alone is no boundary here - a filter by label and the favorites section take it out as well, and
	 * there the columns are still matched over the one mailbox they were opened in. What the gate keeps
	 * them out of is the list of every mailbox the user has: the all-mail mode, which says so with
	 * MD5_DIRS, and the favorites of the mobile client, which says so by the mailbox list alone.
	 */
	private function detectWideScope(array $filterData): bool
	{
		return self::detectAllMailMode($filterData) || count($this->mailboxIds) > 1;
	}

	private static function readTrimmedString(array $filterData, string $key): string
	{
		$value = $filterData[$key] ?? null;

		return is_string($value) ? trim($value) : '';
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

	/**
	 * An unknown label makes the filter match nothing: a request for one label must never answer with the
	 * whole mailbox. Rejected up front by {@see SearchMessagesDto::fromArray()}.
	 */
	public function addClassification(string $label): self
	{
		$parsed = ClassificationLabel::tryFrom($label);
		if ($parsed === null)
		{
			$this->filter[QueryBuilder::FILTER_KEY_CLASSIFICATION] = [self::NO_MATCH_MARK_CODE];

			return $this;
		}

		$existing = $this->filter[QueryBuilder::FILTER_KEY_CLASSIFICATION] ?? [];
		if (in_array(self::NO_MATCH_MARK_CODE, $existing, true))
		{
			return $this;
		}

		$markCode = $parsed->markCode();

		if (!in_array($markCode, $existing, true))
		{
			$existing[] = $markCode;
		}

		$this->filter[QueryBuilder::FILTER_KEY_CLASSIFICATION] = $existing;

		return $this;
	}

	public function addIsFavorite(bool $isFavorite): self
	{
		if ($isFavorite && $this->userId !== null && $this->userId > 0)
		{
			$this->filter[QueryBuilder::FILTER_KEY_IS_FAVORITE] = $this->userId;
		}

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

	/**
	 * Includes messages that have a binding of the listed entity types.
	 *
	 * @param string[] $entityTypes
	 */
	public function addIncludeBindings(array $entityTypes): self
	{
		$this->filter[QueryBuilder::FILTER_KEY_INCLUDE_BINDINGS] = array_values($entityTypes);

		return $this;
	}

	/**
	 * Excludes messages that have a binding of the listed entity types.
	 *
	 * @param string[] $entityTypes
	 */
	public function addExcludeBindings(array $entityTypes): self
	{
		$this->filter[QueryBuilder::FILTER_KEY_EXCLUDE_BINDINGS] = array_values($entityTypes);

		return $this;
	}

	/**
	 * The predicate is evaluated over the whole mailbox: GROUP BY plus ORDER BY MAX() keep LIMIT from cutting
	 * the scan short (0.67 s on 100k messages). That is why the assistant tool keeps the filter out of its
	 * schema until the list query is reworked to stop at LIMIT.
	 */
	public function addUnanswered(bool $unanswered): self
	{
		$this->filter[QueryBuilder::FILTER_KEY_UNANSWERED] = $unanswered;

		return $this;
	}

	public function addDir(string $dir): self
	{
		$this->filter['=MESSAGE_UID.DIR_MD5'] = md5($dir);

		return $this;
	}

	/**
	 * @param array<int, string[]> $excludedByMailbox
	 * @param array<int, string[]>|null $includedByMailbox Null means that no inclusion scope is required.
	 */
	public function addDirectoryScopes(array $excludedByMailbox, ?array $includedByMailbox = null): self
	{
		foreach ($excludedByMailbox as $mailboxId => $directoryHashes)
		{
			$mailboxId = (int)$mailboxId;
			$directoryHashes = array_values(array_unique(array_filter($directoryHashes, 'is_string')));
			if ($mailboxId <= 0 || $directoryHashes === [])
			{
				continue;
			}

			$this->filter[] = [
				'LOGIC' => 'OR',
				['!=MESSAGE_UID.MAILBOX_ID' => $mailboxId],
				['!@MESSAGE_UID.DIR_MD5' => $directoryHashes],
			];
		}

		if ($includedByMailbox === null)
		{
			return $this;
		}

		$includedScopes = ['LOGIC' => 'OR'];
		foreach ($includedByMailbox as $mailboxId => $directoryHashes)
		{
			$mailboxId = (int)$mailboxId;
			$directoryHashes = array_values(array_unique(array_filter($directoryHashes, 'is_string')));
			if ($mailboxId <= 0 || $directoryHashes === [])
			{
				continue;
			}

			$includedScopes[] = [
				'=MESSAGE_UID.MAILBOX_ID' => $mailboxId,
				'@MESSAGE_UID.DIR_MD5' => $directoryHashes,
			];
		}

		if (count($includedScopes) === 1)
		{
			$this->filter['=MESSAGE_UID.ID'] = 0;
		}
		else
		{
			$this->filter[] = $includedScopes;
		}

		return $this;
	}

	/**
	 * Messages bound to the label owned by $userId (defaults to the current user). Mutually exclusive
	 * with the folder filter, so spam and trash are excluded explicitly, as favorites does it. The
	 * label counter applies the same rule.
	 */
	public function addLabel(int $labelId, ?int $userId = null): self
	{
		$this->filter[QueryBuilder::FILTER_KEY_LABEL] = [
			'id' => $labelId,
			'userId' => $userId ?? $this->userId ?? (int)CurrentUser::get()->getId(),
		];

		unset($this->filter['=MESSAGE_UID.DIR_MD5'], $this->filter['@MESSAGE_UID.DIR_MD5']);

		$this->excludeDirs(MailboxDirectoryHelper::getSpamAndTrashDirsMd5ForMailboxes($this->mailboxIds));

		return $this;
	}

	private function excludeDirs(array $dirsMd5): void
	{
		if ($dirsMd5 === [])
		{
			return;
		}

		$this->filter['!@MESSAGE_UID.DIR_MD5'] = array_values(array_unique(array_merge(
			$this->filter['!@MESSAGE_UID.DIR_MD5'] ?? [],
			$dirsMd5
		)));
	}

	public function addSearchFilter(string $search): self
	{
		$this->filter['*SEARCH_CONTENT'] = $search;

		return $this;
	}

	/**
	 * Sender is matched against the decoded From header, so a display name matches as well as an address.
	 * A value of several words is matched word by word, as the legacy list did it: the header keeps the
	 * name in the order the sender wrote it, and the order the words were typed in may not decide
	 * whether the message is found.
	 */
	public function addSender(string $sender): self
	{
		$words = self::splitIntoWords($sender);
		if ($words === [])
		{
			return $this;
		}

		if (count($words) === 1)
		{
			$this->filter['%FIELD_FROM'] = $words[0];

			return $this;
		}

		$this->filter[] = self::everyWordMatches('FIELD_FROM', $words);

		return $this;
	}

	/**
	 * The three recipient columns hold what fits into their varchar(255); the original recipients of the
	 * search blob answer for the addresses of a long list that got cut off there.
	 *
	 * All the words of the value are required of one and the same column: a name split over the To and the
	 * Cc of the same message names neither of the two recipients.
	 */
	public function addRecipient(string $recipient): self
	{
		$words = self::splitIntoWords($recipient);
		if ($words === [])
		{
			return $this;
		}

		$condition = ['LOGIC' => 'OR'];
		foreach (self::RECIPIENT_COLUMNS as $column)
		{
			$condition[] = self::everyWordMatches($column, $words);
		}

		// The same words the columns are asked for: both halves of the alternative speak of one value.
		$originalRecipientSearch = Message::prepareOriginalRecipientsSearchString(implode(' ', $words));
		if ($originalRecipientSearch !== '')
		{
			$condition[] = ['*SEARCH_CONTENT' => $originalRecipientSearch];
		}

		$this->filter[] = $condition;

		return $this;
	}

	/**
	 * The headers are stored the way {@see \CMailHeader::ConvertHeader()} leaves them, with every emoji
	 * encoded, so the value is encoded before anything else is done to it: the columns are asked for the
	 * form they hold, and the bound below counts the characters that are really stored. Text carrying no
	 * emoji comes back from the encoder unchanged.
	 *
	 * Whitespace is the only separator taken from the legacy filter query: its boolean operators would
	 * split the addresses that carry them, the "+" of a plus address before all.
	 *
	 * The value reaches the filter straight from a request and no LIMIT cuts the scan short, so a pasted
	 * list of addresses would cost as much as a value that finds something. Both bounds are of what one
	 * field is typed with, and the length is the one the columns themselves hold.
	 *
	 * @return string[]
	 */
	private static function splitIntoWords(string $value): array
	{
		$stored = trim(Emoji::encode($value));
		$bounded = mb_substr($stored, 0, self::ADDRESS_VALUE_MAX_LENGTH);
		$words = preg_split('/\s+/u', $bounded, -1, PREG_SPLIT_NO_EMPTY);

		return $words === false ? [] : array_slice($words, 0, self::ADDRESS_VALUE_MAX_WORDS);
	}

	/**
	 * @param string[] $words
	 */
	private static function everyWordMatches(string $column, array $words): array
	{
		if (count($words) === 1)
		{
			return ['%' . $column => $words[0]];
		}

		return [
			'LOGIC' => 'AND',
			...array_map(
				static fn (string $word): array => ['%' . $column => $word],
				$words,
			),
		];
	}

	private function addSearchQueryFilter(string $search): self
	{
		$preparedSearch = Message::prepareSearchString($search);
		$originalRecipientSearch = Message::prepareOriginalRecipientsSearchString($search);
		if ($originalRecipientSearch === '')
		{
			return $this->addSearchFilter($preparedSearch);
		}

		$this->filter[] = [
			'LOGIC' => 'OR',
			['*SEARCH_CONTENT' => $preparedSearch],
			['*SEARCH_CONTENT' => $originalRecipientSearch],
		];

		return $this;
	}

	public function addDateFrom(DateTime $date): self
	{
		$this->filter['>=MESSAGE_UID.INTERNALDATE'] = $date;

		return $this;
	}

	public function addDateTo(DateTime $date): self
	{
		$this->filter['<=MESSAGE_UID.INTERNALDATE'] = $date;

		return $this;
	}

	public function resetFilters(): self
	{
		$this->filter = [];

		return $this;
	}

	public function getUserId(): ?int
	{
		return $this->userId;
	}

	public function needsAttachmentsStack(): bool
	{
		return $this->withAttachmentsStack;
	}

	public function getArray(): array
	{
		return $this->filter;
	}
}
