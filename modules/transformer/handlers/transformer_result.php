<?php

use Bitrix\Main\Web\Json;
use Bitrix\Transformer\Command;
use Bitrix\Transformer\Command\ResultFile;
use Bitrix\Transformer\Command\ResultFileResolver;
use Bitrix\Transformer\FileUploader;

global $APPLICATION;

if (is_object($APPLICATION))
{
	$APPLICATION->RestartBuffer();
}

if (!\Bitrix\Main\Loader::includeModule('transformer'))
{
	echo Json::encode([
		'error' => [
			'code' => 'MODULE_NOT_INSTALLED',
			'msg' => 'Module transformer isn`t installed'
		]
	]);

	return;
}

$httpRequest = \Bitrix\Main\Context::getCurrent()->getRequest();
$id = $httpRequest->getQuery('id');
if (empty($id))
{
	echo Json::encode([
		'error' => 'Wrong request',
	]);

	return;
}

$logContext = [
	'guid' => $id,
];

if ($httpRequest->getPost('check_result_expected') === 'y')
{
	$command = Command::getByGuid($id);

	echo Json::encode([
		'success' => true,
		'result' => [
			'expected' => $command ? $command->isResultExpected() : false,
		],
	]);

	return;
}

$command = Command::getByGuid($id);
if (!$command)
{
	$message = 'Command '.$id.' not found';
	\Bitrix\Transformer\Log::logger()->error($message, $logContext);
	echo Json::encode([
		'error' => $message,
	]);

	return;
}

$locker = \Bitrix\Main\DI\ServiceLocator::getInstance()->get('transformer.service.command.locker');

if (!$locker->lock($command))
{
	$message = 'Could not acquire lock for command ' . $id;
	\Bitrix\Transformer\Log::logger()->error($message, $logContext);
	echo Json::encode([
		'error' => $message,
	]);

	return;
}

if ($command->getStatus() !== Command::STATUS_SEND)
{
	$message = 'Error: Wrong command status ' . $command->getStatus();
	\Bitrix\Transformer\Log::logger()->error($message, [...$logContext, 'status' => $command->getStatus()]);
	echo Json::encode([
		'error' => $message,
	]);

	$locker->unlock($command);
	return;
}

$resolver = new ResultFileResolver();
$fileSize = $httpRequest->getPost('file_size');

//region get upload info
if ($httpRequest->getPost('upload') === 'where')
{
	$fileId = $httpRequest->getPost('file_id');
	$uploadInfo = FileUploader::getUploadInfo($command, $fileId, (int)$fileSize);
	if ($uploadInfo->isSuccess())
	{
		echo Json::encode($uploadInfo->getData());
	}
	else
	{
		\Bitrix\Transformer\Log::logger()->error('Result file rejected', [
			'guid' => $command->getGuid(),
			'branch' => 'upload_info',
			'format' => is_string($fileId) ? mb_substr($fileId, 0, 255) : get_debug_type($fileId),
			'name' => get_debug_type($fileId),
		]);
		echo Json::encode(['error' => $uploadInfo->getErrorMessages()]);
	}

	$locker->unlock($command);
	return;
}
//endregion

//region upload file part
$fileName = $httpRequest->getPost('file_name');
$uploadedFile = $httpRequest->getFile('file');
if ($fileName && $uploadedFile)
{
	$isLastPart = ($httpRequest->getPost('last_part') === 'y');
	$bucket = intval($httpRequest->getPost('bucket'));
	if (!$resolver->isUploadAllowed($command, $fileName, $bucket))
	{
		echo Json::encode(['error' => 'Wrong upload name']);
		$locker->unlock($command);
		return;
	}

	$logContext = [
		...$logContext,
		'fileName' => $fileName,
		'isLastPart' => $isLastPart,
		'bucket' => $bucket,
		'fileSize' => $fileSize
	];

	if (isset($uploadedFile['error']) && $uploadedFile['error'] > 0)
	{
		$message = 'client web-server error uploading file part';

		\Bitrix\Transformer\Log::logger()->error(
			'Error uploading file part: {errors}',
			[
				...$logContext,
				'errors' => $message,
			],
		);

		echo Json::encode([
			'error' => $message,
		]);

		$locker->unlock($command);
		return;
	}

	if (
		empty($uploadedFile['tmp_name'])
		|| !is_uploaded_file($uploadedFile['tmp_name'])
	)
	{
		$message = 'file not found';

		\Bitrix\Transformer\Log::logger()->error(
			'Error uploading file part: {errors}',
			[
				...$logContext,
				'errors' => $message,
			]
		);

		echo Json::encode([
			'error' => $message,
		]);

		$locker->unlock($command);
		return;
	}

	$ioFile = new \Bitrix\Main\IO\File($uploadedFile['tmp_name']);
	if (!$ioFile->isExists() || !$ioFile->isReadable())
	{
		$message = 'uploaded file part is not readable';

		\Bitrix\Transformer\Log::logger()->error(
			'Error uploading file part: {errors}',
			[
				...$logContext,
				'errors' => $message,
			]
		);

		echo Json::encode([
			'error' => $message,
		]);

		$locker->unlock($command);
		return;
	}

	$filePart = $ioFile->getContents();
	if ($filePart === false)
	{
		$message = 'error reading uploaded file part';

		\Bitrix\Transformer\Log::logger()->error(
			'Error uploading file part: {errors}',
			[
				...$logContext,
				'errors' => $message,
			]
		);

		echo Json::encode([
			'error' => $message,
		]);

		$locker->unlock($command);
		return;
	}

	$saveResult = FileUploader::saveUploadedPart($fileName, $filePart, $fileSize, $isLastPart, $bucket);
	if ($saveResult->isSuccess())
	{
		$saveData = $saveResult->getData();
		$message = 'file saved to '.$saveData['result'];

		echo Json::encode([
			'success' => $message,
		]);
	}
	else
	{
		$message = $saveResult->getErrorMessages();
		\Bitrix\Transformer\Log::logger()->error(
			'Error uploading file part: {errors}',
			[
				...$logContext,
				'errors' => $message,
			]
		);

		echo Json::encode([
			'error' => $message,
		]);
	}

	$locker->unlock($command);
	return;
}
//endregion

$analyticsRegistrar = \Bitrix\Main\DI\ServiceLocator::getInstance()->get('transformer.service.integration.analytics.registrar');

//region error
$error = $httpRequest->getPost('error');
$errorCode = intval($httpRequest->getPost('errorCode'));
if ($error || $errorCode)
{
	if ($errorCode && !$error)
	{
		$error = $errorCode;
	}
	if (!$errorCode)
	{
		$errorCode = Command::ERROR_CONTROLLER_UNKNOWN_ERROR;
	}
	\Bitrix\Transformer\Log::logger()->error(
		'Error on server: {error}',
		[...$logContext, 'error' => $error, 'errorCode' => $errorCode],
	);

	$result = $httpRequest->getPost('result');
	foreach ($resolver->resolveAll($command, $result['files'] ?? [], 'error') as $file)
	{
		$file->delete();
	}

	$command->updateStatus(Command::STATUS_ERROR, $error, $errorCode);

	$analyticsRegistrar->registerCommandFinish($command);

	\Bitrix\Main\Application::getInstance()->addBackgroundJob(static function () use ($command, $error, $locker) {
		try
		{
			$command->callback(['error' => $error]);
		}
		finally
		{
			$locker->unlock($command);
		}
	});

	echo Json::encode([
		'success' => 'error received'
	]);

	return;
}
//endregion

//region finish
$finish = ($httpRequest->getPost('finish') === 'y');
if ($finish)
{
	$files = [];
	$updateStatusResult = $command->updateStatus(Command::STATUS_UPLOAD);
	if (!$updateStatusResult->isSuccess())
	{
		\Bitrix\Transformer\Log::logger()->critical(
			'Cant mark command as uploaded: {errors}',
			[...$logContext, 'errors' => $updateStatusResult->getErrorMessages()],
		);

		echo Json::encode([
			'error' => 'Could not mark command as uploaded',
		]);

		$locker->unlock($command);

		return;
	}

	$analyticsRegistrar->registerCommandFinish($command);

	$result = $httpRequest->getPost('result');
	if (!is_array($result))
	{
		$result = [];
	}
	$files = $resolver->resolveAll($command, $result['files'] ?? [], 'finish');
	$result['files'] = array_map(static fn(ResultFile $file) => $file->getAbsolutePath(), $files);

	\Bitrix\Main\Application::getInstance()->addBackgroundJob(static function () use ($command, $result, $files, $locker) {
		try
		{
			if ($command->callback($result))
			{
				$command->updateStatus(Command::STATUS_SUCCESS);
				$command->push();
			}
			else
			{
				$command->updateStatus(Command::STATUS_ERROR, 'Callback error', Command::ERROR_CALLBACK);
			}
		}
		finally
		{
			foreach ($files as $file)
			{
				$file->delete();
			}

			$locker->unlock($command);
		}
	});

	// controller will never know if there was callback error, but it doesn't care really
	echo Json::encode([
		'success' => 'OK'
	]);

	return;
}
//endregion

$locker->unlock($command);
