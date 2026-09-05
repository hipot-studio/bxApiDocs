<?php

namespace Bitrix\UI\FileUploader;

use Bitrix\Main\Localization\Loc;

Loc::loadLanguageFile(__DIR__ . '/UserErrors.php');

class UploaderError extends \Bitrix\Main\Error
{
	protected string $description = '';
	protected bool $system = false;

	public const MAX_FILE_SIZE_EXCEEDED = 'MAX_FILE_SIZE_EXCEEDED';
	public const MIN_FILE_SIZE_EXCEEDED = 'MIN_FILE_SIZE_EXCEEDED';
	public const FILE_TYPE_NOT_ALLOWED = 'FILE_TYPE_NOT_ALLOWED';
	public const FILE_NAME_NOT_ALLOWED = 'FILE_NAME_NOT_ALLOWED';
	public const IMAGE_MAX_FILE_SIZE_EXCEEDED = 'IMAGE_MAX_FILE_SIZE_EXCEEDED';
	public const IMAGE_MIN_FILE_SIZE_EXCEEDED = 'IMAGE_MIN_FILE_SIZE_EXCEEDED';
	public const IMAGE_TYPE_NOT_SUPPORTED = 'IMAGE_TYPE_NOT_SUPPORTED';
	public const IMAGE_IS_TOO_SMALL = 'IMAGE_IS_TOO_SMALL';
	public const IMAGE_IS_TOO_BIG = 'IMAGE_IS_TOO_BIG';

	public const SAVE_FILE_FAILED = 'SAVE_FILE_FAILED';
	public const FILE_LOAD_FAILED = 'FILE_LOAD_FAILED';
	public const FILE_UPLOAD_ACCESS_DENIED = 'FILE_UPLOAD_ACCESS_DENIED';
	public const FILE_LOAD_ACCESS_DENIED = 'FILE_LOAD_ACCESS_DENIED';
	public const FILE_REMOVE_ACCESS_DENIED = 'FILE_REMOVE_ACCESS_DENIED';

	public const INVALID_CONTENT_RANGE = 'INVALID_CONTENT_RANGE';
	public const INVALID_CONTENT_TYPE = 'INVALID_CONTENT_TYPE';
	public const INVALID_CONTENT_LENGTH = 'INVALID_CONTENT_LENGTH';
	public const INVALID_CONTENT_NAME = 'INVALID_CONTENT_NAME';
	public const INVALID_FILENAME = 'INVALID_FILENAME';
	public const INVALID_RANGE_SIZE = 'INVALID_RANGE_SIZE';
	public const INVALID_CHUNK_SIZE = 'INVALID_CHUNK_SIZE';
	public const INVALID_CHUNK_OFFSET = 'INVALID_CHUNK_OFFSET';
	public const TOO_BIG_REQUEST = 'TOO_BIG_REQUEST';
	public const FILE_FIND_FAILED = 'FILE_FIND_FAILED';
	public const FILE_MOVE_FAILED = 'FILE_MOVE_FAILED';
	public const FILE_APPEND_NOT_FOUND = 'FILE_APPEND_NOT_FOUND';
	public const CHUNK_NOT_FOUND = 'CHUNK_NOT_FOUND';
	public const CHUNK_COPY_FAILED = 'CHUNK_COPY_FAILED';
	public const CHUNK_TOO_BIG = 'CHUNK_TOO_BIG';
	public const CHUNK_APPEND_NOT_FOUND = 'CHUNK_APPEND_NOT_FOUND';
	public const CHUNK_APPEND_FAILED = 'CHUNK_APPEND_FAILED';
	public const CLOUD_EMPTY_BUCKET = 'CLOUD_EMPTY_BUCKET';
	public const CLOUD_INVALID_CHUNK_SIZE = 'CLOUD_INVALID_CHUNK_SIZE';
	public const CLOUD_GET_CONTENTS_FAILED = 'CLOUD_GET_CONTENTS_FAILED';
	public const CLOUD_START_UPLOAD_FAILED = 'CLOUD_START_UPLOAD_FAILED';
	public const CLOUD_FINISH_UPLOAD_FAILED = 'CLOUD_FINISH_UPLOAD_FAILED';
	public const CLOUD_UPLOAD_FAILED = 'CLOUD_UPLOAD_FAILED';
	public const CLOUD_UPLOAD_PART_FAILED = 'CLOUD_UPLOAD_PART_FAILED';
	public const PART_WRITE_FAILED = 'PART_WRITE_FAILED';
	public const EMPTY_TOKEN = 'EMPTY_TOKEN';
	public const UNKNOWN_TOKEN = 'UNKNOWN_TOKEN';
	public const INVALID_SIGNATURE = 'INVALID_SIGNATURE';

	public const WRONG_STRATEGY = 'WRONG_STRATEGY';
	public const INVALID_PART_NO = 'INVALID_PART_NO';
	public const INVALID_PART_SIZE = 'INVALID_PART_SIZE';

	public const PRESIGNED_UNSUPPORTED = 'PRESIGNED_UNSUPPORTED';
	public const PRESIGNED_INIT_FAILED = 'PRESIGNED_INIT_FAILED';
	public const PRESIGNED_URL_FAILED = 'PRESIGNED_URL_FAILED';
	public const INVALID_ETAG = 'INVALID_ETAG';
	public const FINALIZATION_NOT_READY = 'FINALIZATION_NOT_READY';
	public const FINALIZATION_IN_PROGRESS = 'FINALIZATION_IN_PROGRESS';

	private static array $systemErrors = [
		self::INVALID_CONTENT_RANGE => 'Content-Range header is invalid',
		self::INVALID_CONTENT_TYPE => 'Content-Type header is required.',
		self::INVALID_CONTENT_LENGTH => 'Content-Length header is required.',
		self::INVALID_CONTENT_NAME => 'X-Upload-Content-Name header is required.',
		self::INVALID_FILENAME => 'Filename is invalid.',
		self::INVALID_RANGE_SIZE => 'Range chunk file size (#rangeChunkSize#) is not equal Content-Length (#contentLength#).',
		self::INVALID_CHUNK_SIZE => 'Chunk file size (#chunkSize#) is not equal Content-Length (#contentLength#).',
		self::INVALID_CHUNK_OFFSET => 'Chunk offset is invalid.',
		self::TOO_BIG_REQUEST => 'The content length is too big to process the request.',
		self::FILE_FIND_FAILED => 'Could not find a file.',
		self::FILE_MOVE_FAILED => 'Could not move file.',
		self::FILE_APPEND_NOT_FOUND => 'File not found.',
		self::CHUNK_NOT_FOUND => 'Could not find chunk file.',
		self::CHUNK_COPY_FAILED => 'Could not copy chunk file.',
		self::CHUNK_TOO_BIG => 'You cannot upload a chunk more than the file size.',
		self::CHUNK_APPEND_NOT_FOUND => 'Could not find chunk.',
		self::CHUNK_APPEND_FAILED => 'Could not put contents to file.',
		self::CLOUD_EMPTY_BUCKET => 'Could not get the cloud bucket.',
		self::CLOUD_INVALID_CHUNK_SIZE => 'Cannot upload file to cloud. The size of the chunk (#chunkSize#) must be more than #minUploadSize#. Check "post_max_size" (#postMaxSize#) and "upload_max_filesize" (#uploadMaxFileSize#) options in php.ini.',
		self::CLOUD_GET_CONTENTS_FAILED => 'Could not get file contents.',
		self::CLOUD_START_UPLOAD_FAILED => 'Could not start cloud upload.',
		self::CLOUD_FINISH_UPLOAD_FAILED => 'Could not finish cloud upload. #detail#',
		self::CLOUD_UPLOAD_FAILED => 'Could not upload file for #fails# times.',
		self::CLOUD_UPLOAD_PART_FAILED => 'Could not upload part #partNo# to cloud.',
		self::PART_WRITE_FAILED => 'Could not write part #partNo# to local file.',
		self::EMPTY_TOKEN => 'Could not append content to file. Have to set token parameter.',
		self::UNKNOWN_TOKEN => 'Could not find file by token.',
		self::INVALID_SIGNATURE => 'Token signature is invalid.',
		self::WRONG_STRATEGY => 'The upload session does not support parallel parts.',
		self::INVALID_PART_NO => 'Part number #partNo# is out of range [1, #partCount#].',
		self::INVALID_PART_SIZE => 'Part #partNo# size #actualSize# does not match expected size #expectedSize#.',
		self::PRESIGNED_UNSUPPORTED => 'The bucket does not support presigned uploads; fall back to the parallel strategy.',
		self::PRESIGNED_INIT_FAILED => 'Could not initialize the presigned multipart session.',
		self::PRESIGNED_URL_FAILED => 'Could not produce a presigned URL for part #partNo#.',
		self::INVALID_ETAG => 'ETag value for part #partNo# is missing or malformed.',
		self::FINALIZATION_NOT_READY => 'Cannot finalize the upload: not all parts have been registered yet.',
		self::FINALIZATION_IN_PROGRESS => 'Another request is finalizing this upload; retry shortly.',
	];

	public function __construct(string $code, ...$args)
	{
		$message = isset($args[0]) && is_string($args[0]) ? $args[0] : null;
		$description = isset($args[1]) && is_string($args[1]) ? $args[1] : null;
		$lastIndex = count($args) - 1;
		$customData = isset($args[$lastIndex]) && is_array($args[$lastIndex]) ? $args[$lastIndex] : [];

		$replacements = [];
		foreach ($customData as $key => $value)
		{
			$replacements["#{$key}#"] = $value;
		}

		if (isset(self::$systemErrors[$code]))
		{
			$message = self::$systemErrors[$code];
			foreach ($replacements as $search => $repl)
			{
				$message = str_replace($search, $repl, $message);
			}

			$this->setSystem(true);
			$description = '';
		}

		if (!is_string($message))
		{
			$message = Loc::getMessage("UPLOADER_{$code}", $replacements);
		}

		if (is_string($message) && mb_strlen($message) > 0 && !is_string($description))
		{
			$description = Loc::getMessage("UPLOADER_{$code}_DESC", $replacements);
		}

		if (!is_string($message) || mb_strlen($message) === 0)
		{
			$message = $code;
		}

		parent::__construct($message, $code, $customData);

		if (is_string($description))
		{
			$this->setDescription($description);
		}
	}

	public function getDescription(): string
	{
		return $this->description;
	}

	public function setDescription(string $description): void
	{
		$this->description = $description;
	}

	public function isSystem(): bool
	{
		return $this->system;
	}

	public function setSystem(bool $system): void
	{
		$this->system = $system;
	}

	public function jsonSerialize()
	{
		return [
			'message' => $this->getMessage(),
			'code' => $this->getCode(),
			'type' => 'file-uploader',
			'system' => $this->isSystem(),
			'description' => $this->getDescription(),
			'customData' => $this->getCustomData(),
		];
	}
}
