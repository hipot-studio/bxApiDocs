<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Source;

use Bitrix\Im\Model\FolderSourceTable;
use Bitrix\Im\V2\Error;
use Bitrix\Im\V2\Folder\Cache\FolderCache;
use Bitrix\Im\V2\Folder\Definition\CompositeDefinition;
use Bitrix\Im\V2\Folder\Error\FolderError;
use Bitrix\Im\V2\Folder\Folder;
use Bitrix\Im\V2\Folder\FolderCollection;
use Bitrix\Im\V2\Folder\FolderProvider;
use Bitrix\Im\V2\Folder\Pin\PinService;
use Bitrix\Im\V2\Folder\Pull\FolderUpdate;
use Bitrix\Im\V2\Folder\System\AllFolder;
use Bitrix\Im\V2\Folder\System\ShowcaseSourceRegistry;
use Bitrix\Im\V2\Folder\System\SystemFolder;
use Bitrix\Im\V2\Folder\Visibility\FolderVisibilityLock;
use Bitrix\Im\V2\Folder\Visibility\FolderVisibilityResolver;

/**
 * Composition of a composite folder ("All") as its settings screen needs it (ALG-01): one
 * item per source the user may switch, the showcase items shown locked, and the folder
 * objects the items take their texts from.
 *
 * The order is the one the canonical registry fixed ({@see AllFolder::getDefaultDefinition}),
 * with showcase items placed by their own anchor; it is deliberately not sorted by
 * {@see SystemFolder::getDefaultPosition} - the positions of the invisible sources are parked
 * at the tail and sorting by them would produce the wrong screen.
 *
 * The write side lives here as well (ALG-02): the composition is saved as the full snapshot the
 * client was shown, normalized against the same registry the screen was drawn from.
 *
 * Both entry points take the owner from the folder itself: a (folder, user) pair passed in from
 * outside can disagree, and a disagreeing pair would write the composition of one user under
 * another and push the change to the wrong recipient.
 *
 * Two kinds of code meet here, and this service is the only place they are translated: the client
 * speaks the wire codes of the folders ({@see SystemFolder::getRestCode()}), while the table, the
 * canonical registry and the merge speak the stored ones ({@see SystemFolder::getCode()}). Reading
 * turns stored into wire, writing turns wire back into stored, and neither half ever compares a
 * code of one kind with a code of the other.
 *
 * The invariant of a hidden folder crosses both halves as one rule ({@see self::getLockedSourceCodes}):
 * the source feeding a section whose navigation folder the owner has hidden is shown locked by the
 * reading half and kept mandatory by the writing one, so neither can let it go while the other
 * promises it is there.
 */
class FolderSourceService
{
	public function __construct(
		private readonly FolderProvider $folderProvider,
		private readonly ShowcaseSourceRegistry $showcaseSourceRegistry,
		private readonly PinService $pinService,
		private readonly FolderCache $folderCache,
		private readonly FolderVisibilityResolver $visibilityResolver,
		private readonly FolderVisibilityLock $visibilityLock,
	)
	{
	}

	/**
	 * Writes no composition row of its own - but the first read of the folder list seeds the
	 * composition ({@see FolderProvider::getByUser}), for this caller as for any other reader.
	 */
	public function getSources(Folder $folder): SourcesResult
	{
		$result = new SourcesResult();

		$composition = $this->getComposition($folder);
		if ($composition === null)
		{
			return $result->addError(new Error(FolderError::FOLDER_OPERATION_NOT_SUPPORTED));
		}

		$userId = $this->getOwnerId($folder);
		if ($userId <= 0)
		{
			return $result->addError(new Error(FolderError::FOLDER_OPERATION_NOT_SUPPORTED));
		}

		$foldersByCode = $this->getFoldersByCode($userId);
		$composition = $this->getSeededComposition($foldersByCode) ?? $composition;
		$lockedSourceCodes = $this->getLockedSourceCodes($userId, $foldersByCode);

		// The stored composition arrives as wire codes ({@see CompositeDefinition::toRestFormat}),
		// so an item is looked up in it by the wire code it carries itself. Membership is not
		// expanded here: a source with no chats yet stays an enabled source.
		$storedCodes = array_fill_keys($composition->toRestFormat()['sourceFolders'] ?? [], true);

		/** @var SourceItem[] $items */
		$items = [];
		/** @var array<string, string> $itemCodeBySourceCode */
		$itemCodeBySourceCode = [];

		foreach (AllFolder::getDefaultDefinition() as $entry)
		{
			// AC-014: the mandatory source is not switchable, so it gets no item at all.
			if ($entry->mandatory)
			{
				continue;
			}

			// AC-039: a source unavailable to the user is not shown locked, it is not shown at all.
			$sourceFolder = $this->findAvailableFolder($foldersByCode, $entry->code, $userId);
			if ($sourceFolder === null)
			{
				continue;
			}

			// AC-015: a source the invariant holds keeps its row on the screen and loses the switch.
			// Dropping the item instead would take the explanation away together with the control.
			$folderHidden = isset($lockedSourceCodes[$entry->code]);

			$wireCode = (string)$sourceFolder->getRestCode();
			$item = new SourceItem(
				folderId: (int)$sourceFolder->getId(),
				code: $wireCode,
				enabled: isset($storedCodes[$wireCode]),
				togglable: !$folderHidden,
				lockReason: $folderHidden ? LockReason::FolderHidden : null,
			);

			$items[] = $item;
			$itemCodeBySourceCode[$entry->code] = $item->code;
		}

		foreach ($this->showcaseSourceRegistry->getAll() as $showcase)
		{
			// AC-019: a showcase source the user cannot have is not shown either.
			$sourceFolder = $this->findAvailableFolder($foldersByCode, $showcase->folderCode, $userId);
			if ($sourceFolder === null)
			{
				continue;
			}

			$items = $this->insertAfter(
				$items,
				$this->resolveAnchorCode($showcase->afterCode, $itemCodeBySourceCode),
				new SourceItem(
					folderId: (int)$sourceFolder->getId(),
					code: (string)$sourceFolder->getRestCode(),
					enabled: false,
					togglable: false,
					lockReason: LockReason::Showcase,
				),
			);
		}

		return $result->setSourceList(
			new SourceList($items, $this->collectItemFolders($items, $foldersByCode)),
		);
	}

	/**
	 * Full-snapshot write of a composite folder's composition (ALG-02): the codes the client wants
	 * enabled, merged with what is stored, then applied to b_im_folder_source.
	 *
	 * The write path is this service and not {@see \Bitrix\Im\V2\Folder\FolderService::update()} -
	 * that one turns down everything but a personal folder before it looks at the fields.
	 *
	 * @param string[] $sourceCodes every code that must stay enabled, in the wire codes the screen
	 *        was drawn with ({@see SourceItem::$code}); the empty set is legitimate and means
	 *        "everything off except the mandatory source"
	 */
	public function updateSources(Folder $folder, array $sourceCodes): UpdateSourcesResult
	{
		return $this->writeComposition(
			$folder,
			fn (array $storedCodes, array $storedCodeByWireCode): array
				=> $this->toStoredCodes($sourceCodes, $storedCodeByWireCode),
		);
	}

	/**
	 * Switches a set of sources on over the stored composition and leaves the rest of it alone -
	 * the write the invariant of a hidden folder is kept by (AC-013): a section may leave the
	 * navigation only while its chats stay reachable through "All", so hiding it switches its
	 * source back on first.
	 *
	 * Not expressible through {@see self::updateSources()}: that one takes the snapshot of the
	 * client and reads a missing code as a source switched off, so a call carrying part of the
	 * codes would switch off everything else.
	 *
	 * A whole set and not one code: one save of the "Folder list" screen can hide several sections
	 * at once (API-02). The set goes through one lock and one merge, so a partial return does not
	 * happen - either every section of it is back or none is; hiding a single folder (API-01) is
	 * the same call with a set of one.
	 *
	 * @param string[] $storedCodesToEnable sources to switch on, in the codes the composition is
	 *        stored under ({@see SystemFolder::getCode()}) - the kind
	 *        {@see \Bitrix\Im\V2\Folder\Visibility\FolderVisibilityResolver::resolveSourceToReturn}
	 *        answers in. A set of codes already enabled, the empty set included, writes nothing
	 *        and sends no event.
	 */
	public function enableSources(Folder $folder, array $storedCodesToEnable): UpdateSourcesResult
	{
		return $this->writeComposition(
			$folder,
			static fn (array $storedCodes, array $storedCodeByWireCode): array
				=> array_merge($storedCodes, $storedCodesToEnable),
		);
	}

	/**
	 * The composition really stored for the folder, in the codes the merge and the canonical
	 * registry speak ({@see SystemFolder::getCode()}) - the input the invariant of a hidden folder
	 * is answered against ({@see \Bitrix\Im\V2\Folder\Visibility\FolderVisibilityResolver::resolveSourceToReturn}).
	 *
	 * The shape published to the client ({@see CompositeDefinition::toRestFormat()}) cannot serve
	 * it: that one speaks wire codes and drops the sources unavailable to the owner, and the
	 * invariant asks about the stored row.
	 *
	 * A folder with no composition of ours answers the empty set, the same soft refusal the
	 * reading half gives it.
	 *
	 * @return string[]
	 */
	public function getStoredSourceCodes(Folder $folder): array
	{
		if ($this->getComposition($folder) === null)
		{
			return [];
		}

		return $this->loadStoredCodes((int)$folder->getId());
	}

	/**
	 * The single write path of the composition, shared by both entry points: the lock, the merge
	 * against what is really stored, insert before delete, the side effects, and the answer and
	 * the push built from one re-read folder.
	 *
	 * The entries differ in the desired composition alone, so they hand it in as a closure that is
	 * called under the lock - the stored codes it may need are read there and nowhere earlier.
	 * Each entry uses one of the two arguments and ignores the other: the snapshot write speaks
	 * wire codes and needs the map, the enabling write already speaks stored codes and needs the
	 * stored composition.
	 *
	 * @param \Closure(string[], array<string, string>): string[] $resolveDesiredCodes stored
	 *        composition and "wire code => stored code" of the sources this user may switch,
	 *        answered with the codes that must end up enabled, in stored codes
	 */
	private function writeComposition(Folder $folder, \Closure $resolveDesiredCodes): UpdateSourcesResult
	{
		$result = new UpdateSourcesResult();

		if ($this->getComposition($folder) === null)
		{
			return $result->addError(new Error(FolderError::FOLDER_OPERATION_NOT_SUPPORTED));
		}

		$folderId = (int)$folder->getId();
		$userId = $this->getOwnerId($folder);
		if ($userId <= 0)
		{
			return $result->addError(new Error(FolderError::FOLDER_OPERATION_NOT_SUPPORTED));
		}

		// The snapshot is written as a difference against what is stored, and the sources a hidden
		// folder holds are mandatory in it (AC-015), so the stored codes, the hidden flags and the
		// write are one step for the owner. Two writers reading the same state would otherwise each
		// drop what the other keeps - a save from one device could switch off the source of a section
		// a hide from another one has just taken out of the navigation.
		if (!$this->visibilityLock->acquire($userId))
		{
			// Nothing has been written yet, so the refusal is the whole outcome: the client keeps the
			// choice on screen and the save can simply be repeated.
			return $result->addError(new Error(FolderError::FOLDER_OPERATION_NOT_SUPPORTED));
		}

		try
		{
			$foldersByCode = $this->getFoldersByCode($userId);
			$this->refreshHiddenByUser($foldersByCode, $userId);
			$lockedSourceCodes = $this->getLockedSourceCodes($userId, $foldersByCode);

			$manageable = [];
			$mandatory = [];
			/** @var array<string, string> $storedCodeByWireCode */
			$storedCodeByWireCode = [];
			foreach (AllFolder::getDefaultDefinition() as $entry)
			{
				$sourceFolder = $this->findAvailableFolder($foldersByCode, $entry->code, $userId);
				if ($sourceFolder !== null)
				{
					$manageable[] = $entry->code;
					$storedCodeByWireCode[(string)$sourceFolder->getRestCode()] = $entry->code;
				}

				// AC-015: a locked source is mandatory for as long as the folder stays hidden. The
				// screen shows it switched off nowhere, so the snapshot never carries it back and the
				// save would drop it; the merge returns it silently instead, and the rest of the same
				// save applies as it is (AC-018).
				if ($entry->mandatory || isset($lockedSourceCodes[$entry->code]))
				{
					$mandatory[] = $entry->code;
				}
			}

			// Read after getFoldersByCode(), which seeds the composition: an earlier snapshot comes back empty.
			$storedCodes = $this->loadStoredCodes($folderId);

			$diff = SourceComposition::merge(
				$storedCodes,
				$resolveDesiredCodes($storedCodes, $storedCodeByWireCode),
				$manageable,
				$mandatory,
			);

			// Attached here and not at each return: every outcome below this line has the composition
			// counted, the save that changed nothing included, and a list attached means to the caller
			// exactly that (ALG-01). The refusals above keep it null - nothing was written there.
			$result->setDisabledSourceCodes($diff->disabled);

			// Nothing to change - no folderUpdate noise. The client sends the whole snapshot on every
			// save, so a save that changes nothing is an ordinary case and not an exception.
			if ($diff->isEmpty())
			{
				// Answered from the composition the list was rebuilt with, for the reason
				// {@see getSeededComposition()} names: the object of the binder predates the seeding.
				$seededComposition = $this->getSeededComposition($foldersByCode);

				return $result->setSourceFolders(
					$seededComposition === null
						? $this->readSourceFolders($folder)
						: ($seededComposition->toRestFormat()['sourceFolders'] ?? []),
				);
			}

			// Insert before delete, and no transaction - the folder domain keeps its integrity by the
			// order of operations: a request torn between the two leaves a source enabled rather than
			// losing its row.
			$this->insertRows($folderId, $userId, $diff->toInsert);
			$this->deleteRows($folderId, $userId, $diff->toRemove);
		}
		finally
		{
			// Released as soon as the rows are settled: everything below reads the result of the write
			// back and reacts to it, and holding the owner through the cache reset and the re-read
			// would only make the next save of the same user wait for work that no longer decides
			// anything.
			$this->visibilityLock->release($userId);
		}

		// A chat the save took out of "All" entirely loses its pin there, and switching the source back
		// on does not return it ({@see PinService::syncShadowsOnSourcesRemove()}).
		$this->pinService->syncShadowsOnSourcesRemove($folder, $diff->toRemove, $diff->enabled);

		// The composition drives the folder list, so the list cache of the user goes with the write.
		$this->folderCache->clearByUser($userId);

		// The answer and the push are built from one re-read object: getById() goes straight to the
		// table, past FolderCache, and hands the composition back in the canonical registry order.
		$updatedFolder = $this->folderProvider->getById($folderId);
		if ($updatedFolder === null)
		{
			// The rows are already written, and the object of the caller carries the composition from
			// before the write: answering with it - or pushing it - would roll the saved change back on
			// the screen without a word. The save stands, the state to show it with is what is missing.
			return $result->addError(new Error(FolderError::FOLDER_NOT_FOUND));
		}

		(new FolderUpdate($updatedFolder, $userId))->send();

		return $result->setSourceFolders($this->readSourceFolders($updatedFolder));
	}

	/**
	 * Composition of "All", or null when the folder has none. Shared by every method of the
	 * service: a folder with no composition of ours has nothing to read or to write, and answers
	 * FOLDER_OPERATION_NOT_SUPPORTED either way.
	 *
	 * Narrowed to {@see AllFolder} and not to {@see CompositeDefinition} on purpose: both halves
	 * take the sources from the registry of "All" ({@see AllFolder::getDefaultDefinition}), so a
	 * second composite folder would be served the source list of another one and would rewrite its
	 * own composition with foreign codes.
	 *
	 * A folder the owner cannot have has no composition either. The rows outlive the release gate -
	 * they are written the moment the folder is seeded - so a client holding a folderId from the
	 * time the gate was open would otherwise keep reading and rewriting the composition through
	 * plain REST calls after it was closed. The list half of the API drops the folder for the same
	 * reason ({@see \Bitrix\Im\V2\Controller\Folder::listAction}), and the refusal is soft, as
	 * {@see SystemFolder::isAvailable()} prescribes for a domain rule that can flip under a request.
	 */
	private function getComposition(Folder $folder): ?CompositeDefinition
	{
		if (!$folder instanceof AllFolder || !$folder->isAvailable($this->getOwnerId($folder)))
		{
			return null;
		}

		$definition = $folder->getDefinition();

		return $definition instanceof CompositeDefinition ? $definition : null;
	}

	/**
	 * Composition of "All" as the seeding inside {@see getFoldersByCode()} left it, or null when the
	 * rebuilt list carries no such folder.
	 *
	 * Taken after that seeding on purpose, and this is why the folder of the caller is not read
	 * directly: the object arriving from the binder was hydrated before the seeding and is never
	 * rehydrated, so its own composition can be older than the rows. The write half keeps the same
	 * order at its own read of the stored codes.
	 *
	 * @param array<string, SystemFolder> $foldersByCode
	 */
	private function getSeededComposition(array $foldersByCode): ?CompositeDefinition
	{
		$seededFolder = $foldersByCode[AllFolder::CODE] ?? null;

		return $seededFolder === null ? null : $this->getComposition($seededFolder);
	}

	/**
	 * Owner of the folder, the single source of the user for every step of both entry points -
	 * see the class docblock. Zero means the folder belongs to nobody (a registry prototype), and
	 * both entry points turn such a folder down instead of reading or writing the composition of
	 * user 0.
	 */
	private function getOwnerId(Folder $folder): int
	{
		return (int)($folder->getUserId() ?? 0);
	}

	/**
	 * Folders of the user keyed by their stored code - {@see FolderCollection} cannot look a
	 * folder up by code, so the collection is walked once. Unavailable folders are kept on
	 * purpose: an item is bound to its folder object, and a source has to be found before it
	 * can be dropped.
	 *
	 * This first read of the list also seeds the composition of "All" ({@see FolderProvider::getByUser}),
	 * which is why both halves of the service read the composition only after this call.
	 *
	 * @return array<string, SystemFolder>
	 */
	private function getFoldersByCode(int $userId): array
	{
		$foldersByCode = [];

		foreach ($this->folderProvider->getByUser($userId) as $folder)
		{
			if (!$folder instanceof SystemFolder)
			{
				continue;
			}

			$code = $folder->getCode();
			if ($code !== null)
			{
				$foldersByCode[$code] = $folder;
			}
		}

		return $foldersByCode;
	}

	/**
	 * @param array<string, SystemFolder> $foldersByCode
	 */
	private function findAvailableFolder(array $foldersByCode, string $code, int $userId): ?SystemFolder
	{
		$folder = $foldersByCode[$code] ?? null;

		return $folder !== null && $folder->isAvailable($userId) ? $folder : null;
	}

	/**
	 * Stored codes of the sources the invariant of a hidden folder holds: their recent section is
	 * reachable through "All" alone, because the navigation folder of that section is hidden by the
	 * owner (AC-015). Computed once per call of either half and read by both - the item the reading
	 * half publishes locked and the code the writing half keeps mandatory are the same fact, and a
	 * second statement of it would let the screen and the save disagree.
	 *
	 * The rule is stated over recent sections and not over codes: a source and the navigation folder
	 * of its section carry different codes, and the sections are what the invariant is written in
	 * ({@see FolderVisibilityResolver::getSectionsLockedByHiddenFolders}).
	 *
	 * Narrowed to the sources available to the owner, as both halves are: an unavailable source has
	 * no item and is not manageable, so locking it would only add a row nobody could have removed.
	 *
	 * The folders of the user are the ones already read here, and the personal ones missing from that
	 * list change nothing: a personal folder holds no recent section and locks none.
	 *
	 * @param array<string, SystemFolder> $foldersByCode
	 * @return array<string, true> stored source code => true
	 */
	private function getLockedSourceCodes(int $userId, array $foldersByCode): array
	{
		$lockedSections = array_fill_keys(
			$this->visibilityResolver->getSectionsLockedByHiddenFolders(
				$userId,
				$this->toFolderCollection($foldersByCode),
			),
			true,
		);

		$lockedCodes = [];
		foreach (AllFolder::getDefaultDefinition() as $entry)
		{
			$section = $this->findAvailableFolder($foldersByCode, $entry->code, $userId)?->getRecentSection();
			if ($section !== null && isset($lockedSections[$section]))
			{
				$lockedCodes[$entry->code] = true;
			}
		}

		return $lockedCodes;
	}

	/**
	 * The hidden flags of the folders brought up to the rows: the list may come from the cache of this
	 * hit, while the lock is only worth something if the flags are the ones its previous holder wrote.
	 *
	 * @param array<string, SystemFolder> $foldersByCode
	 */
	private function refreshHiddenByUser(array $foldersByCode, int $userId): void
	{
		$hiddenFolderIds = array_flip($this->folderProvider->getHiddenByUserFolderIds($userId));

		foreach ($foldersByCode as $folder)
		{
			$folder->setHiddenByUser(isset($hiddenFolderIds[(int)$folder->getId()]));
		}
	}

	/**
	 * The folders this service has already read, as the collection the visibility rule is stated
	 * over. Built from that list and not read again: the rule is an addition to both halves and
	 * must not cost either of them a second query.
	 *
	 * @param array<string, SystemFolder> $foldersByCode
	 */
	private function toFolderCollection(array $foldersByCode): FolderCollection
	{
		$collection = new FolderCollection();
		foreach ($foldersByCode as $folder)
		{
			$collection->add($folder);
		}

		return $collection;
	}

	/**
	 * Wire code of the item a showcase item must follow: the anchor names a source of the
	 * canonical registry, while items carry wire codes. Null - and so the tail of the list -
	 * when the entry has no anchor or the anchor source produced no item of its own (TPL-02).
	 *
	 * @param array<string, string> $itemCodeBySourceCode source code => wire code of its item
	 */
	private function resolveAnchorCode(?string $afterCode, array $itemCodeBySourceCode): ?string
	{
		if ($afterCode === null)
		{
			return null;
		}

		return $itemCodeBySourceCode[$afterCode] ?? null;
	}

	/**
	 * @param SourceItem[] $items
	 * @return SourceItem[]
	 */
	private function insertAfter(array $items, ?string $afterItemCode, SourceItem $item): array
	{
		$offset = count($items);

		if ($afterItemCode !== null)
		{
			foreach ($items as $index => $existing)
			{
				if ($existing->code === $afterItemCode)
				{
					$offset = $index + 1;
					break;
				}
			}
		}

		array_splice($items, $offset, 0, [$item]);

		return $items;
	}

	/**
	 * Source folders of exactly the items that made it into the list, in the same order. The
	 * items are joined to the folders by id, the only bond of DTO-01.
	 *
	 * @param SourceItem[] $items
	 * @param array<string, SystemFolder> $foldersByCode
	 */
	private function collectItemFolders(array $items, array $foldersByCode): FolderCollection
	{
		$foldersById = [];
		foreach ($foldersByCode as $folder)
		{
			$foldersById[(int)$folder->getId()] = $folder;
		}

		$collection = new FolderCollection();
		foreach ($items as $item)
		{
			$folder = $foldersById[$item->folderId] ?? null;
			if ($folder !== null)
			{
				$collection->add($folder);
			}
		}

		return $collection;
	}

	/**
	 * Snapshot of the client translated from the wire codes the screen was drawn with
	 * ({@see SourceItem::$code}) into the codes the composition is stored under
	 * ({@see SystemFolder::getCode()}). The two differ as soon as a source publishes a wire code of
	 * its own ({@see SystemFolder::getRestCode()}), and everything below - the stored snapshot, the
	 * canonical registry, the merge - speaks stored codes only.
	 *
	 * A wire code of no manageable source of this user resolves to nothing and is dropped here: a
	 * showcase item and an unknown code alike never become a composition row (AC-020).
	 *
	 * @param string[] $wireCodes
	 * @param array<string, string> $storedCodeByWireCode wire code => stored code of a manageable source
	 * @return string[]
	 */
	private function toStoredCodes(array $wireCodes, array $storedCodeByWireCode): array
	{
		$storedCodes = [];
		foreach ($storedCodeByWireCode as $wireCode => $storedCode)
		{
			if (in_array((string)$wireCode, $wireCodes, true))
			{
				$storedCodes[] = $storedCode;
			}
		}

		return $storedCodes;
	}

	/**
	 * Codes really stored for the folder, filtered by nothing - the input D17 asks for. Taken from
	 * the table and not from the folder object: {@see AllFolder::setSourceCodes()} has already
	 * dropped the codes outside the registry and {@see CompositeDefinition::toRestFormat()} the
	 * ones unavailable to the owner, while both kinds have to survive the save.
	 *
	 * @return string[]
	 */
	private function loadStoredCodes(int $folderId): array
	{
		if ($folderId <= 0)
		{
			return [];
		}

		$rows = FolderSourceTable::query()
			->setSelect(['SOURCE_FOLDER_CODE'])
			->where('FOLDER_ID', $folderId)
			->fetchAll()
		;

		$codes = [];
		foreach ($rows as $row)
		{
			$codes[] = (string)$row['SOURCE_FOLDER_CODE'];
		}

		return $codes;
	}

	/**
	 * @param string[] $codes
	 */
	private function insertRows(int $folderId, int $userId, array $codes): void
	{
		if ($folderId <= 0 || $codes === [])
		{
			return;
		}

		$rows = [];
		foreach ($codes as $code)
		{
			$rows[] = [
				'FOLDER_ID' => $folderId,
				'USER_ID' => $userId,
				'SOURCE_FOLDER_CODE' => $code,
			];
		}

		// Two devices enabling the same source at once race over the keys of UIX_B_IM_FOLDER_SOURCE_1.
		FolderSourceTable::multiplyInsertWithoutDuplicate(
			$rows,
			['UNIQUE_FIELDS' => ['FOLDER_ID', 'SOURCE_FOLDER_CODE'], 'DEADLOCK_SAFE' => true]
		);
	}

	/**
	 * @param string[] $codes
	 */
	private function deleteRows(int $folderId, int $userId, array $codes): void
	{
		if ($folderId <= 0 || $codes === [])
		{
			return;
		}

		// Narrowed by the owner as everywhere else in the domain: a composition row is always
		// stored under the owner of its folder, so the predicate costs nothing.
		FolderSourceTable::deleteBatch([
			'=FOLDER_ID' => $folderId,
			'=USER_ID' => $userId,
			'=SOURCE_FOLDER_CODE' => $codes,
		]);
	}

	/**
	 * Composition of the folder the way the client gets it - the very list that rides inside
	 * folder.definition of the folderUpdate push (EVENT-01).
	 *
	 * @return string[]
	 */
	private function readSourceFolders(Folder $folder): array
	{
		return $folder->getDefinition()->toRestFormat()['sourceFolders'] ?? [];
	}
}
