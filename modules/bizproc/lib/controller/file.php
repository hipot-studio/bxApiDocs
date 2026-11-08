<?php

namespace Bitrix\Bizproc\Controller;

use Bitrix\Main;
use Bitrix\Main\Engine\Response\BFile;
use Bitrix\Main\Error;

class File extends Base
{
	private const TOKEN_SALT = 'bizproc.file.show';

	public function configureActions(): array
	{
		$configureActions = parent::configureActions();
		$configureActions['show'] = [
			'-prefilters' => [
				Main\Engine\ActionFilter\Csrf::class,
				Main\Engine\ActionFilter\Authentication::class,
			],
			'+prefilters' => [
				new Main\Engine\ActionFilter\CloseSession(),
			]
		];

		return $configureActions;
	}

	public function showAction($token)
	{
		[$fileId] = self::extractToken($token);
		$file = null;

		if ($fileId)
		{
			$file = \CFile::getFileArray($fileId);
		}

		if (!$file)
		{
			$this->addError(new Error('No file'));
			return null;
		}

		return BFile::createByFileData($file);
	}

	/**
	 * @param bool $absolute a link travelling out of the page - into a letter, a notification, the text of
	 *   a task - carries the host of the `main/server_name` setting. A link consumed inside the page asks
	 *   for false: that setting may name another host, and a relative link keeps the one the page was
	 *   opened by.
	 */
	public static function getPublicLink(int $fileId, bool $absolute = true): string
	{
		return Main\Engine\UrlManager::getInstance()->create(
			'bizproc.file.show',
			[
				'token' => static::generateToken($fileId),
			],
			$absolute
		)->getUri();
	}

	private static function generateToken(int $fileId)
	{
		$signer = new Main\Security\Sign\Signer;
		return $signer->sign((string) $fileId, self::TOKEN_SALT);
	}

	private static function extractToken(string $token): array
	{
		$signer = new Main\Security\Sign\Signer;

		try
		{
			$unsigned = $signer->unsign($token, self::TOKEN_SALT);
			$result = [$unsigned];
		}
		catch (\Exception $e)
		{
			$result = [null];
		}

		return $result;
	}
}