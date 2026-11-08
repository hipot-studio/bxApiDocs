<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller;

use Bitrix\Disk\File;
use Bitrix\Mail\FileUploader\SignatureImageUploaderController;
use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Internal\Service\Signature\Image\SignatureImageResourceProvider;
use Bitrix\Mail\Internal\Service\Signature\Image\SignatureImageService;
use Bitrix\Mail\Internal\Service\Signature\Image\SignatureImageValidator;
use Bitrix\Main\Application;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Loader;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\UI\FileUploader\Uploader;

final class SignatureImage extends Base
{
	public const ERROR_NOT_FOUND = 'SIGNATURE_IMAGE_NOT_FOUND';
	public const ERROR_DISK_UNAVAILABLE = 'SIGNATURE_IMAGE_DISK_UNAVAILABLE';
	public const ERROR_RESOLVE_FAILED = 'SIGNATURE_IMAGE_RESOLVE_FAILED';
	public const ERROR_FEATURE_DISABLED = 'SIGNATURE_IMAGE_FEATURE_DISABLED';

	private const VIEW_PATH = '/bitrix/services/main/ajax.php?action=mail.api.signatureimage.view&id=';
	private const CACHE_CONTROL = 'private, max-age=60, no-transform';
	private const CONTENT_TYPES = [
		'image/jpeg' => true,
		'image/png' => true,
		'image/gif' => true,
	];

	public function configureActions(): array
	{
		return [
			'upload' => [
				'+prefilters' => [new HttpMethod([HttpMethod::METHOD_POST])],
			],
			'copy' => [
				'+prefilters' => [new HttpMethod([HttpMethod::METHOD_POST])],
			],
			'view' => [
				'-prefilters' => [Csrf::class],
				'+prefilters' => [
					new HttpMethod([HttpMethod::METHOD_GET]),
					new CloseSession(),
				],
			],
		];
	}

	public function uploadAction(string $uploadId): array|false
	{
		if (!Feature::isSignatureImagesAvailable())
		{
			return $this->fail(self::ERROR_FEATURE_DISABLED, 404);
		}

		$file = $this->resolveUploadedFile($uploadId);
		if ($file === null)
		{
			return $this->fail(SignatureImageValidator::ERROR_UPLOAD_INVALID, 422);
		}

		return $this->createImage($file);
	}

	public function copyAction(int $objectId): array|false
	{
		if (!Feature::isSignatureImagesAvailable())
		{
			return $this->fail(self::ERROR_FEATURE_DISABLED, 404);
		}

		if (!Loader::includeModule('disk'))
		{
			return $this->fail(self::ERROR_DISK_UNAVAILABLE, 503);
		}

		$file = $objectId > 0 ? File::loadById($objectId, ['STORAGE']) : null;
		if (
			!$file instanceof File
			|| !$file->canRead($file->getStorage()->getSecurityContext($this->getUserId()))
		)
		{
			return $this->fail(self::ERROR_NOT_FOUND, 404);
		}

		$source = $file->getRealObject();
		if (!$source instanceof File)
		{
			return $this->fail(SignatureImageService::ERROR_COPY_FAILED, 500);
		}

		// The size of the Disk row is checked before makeFileArray: for a file kept in the cloud that call
		// downloads the whole content into temporary storage, and refusing an oversized picture afterwards
		// would spend the traffic for nothing. The content itself is measured again by the validator.
		if ((int)$source->getSize() > SignatureImageValidator::MAX_FILE_SIZE)
		{
			return $this->fail(SignatureImageValidator::ERROR_TOO_LARGE, 422);
		}

		$fileArray = \CFile::makeFileArray((int)$source->getFileId());
		if (!is_array($fileArray))
		{
			return $this->fail(SignatureImageService::ERROR_COPY_FAILED, 500);
		}

		return $this->createImage($fileArray);
	}

	public function viewAction(string $id): HttpResponse|false
	{
		if (!$this->isUuid($id))
		{
			return $this->notFound();
		}

		try
		{
			$image = (new SignatureImageResourceProvider())->getAccessibleByUuid($id, $this->getUserId());
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return $this->notFound();
		}
		if ($image === null || !isset(self::CONTENT_TYPES[(string)$image['CONTENT_TYPE']]))
		{
			return $this->notFound();
		}

		$file = \CFile::makeFileArray((int)$image['FILE_ID']);
		$path = is_array($file) ? ($file['tmp_name'] ?? null) : null;
		if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path))
		{
			return $this->notFound();
		}

		$content = file_get_contents($path);
		if (!is_string($content) || strlen($content) !== (int)$image['FILE_SIZE'])
		{
			return $this->notFound();
		}

		$response = new HttpResponse();
		$response->setContent($content);
		$response->getHeaders()->set('Content-Type', (string)$image['CONTENT_TYPE']);
		$response->getHeaders()->set('Content-Length', (string)strlen($content));
		$response->getHeaders()->set('X-Content-Type-Options', 'nosniff');
		$response->getHeaders()->set('Cache-Control', self::CACHE_CONTROL);

		return $response;
	}

	private function resolveUploadedFile(string $uploadId): ?array
	{
		if (
			$uploadId === ''
			|| !Loader::includeModule('ui')
		)
		{
			return null;
		}

		try
		{
			$pendingFiles = (new Uploader(new SignatureImageUploaderController()))->getPendingFiles([$uploadId]);
		}
		catch (BadSignatureException)
		{
			return null;
		}
		foreach ($pendingFiles as $pendingFile)
		{
			$fileId = (int)$pendingFile->getFileId();
			$file = $fileId > 0 ? \CFile::makeFileArray($fileId) : false;

			return $pendingFile->isValid() && is_array($file) ? $file : null;
		}

		return null;
	}

	private function createImage(array $file): array|false
	{
		$result = (new SignatureImageService())->create($this->getUserId(), $file);
		if (!$result->isSuccess())
		{
			$error = $result->getError();
			$code = is_string($error?->getCode()) ? $error->getCode() : SignatureImageService::ERROR_STORAGE_FAILED;
			$status = in_array(
				$code,
				[
					SignatureImageService::ERROR_COPY_FAILED,
					SignatureImageService::ERROR_STORAGE_FAILED,
				],
				true,
			) ? 500 : 422;

			return $this->fail($code, $status);
		}

		$data = $result->getData();
		$uuid = (string)$data['uuid'];

		return [
			'image' => [
				'id' => $uuid,
				'src' => self::VIEW_PATH . rawurlencode($uuid),
				'contentType' => (string)$data['contentType'],
				'width' => (int)$data['width'],
				'height' => (int)$data['height'],
				'size' => (int)$data['size'],
				'animated' => (bool)$data['animated'],
				'variants' => (array)$data['variants'],
			],
		];
	}

	private function notFound(): false
	{
		return $this->fail(self::ERROR_NOT_FOUND, 404);
	}

	private function fail(string $code, int $status): false
	{
		Context::getCurrent()->getResponse()->setStatus($status);
		$this->addError(new Error($code, $code));

		return false;
	}

	private function getUserId(): int
	{
		return (int)(CurrentUser::get()->getId() ?? 0);
	}

	private function isUuid(string $id): bool
	{
		return preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
			$id,
		) === 1;
	}
}
