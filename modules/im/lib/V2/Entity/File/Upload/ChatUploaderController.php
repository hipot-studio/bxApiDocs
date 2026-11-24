<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Entity\File\Upload;

use Bitrix\Disk\Driver;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Service\FileService;
use Bitrix\Main\Loader;
use Bitrix\UI\FileUploader\CommitOptions;
use Bitrix\UI\FileUploader\Configuration;
use Bitrix\UI\FileUploader\Contracts\CustomFingerprint;
use Bitrix\UI\FileUploader\FileOwnershipCollection;
use Bitrix\UI\FileUploader\UploaderController;

Loader::requireModule('ui');

/**
 * The ui circuit of the chunked upload door of im.
 *
 * 🔴 THE CHAT IS THE ONE THING THAT CANNOT ARRIVE BY NAME, AND THAT IS WHAT MAKES THIS CLASS SAFE TO
 * CARRY A PERMISSION. A caller of the common ajax door of ui (ui.fileuploader.*) names the controller and
 * its options in the body of the request: the whitelist that would stop him is entered only
 * `if (is_subclass_of($this, FileUploader::class))` ({@see \Bitrix\UI\Controller\FileUploader}), which is
 * FALSE for \Bitrix\UI\Controller\FileUploader itself, and declaring no ui.uploader.allowUseControllers
 * only keeps ControllerResolver from calling includeModule - it does not keep it from reflecting a class
 * that the hit has already loaded. So the options of this controller must be treated as hostile, and they
 * are: the chat is handed over in php by the door and never built out of them, isAvailable() is false
 * without it, and ControllerResolver::createController() - which calls the constructor with ONE argument -
 * can therefore never produce a usable instance of this class.
 *
 * chatId and userId stay in the options because they salt the continuation token, not because anything is
 * decided by them: the permission is asked of the chat the door passed in, for the user that chat is bound
 * to.
 *
 * The fingerprint is the GUID of the upload session instead of bitrix_sessid()
 * ({@see \Bitrix\UI\FileUploader\Uploader::getTokenSalt()}): a webhook holds no browser session, and
 * the continuation token has to survive from one REST call to the next.
 *
 * Loading, viewing and removing stay closed: the door hands the circuit nothing but the bytes of the
 * session it opened itself, and gives nothing of the circuit back to the caller.
 *
 * Renaming the class, or changing the shape of the options, re-salts every live continuation token:
 * the salt is built out of the name of the controller and its options, so an upload already running
 * would break at its next chunk.
 */
final class ChatUploaderController extends UploaderController implements CustomFingerprint
{
	private ?Chat $chat;

	/**
	 * @param array{chatId?: mixed, userId?: mixed, sessionId?: mixed} $options Everything here may have come
	 *        from a request: nothing is decided by it, see the note of the class.
	 * @param Chat|null $chat Already bound to the acting user, and the only way this controller can be given
	 *        one. Null is what a builder outside the door gets, and an instance without a chat is not
	 *        available at all.
	 */
	public function __construct(array $options, ?Chat $chat = null)
	{
		$this->chat = $chat;

		// The options are hashed into the salt of the continuation token, so they are normalized here:
		// a chatId that arrives as '7' once and as 7 the next time would re-salt every live token.
		parent::__construct([
			'chatId' => (int)($options['chatId'] ?? 0),
			'userId' => (int)($options['userId'] ?? 0),
			'sessionId' => (string)($options['sessionId'] ?? ''),
		]);
	}

	/**
	 * 🔴 ASKED BY ONE CALLER ONLY - {@see \Bitrix\UI\FileUploader\ControllerResolver::createController()},
	 * which is to say by a controller name that came from outside. The door never asks it, so the chat
	 * below is the whole barrier: a resolver builds this class with its options alone and gets an instance
	 * that answers "not available", whatever those options say.
	 */
	public function isAvailable(): bool
	{
		return
			$this->chat !== null
			&& $this->getChatId() > 0
			&& $this->getUserId() > 0
			&& $this->getFingerprint() !== ''
			&& Loader::includeModule('disk')
		;
	}

	public function getFingerprint(): string
	{
		return (string)$this->getOption('sessionId', '');
	}

	public function getConfiguration(): Configuration
	{
		$configuration = new Configuration();
		$configuration->setMaxFileSize(FileService::MAX_FILE_SIZE);

		// The one-shot door of im keeps no image policy - a photo passes it on size alone - and both
		// flags hold this door to the same rule. Without them an oversize or unreadable image would be
		// refused here and accepted there, which is a difference the caller never asked for.
		$configuration->setTreatOversizeImageAsFile(true);
		$configuration->setIgnoreUnknownImageTypes(true);

		return $configuration;
	}

	/**
	 * Mirrors CFile::saveFile($fileArray, 'disk', true, true) of Folder::uploadFile(): the file of the
	 * door has to land in b_file exactly the way the working door lands it, random name and no
	 * extension - the default skipExtension = false would leave the only file of Disk with its own.
	 */
	public function getCommitOptions(): CommitOptions
	{
		return new CommitOptions([
			'moduleId' => Driver::INTERNAL_MODULE_ID,
			'savePath' => Driver::INTERNAL_MODULE_ID,
			'forceRandom' => true,
			'skipExtension' => true,
		]);
	}

	/**
	 * The chat the door handed over, and never one built out of the options: a chatId and a userId taken
	 * from a request would turn this into "the right of whoever the body names".
	 */
	public function canUpload(): bool
	{
		return $this->chat?->canDo(Action::Send) ?? false;
	}

	public function canView(): bool
	{
		return false;
	}

	public function canRemove(): bool
	{
		return false;
	}

	public function verifyFileOwner(FileOwnershipCollection $files): void
	{
		// Nothing to verify: the door never accepts a file id from the caller, only its own session.
	}

	private function getChatId(): int
	{
		return (int)$this->getOption('chatId', 0);
	}

	private function getUserId(): int
	{
		return (int)$this->getOption('userId', 0);
	}
}
