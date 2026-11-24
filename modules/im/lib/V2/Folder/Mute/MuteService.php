<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Mute;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Error;
use Bitrix\Im\V2\Folder\BaseFolder;
use Bitrix\Im\V2\Folder\Cache\FolderCache;
use Bitrix\Im\V2\Folder\Definition\SectionDefinition;
use Bitrix\Im\V2\Folder\Error\FolderError;
use Bitrix\Im\V2\Folder\Folder;
use Bitrix\Im\V2\Folder\Pull\FolderUpdate;
use Bitrix\Im\V2\Folder\System\AllFolder;
use Bitrix\Im\V2\Folder\System\OpenLineFolder;
use Bitrix\Im\V2\Folder\System\SystemFolder;
use Bitrix\Im\V2\Reading\Counter\Internal\CountersCache;

/**
 * The single point of writing the rule of the folder (API-01): notifications of the chats of a
 * folder are off or back on. The rule lives on the row of the folder and says nothing about its
 * chats - the muted state of a chat is derived where it is used and is stored nowhere.
 *
 * Separate from {@see \Bitrix\Im\V2\Folder\FolderService::update()} on purpose: that path turns
 * down every folder but a personal one, and a system folder obeys the rule like any other, save
 * the ones listed in {@see self::UNMUTABLE_FOLDER_CODES}.
 *
 * No transaction, as everywhere in the folder domain. The order carries the same property instead:
 * every check runs before the write, and the caches are dropped before the event goes out, so a
 * client that reacts to the event reads the new rule and not the snapshot from before it.
 */
class MuteService
{
	/**
	 * System folders that never take a rule, by the code. "All" shows every chat of the user, so its
	 * rule would mute the whole messenger by a folder nobody composed. The list of open lines is drawn
	 * by the imopenlines module, which follows no rule of a folder: its counters would stay accented
	 * while the push of the same chats went silent. The reader skips a rule such a folder still carries
	 * ({@see MuteProvider}), so a row written before the refusal stops silencing anything.
	 */
	public const UNMUTABLE_FOLDER_CODES = [AllFolder::CODE, OpenLineFolder::CODE];

	public function __construct(
		private readonly FolderCache $folderCache,
		private readonly MuteCache $muteCache,
		private readonly CountersCache $countersCache,
	) {}

	public function setMuted(Folder $folder, bool $isMuted): MuteResult
	{
		$result = new MuteResult();

		if (!Features::isFolderMuteAvailable())
		{
			return $result->addError(new Error(FolderError::FOLDER_OPERATION_NOT_SUPPORTED));
		}

		// Narrowed once for the whole method: the rule is stated over {@see BaseFolder}, the
		// interface carries neither its value nor the write of it.
		if (!$folder instanceof BaseFolder)
		{
			return $result->addError(new Error(FolderError::FOLDER_OPERATION_NOT_SUPPORTED));
		}

		// Checked here and not only by the prefilter of the controller: the service is called from
		// other entry points as well, and the owner of a folder is the only one who may rule it.
		$accessResult = $folder->checkAccess();
		if (!$accessResult->isSuccess())
		{
			return $result->addErrors($accessResult->getErrors());
		}

		// By the code and not by the shape of the definition: a composite folder can be assembled
		// from a single source, and such a folder is still not "All". Refused in both directions,
		// since neither client offers the item for these folders.
		if (in_array($folder->getCode(), self::UNMUTABLE_FOLDER_CODES, true))
		{
			return $result->addError(new Error(FolderError::FOLDER_OPERATION_NOT_SUPPORTED));
		}

		// The client has no control for a folder hidden by the system, and the read path deliberately
		// ignores its rule. Refuse creating such a rule, while still allowing `false` to clean an old
		// NOTIFY_BLOCK left by a visible -> hidden transition or an older client.
		if ($isMuted && $folder->isHiddenBySystem())
		{
			return $result->addError(new Error(FolderError::FOLDER_OPERATION_NOT_SUPPORTED));
		}

		// A system folder whose composition is not a section of the recent list holds no chats of one,
		// and a rule on it could not be honoured: the read path would have to invent the chats it
		// covers ({@see MuteProvider::loadForUsers()} skips such a folder). Refused here as well, so
		// the row never carries a rule the reader ignores. The open-channel showcase is the case that
		// exists today - it carries a section name but an {@see UnsupportedDefinition}.
		if ($folder instanceof SystemFolder && !$folder->getDefinition() instanceof SectionDefinition)
		{
			return $result->addError(new Error(FolderError::FOLDER_OPERATION_NOT_SUPPORTED));
		}

		if ($folder->isMuted() === $isMuted)
		{
			return $result->setFolder($folder);
		}

		$saveResult = $folder->setMuted($isMuted)->save();
		if (!$saveResult->isSuccess())
		{
			return $result->addErrors($saveResult->getErrors());
		}

		$userId = (int)$folder->getUserId();
		$this->folderCache->clearByUser($userId);
		$this->muteCache->clearByUser($userId);
		// Counters of the user are dropped by the service and not by the caller: the processed
		// representation of a counter is built from the rules of the folders, and the legacy cache
		// holding it is cleaned inside remove().
		$this->countersCache->remove($userId);

		(new FolderUpdate($folder, $userId))->send();

		return $result->setFolder($folder);
	}
}
