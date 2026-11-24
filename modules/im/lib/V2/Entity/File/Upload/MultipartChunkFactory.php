<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Entity\File\Upload;

use Bitrix\Im\Model\EO_FileUploadSession;
use Bitrix\Im\V2\Entity\File\FileError;
use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\IO;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\UI\FileUploader\Chunk;
use Bitrix\UI\FileUploader\TempFile;

Loader::requireModule('ui');

/**
 * Builds the chunk of the ui circuit out of one multipart part of a REST request.
 *
 * 🔴 THE PART CARRIES BYTES AND NOTHING ELSE. The name of the file, its mime type and the size of the
 * whole upload are taken from the session the caller opened, never from the request that brings the
 * chunk: everything the circuit decides by - the format policy, the ceiling of the file, the moment the
 * upload is whole - was already decided once, at the start, and a caller must not be able to move any of
 * it halfway through. The range of the chunk is built the same way: the offset says where the caller
 * thinks it stands, and the end of the range is the offset plus the bytes that really arrived.
 *
 * The transport is multipart/form-data and not a bare binary body, because a bare body travelling
 * through /rest/ is read by {@see \CRestUtil::getRequestData()}, which runs parse_str over the raw bytes
 * and lets whatever that produces overwrite the parameters of the call.
 */
final class MultipartChunkFactory
{
	/** The name of the multipart part the bytes of the chunk arrive in. */
	public const FIELD = 'chunk';

	/**
	 * @param int $offset Byte the caller believes the upload stands at; the service has the last word.
	 *
	 * @return Result data: ['chunk' => Chunk].
	 */
	public static function fromRequest(HttpRequest $request, EO_FileUploadSession $session, int $offset): Result
	{
		$result = new Result();

		$part = $request->getFile(self::FIELD);
		if (!self::isSinglePart($part))
		{
			// A body cut off by post_max_size reaches php with an empty $_FILES and no error of its own,
			// so an absent part and a body that never arrived are one and the same case here.
			return $result->addError(new FileError(FileError::UPLOAD_CHUNK_MISSING));
		}

		$window = ChunkWindow::forFile((string)$session->getFileName(), (int)$session->getFileSize());

		$transportRefusal = self::findTransportRefusal((int)$part['error'], $window);
		if ($transportRefusal !== null)
		{
			return $result->addError($transportRefusal);
		}

		$uploaded = new IO\File((string)$part['tmp_name']);
		if (!$uploaded->isExists())
		{
			return $result->addError(new FileError(FileError::UPLOAD_CHUNK_MISSING));
		}

		$file = self::movePartIntoTemporaryRoot($uploaded);
		if ($file === null)
		{
			// Nothing could be written where the circuit needs the part: a failure of the server, named
			// the way every other failure of the server is named here.
			return $result->addError(new Error('File upload failed', UploadErrorMapper::FALLBACK_CODE));
		}

		$chunk = new Chunk($file);

		// A part of no bytes moves the upload nowhere and would reach the circuit as a chunk whose range
		// ends before it begins. It is named the way an absent part is named: send this one again.
		if ($chunk->getSize() <= 0)
		{
			return $result->addError(new FileError(FileError::UPLOAD_CHUNK_MISSING));
		}

		if ($chunk->getSize() > $window->getMax())
		{
			return $result->addError(new FileError(FileError::UPLOAD_CHUNK_TOO_BIG, $window->toRest()));
		}

		// 🔴 A CHUNK THAT RUNS PAST THE FINISH LINE OF THE SESSION DESTROYS THE WHOLE UPLOAD, and nothing
		// below this point refuses it in time: TempFile::append() raises CHUNK_TOO_BIG for exactly this
		// case, and Uploader::handleUploadError() answers any refusal of an append by deleting the
		// temporary file with every byte accepted so far. A caller that computed its tail with a margin
		// would lose the whole upload over an arithmetic slip. The first chunk is the worse half of it: a
		// part above the declared size opens the circuit with receivedSize already over size, isLast() is
		// then false forever, and finish answers FILE_UPLOAD_NOT_COMPLETE until the session runs out.
		if ($offset + $chunk->getSize() > (int)$session->getFileSize())
		{
			return $result->addError(new FileError(FileError::UPLOAD_CHUNK_TOO_BIG, $window->toRest()));
		}

		$chunk->setName((string)$session->getFileName());
		$chunk->setType((string)$session->getMimeType());
		$chunk->setFileSize((int)$session->getFileSize());
		$chunk->setStartRange($offset);
		$chunk->setEndRange($offset + $chunk->getSize() - 1);

		return $result->setData(['chunk' => $chunk]);
	}

	/**
	 * 🔴 THE PART HAS TO LEAVE upload_tmp_dir BEFORE IT BECOMES A CHUNK, AND THIS IS NOT TIDINESS.
	 *
	 * php stages the temporary file of a multipart part wherever upload_tmp_dir points, and that is not
	 * {@see \CTempFile::getAbsoluteRoot()} on any portal that does not deliberately set the two to the same
	 * place. {@see \Bitrix\UI\FileUploader\TempFile::create()} answers a chunk lying outside that root by
	 * COPYING it, and from that moment the chunk holds the copy while the local variable of create() still
	 * holds the original: the accumulator of the upload is renamed out of the ORIGINAL, while the row of the
	 * circuit is written with the path of the COPY - a copy \CTempFile unlinks at the end of the very same
	 * hit. The next chunk then finds no file, the circuit answers FILE_APPEND_NOT_FOUND, and
	 * Uploader::handleUploadError() deletes the temporary file with every byte accepted so far. Every
	 * upload of more than one chunk died there; a one-chunk one survived only because it is committed
	 * inside the same hit.
	 *
	 * Handing the circuit a part that already lies under the temporary root restores the invariant the three
	 * factories of ui keep ({@see \Bitrix\UI\FileUploader\ChunkFactory}), and move_uploaded_file() asserts
	 * on the way that the part really is one php brought in. A part staged by a test did not come from php
	 * and is moved by the plain rename the kernel itself falls back to ({@see \CFile::SaveFile()}).
	 *
	 * @return IO\File|null The part under the temporary root, or null when it could not be moved there.
	 */
	private static function movePartIntoTemporaryRoot(IO\File $uploaded): ?IO\File
	{
		$source = $uploaded->getPhysicalPath();
		$target = TempFile::generateLocalTempFile();

		/**
		 * rename() cannot cross a filesystem boundary, and the part does not always share one with the
		 * temporary root: upload_tmp_dir may be mounted apart, which is what a test fixture writing into
		 * /tmp hits. move_uploaded_file has no such limit, so the copy below is only ever reached off the
		 * production path - and there it is the same fallback CFile::SaveFile keeps for the same reason.
		 */
		$moved = is_uploaded_file($source) ? move_uploaded_file($source, $target) : rename($source, $target);
		if (!$moved && !is_uploaded_file($source))
		{
			$moved = copy($source, $target) && unlink($source);
		}

		if (!$moved)
		{
			return null;
		}

		$file = new IO\File($target);

		return $file->isExists() ? $file : null;
	}

	/**
	 * One part under the name of the field, and not a list of them: a caller sending chunk[] would
	 * otherwise hand arrays where the keys of $_FILES are expected.
	 */
	private static function isSinglePart(mixed $part): bool
	{
		return
			is_array($part)
			&& isset($part['error'], $part['tmp_name'])
			&& is_scalar($part['error'])
			&& is_string($part['tmp_name'])
		;
	}

	/**
	 * What php itself says about the part, translated into the dictionary of the door.
	 *
	 * The two ceilings of a form are named by the window, so the answer always carries the numbers the
	 * caller has to fit into. Everything else here is a failure of the server - no temporary directory,
	 * nothing could be written - and leaves as the general refusal of an upload.
	 */
	private static function findTransportRefusal(int $error, ChunkWindow $window): ?Error
	{
		return match ($error)
		{
			UPLOAD_ERR_OK => null,
			UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE
				=> new FileError(FileError::UPLOAD_CHUNK_TOO_BIG, $window->toRest()),
			// A part that arrived truncated is a part the caller has to send again, like an absent one.
			UPLOAD_ERR_NO_FILE, UPLOAD_ERR_PARTIAL => new FileError(FileError::UPLOAD_CHUNK_MISSING),
			default => new Error('File upload failed', UploadErrorMapper::FALLBACK_CODE),
		};
	}
}
