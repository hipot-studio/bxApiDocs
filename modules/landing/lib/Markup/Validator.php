<?php

namespace Bitrix\Landing\Markup;

use Bitrix\Landing\Copilot\Generation\Log;
use Bitrix\Landing\Markup\Form\FormDeclaration;
use Bitrix\Landing\Markup\Reference\ReferenceMatcher;
use Bitrix\Landing\Markup\Repair\RepairableCodes;
use Bitrix\Landing\Markup\Security\ForbiddenCodeRules;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

Loc::loadMessages(__FILE__);

/**
 * Validation of an incoming markup package: structure, limits and the file type whitelist.
 *
 * The single place for all package limits (ADR: one place, intake service constants).
 * Executable-as-document types are rejected: package files are served from the portal
 * domain (html/svg would run in the portal origin on a direct URL hit).
 *
 * Hard defects (limits, paths, types, image and form declarations, code of the block outcome class)
 * stop the validation before the repairable ones are looked for; repairable defects are
 * all reported at once - the repair step gets the whole list in a single iteration.
 */
class Validator
{
	public const LIMIT_PACKAGE_SIZE = 15728640; // 15 MB
	public const LIMIT_FILE_SIZE = 5242880; // 5 MB
	public const LIMIT_FILES_COUNT = 50;
	public const LIMIT_ENTRY_SIZE = 1048576; // 1 MB
	public const LIMIT_VIDEO_COUNT = 1;

	public const TOKEN_REGEX = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

	private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
	private const TEXT_EXTENSIONS = ['css', 'js'];
	private const VIDEO_EXTENSIONS = ['mp4'];

	private Log $log;

	/** @var array<int, string>|null */
	private ?array $portalHosts;

	/**
	 * @param array<int, string>|null $portalHosts hosts of the portal; null - the hosts of the current hit
	 */
	public function __construct(?Log $log = null, ?array $portalHosts = null)
	{
		$this->log = $log ?? new Log(null);
		$this->portalHosts = $portalHosts;
	}

	/**
	 * Path rules of the contract, shared by the package validation and the per-file
	 * intake of the upload buffer. Pure check: usable outside of a full kernel.
	 */
	public function isValidPath(string $path): bool
	{
		return (bool)preg_match('#^[a-zA-Z0-9_\-./]{1,200}$#', $path) && !str_contains($path, '..');
	}

	/**
	 * Checks of a single file before it is written anywhere: the path rules, the single file size
	 * limit and the type by content. Html is let through: whether the file is the entry, the only
	 * place html may take, is known at submission.
	 */
	public function validateFileIntake(string $path, string $content): ?Error
	{
		if (!$this->isValidPath($path))
		{
			return new Error('The file path violates the package path rules.', 'MARKUP_BAD_PATH');
		}

		if (strlen($content) > self::LIMIT_FILE_SIZE)
		{
			return new Error('The file exceeds the single file size limit.', 'MARKUP_FILE_TOO_BIG');
		}

		$extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));
		if (!in_array($extension, ['html', 'htm'], true) && $this->resolveType($path, $content) === null)
		{
			return new Error('The file type is not allowed by the package contract.', 'MARKUP_BAD_TYPE');
		}

		return null;
	}

	/**
	 * Package limits a file must fit into on its arrival in the upload buffer: the entry size
	 * (html may only be the entry), the number of files, the package total and a single video.
	 * A repeated path replaces its buffered file. Codes are the ones of the package validation.
	 *
	 * @param array<int, array{path: string, size: int}> $bufferFiles Files already in the buffer.
	 */
	public function validateBufferFill(array $bufferFiles, string $path, int $size): ?Error
	{
		$extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));
		if (in_array($extension, ['html', 'htm'], true) && $size > self::LIMIT_ENTRY_SIZE)
		{
			return new Error(
				'The entry document exceeds the entry size limit.',
				'MARKUP_ENTRY_TOO_BIG'
			);
		}

		$count = 1;
		$totalSize = $size;
		$videoCount = $this->resolveTypeByPath($path) === 'video' ? 1 : 0;
		foreach ($bufferFiles as $file)
		{
			$filePath = (string)($file['path'] ?? '');
			if ($filePath === $path)
			{
				continue;
			}
			$count++;
			$totalSize += (int)($file['size'] ?? 0);
			if ($this->resolveTypeByPath($filePath) === 'video')
			{
				$videoCount++;
			}
		}

		if ($count > self::LIMIT_FILES_COUNT)
		{
			return new Error(
				'The file does not fit into the package files count limit.',
				'MARKUP_TOO_MANY_FILES'
			);
		}
		if ($totalSize > self::LIMIT_PACKAGE_SIZE)
		{
			return new Error(
				'The file does not fit into the package size limit.',
				'MARKUP_PACKAGE_TOO_BIG'
			);
		}
		if ($videoCount > self::LIMIT_VIDEO_COUNT)
		{
			return new Error(
				'The package may hold only one video file.',
				'MARKUP_TOO_MANY_VIDEO'
			);
		}

		return null;
	}

	public function validate(Package $package): Result
	{
		$result = new Result();

		$entry = $package->getEntry();
		if (!$entry || $entry['content'] === '')
		{
			return $result->addError(new Error(
				Loc::getMessage('LANDING_MARKUP_VALIDATOR_NO_ENTRY'),
				'MARKUP_NO_ENTRY'
			));
		}
		if (!$this->isValidPath($entry['path']))
		{
			return $result->addError(new Error(
				Loc::getMessage('LANDING_MARKUP_VALIDATOR_BAD_PATH', ['#PATH#' => $entry['path']]),
				'MARKUP_BAD_PATH'
			));
		}
		if (strlen($entry['content']) > self::LIMIT_ENTRY_SIZE)
		{
			return $result->addError(new Error(
				Loc::getMessage('LANDING_MARKUP_VALIDATOR_ENTRY_TOO_BIG'),
				'MARKUP_ENTRY_TOO_BIG'
			));
		}

		if ($package->getFilesCount() > self::LIMIT_FILES_COUNT)
		{
			return $result->addError(new Error(
				Loc::getMessage('LANDING_MARKUP_VALIDATOR_TOO_MANY_FILES'),
				'MARKUP_TOO_MANY_FILES'
			));
		}
		if ($package->getTotalSize() > self::LIMIT_PACKAGE_SIZE)
		{
			return $result->addError(new Error(
				Loc::getMessage('LANDING_MARKUP_VALIDATOR_PACKAGE_TOO_BIG'),
				'MARKUP_PACKAGE_TOO_BIG'
			));
		}

		$videoCount = 0;
		$seenPaths = [];
		foreach ($package->getAssets() as $asset)
		{
			$path = trim($asset['path']);
			if ($path === '' || isset($seenPaths[$path]) || !$this->isValidPath($path))
			{
				return $result->addError(new Error(
					Loc::getMessage('LANDING_MARKUP_VALIDATOR_BAD_PATH', ['#PATH#' => $path]),
					'MARKUP_BAD_PATH'
				));
			}
			$seenPaths[$path] = true;

			if (strlen($asset['content']) > self::LIMIT_FILE_SIZE)
			{
				return $result->addError(new Error(
					Loc::getMessage('LANDING_MARKUP_VALIDATOR_FILE_TOO_BIG', ['#PATH#' => $path]),
					'MARKUP_FILE_TOO_BIG'
				));
			}

			$type = $this->resolveType($path, $asset['content']);
			if ($type === null)
			{
				return $result->addError(new Error(
					Loc::getMessage('LANDING_MARKUP_VALIDATOR_BAD_TYPE', ['#PATH#' => $path]),
					'MARKUP_BAD_TYPE'
				));
			}
			if ($type === 'video')
			{
				$videoCount++;
				if ($videoCount > self::LIMIT_VIDEO_COUNT)
				{
					return $result->addError(new Error(
						Loc::getMessage('LANDING_MARKUP_VALIDATOR_TOO_MANY_VIDEO'),
						'MARKUP_TOO_MANY_VIDEO'
					));
				}
			}
		}

		foreach ($package->getImageDeclaration() as $image)
		{
			$error = $this->validateImageDeclaration($image);
			if ($error !== null)
			{
				return $result->addError($error);
			}
		}

		$error = $this->validateFormDeclaration($package->getFormDeclaration());
		if ($error !== null)
		{
			return $result->addError($error);
		}

		$codeErrors = $this->analyzeCode($package);
		if ($codeErrors[ForbiddenCodeRules::OUTCOME_BLOCK])
		{
			foreach ($codeErrors[ForbiddenCodeRules::OUTCOME_BLOCK] as $error)
			{
				$result->addError($error);
			}

			return $result;
		}

		foreach ($this->detectRepairableDefects($package) as $error)
		{
			$result->addError($error);
		}
		foreach ($codeErrors[ForbiddenCodeRules::OUTCOME_REPAIR] as $error)
		{
			$result->addError($error);
		}

		return $result;
	}

	/**
	 * Runs the entry and the text assets through the static rules and lays the hits out by
	 * outcome class: block and repair become errors, allow only reaches the generation log.
	 * Binary assets are not scanned.
	 * @return array{block: array<int, Error>, repair: array<int, Error>}
	 */
	private function analyzeCode(Package $package): array
	{
		$errors = [
			ForbiddenCodeRules::OUTCOME_BLOCK => [],
			ForbiddenCodeRules::OUTCOME_REPAIR => [],
		];

		$portalHosts = $this->portalHosts ??= $this->detectPortalHosts();
		foreach ($this->collectTexts($package) as $path => $content)
		{
			$target = $this->resolveCodeTarget($package, $path);
			foreach (ForbiddenCodeRules::matchAll($content, $target, $portalHosts) as $hit)
			{
				if ($hit['outcome'] === ForbiddenCodeRules::OUTCOME_ALLOW)
				{
					$this->log->add(sprintf(
						'Markup package: rule %s allowed in %s, line %d',
						$hit['id'],
						$path,
						$hit['line']
					));
					continue;
				}

				$isBlock = $hit['outcome'] === ForbiddenCodeRules::OUTCOME_BLOCK;
				$errors[$hit['outcome']][] = new Error(
					Loc::getMessage(
						$isBlock
							? 'LANDING_MARKUP_VALIDATOR_FORBIDDEN_API'
							: 'LANDING_MARKUP_VALIDATOR_REPAIRABLE_CODE',
						['#PATH#' => $path, '#RULE#' => $hit['id']]
					),
					$isBlock ? 'MARKUP_FORBIDDEN_API' : RepairableCodes::REPAIRABLE_CODE,
					['path' => $path, 'line' => $hit['line'], 'rule' => $hit['id']]
				);
			}
		}

		return $errors;
	}

	/**
	 * A hit of an agent has no http host: the configured names of the portal stand for it.
	 * @return array<int, string>
	 */
	private function detectPortalHosts(): array
	{
		$hosts = [
			(string)Context::getCurrent()?->getServer()->getHttpHost(),
			defined('SITE_SERVER_NAME') ? (string)SITE_SERVER_NAME : '',
			(string)Option::get('main', 'server_name', ''),
		];

		return array_values(array_unique(array_filter($hosts)));
	}

	private function resolveCodeTarget(Package $package, string $path): string
	{
		if ($path === (string)$package->getEntry()['path'])
		{
			return ForbiddenCodeRules::TARGET_HTML;
		}

		return mb_strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'css'
			? ForbiddenCodeRules::TARGET_CSS
			: ForbiddenCodeRules::TARGET_JS;
	}

	/**
	 * Defects the repair step can fix: a file nothing refers to and an image token
	 * the declaration does not know.
	 * @return array<int, Error>
	 */
	private function detectRepairableDefects(Package $package): array
	{
		$matcher = new ReferenceMatcher();
		$textByPath = $this->collectTexts($package);
		$errors = [];

		foreach ($package->getAssets() as $asset)
		{
			$path = (string)$asset['path'];
			$others = $textByPath;
			unset($others[$path]);
			if (!$matcher->isPathReferenced($path, $others))
			{
				$errors[] = new Error(
					Loc::getMessage('LANDING_MARKUP_VALIDATOR_UNREFERENCED_FILE', ['#PATH#' => $path]),
					RepairableCodes::UNREFERENCED_FILE,
					['path' => $path, 'line' => null]
				);
			}
		}

		$declaredTokens = array_column($package->getImageDeclaration(), 'token');
		foreach ($textByPath as $path => $text)
		{
			foreach ($matcher->collectTokens([$text]) as $token)
			{
				if (in_array($token, $declaredTokens, true))
				{
					continue;
				}
				$errors[] = new Error(
					Loc::getMessage(
						'LANDING_MARKUP_VALIDATOR_UNDECLARED_TOKEN',
						['#PATH#' => $path, '#TOKEN#' => $token]
					),
					RepairableCodes::UNDECLARED_TOKEN,
					['path' => $path, 'line' => null]
				);
			}
		}

		return $errors;
	}

	/**
	 * Texts the references live in: the entry document and the text assets.
	 * @return array<string, string> content by package path
	 */
	private function collectTexts(Package $package): array
	{
		$entry = $package->getEntry();
		$texts = [(string)$entry['path'] => (string)$entry['content']];

		foreach ($package->getAssets() as $asset)
		{
			if ($this->resolveTypeByPath((string)$asset['path']) === 'text')
			{
				$texts[(string)$asset['path']] = (string)$asset['content'];
			}
		}

		return $texts;
	}

	/**
	 * Resolves the asset type by extension and verifies the actual content.
	 * @return string|null image|text|video, null for a rejected file.
	 */
	public function resolveType(string $path, string $content): ?string
	{
		$extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));

		if (in_array($extension, self::IMAGE_EXTENSIONS, true))
		{
			return $this->isImageContent($content) ? 'image' : null;
		}
		if (in_array($extension, self::TEXT_EXTENSIONS, true))
		{
			return !str_contains($content, "\0") ? 'text' : null;
		}
		if (in_array($extension, self::VIDEO_EXTENSIONS, true))
		{
			// mp4 container starts with an ftyp box within the first bytes
			return strpos(substr($content, 0, 32), 'ftyp') !== false ? 'video' : null;
		}

		return null;
	}

	/**
	 * The type an extension belongs to, without a content check: enough to decide
	 * whether a stored file travels by content (text) or by identifier (binary).
	 * @return string|null image|text|video, null for a foreign extension.
	 */
	public function resolveTypeByPath(string $path): ?string
	{
		$extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));

		return match (true)
		{
			in_array($extension, self::IMAGE_EXTENSIONS, true) => 'image',
			in_array($extension, self::TEXT_EXTENSIONS, true) => 'text',
			in_array($extension, self::VIDEO_EXTENSIONS, true) => 'video',
			default => null,
		};
	}

	private function isImageContent(string $content): bool
	{
		$info = @getimagesizefromstring($content);

		return is_array($info) && in_array(
			$info[2],
			[IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP, IMAGETYPE_GIF],
			true
		);
	}

	/**
	 * The structure and the limits of the form declaration. A marker with no declaration and
	 * a declaration with no marker are not defects: the creation step answers them with warnings.
	 * @param array<int, array> $forms
	 */
	private function validateFormDeclaration(array $forms): ?Error
	{
		if (count($forms) > FormDeclaration::LIMIT_FORMS)
		{
			return new Error(
				Loc::getMessage('LANDING_MARKUP_VALIDATOR_TOO_MANY_FORMS'),
				'MARKUP_BAD_FORM'
			);
		}

		$seenTokens = [];
		foreach ($forms as $form)
		{
			$token = is_string($form['token'] ?? null) ? $form['token'] : '';
			if (FormDeclaration::normalize($form) === null)
			{
				return new Error(
					Loc::getMessage('LANDING_MARKUP_VALIDATOR_BAD_FORM', ['#TOKEN#' => $token]),
					'MARKUP_BAD_FORM'
				);
			}
			if (isset($seenTokens[$token]))
			{
				return new Error(
					Loc::getMessage('LANDING_MARKUP_VALIDATOR_REPEATED_FORM', ['#TOKEN#' => $token]),
					'MARKUP_BAD_FORM'
				);
			}
			$seenTokens[$token] = true;

			if (count((array)($form['fields'] ?? [])) > FormDeclaration::LIMIT_FIELDS)
			{
				return new Error(
					Loc::getMessage('LANDING_MARKUP_VALIDATOR_TOO_MANY_FORM_FIELDS', ['#TOKEN#' => $token]),
					'MARKUP_BAD_FORM'
				);
			}
		}

		return null;
	}

	private function validateImageDeclaration(array $image): ?Error
	{
		$token = (string)($image['token'] ?? '');
		if (!preg_match(self::TOKEN_REGEX, $token))
		{
			return new Error(
				Loc::getMessage('LANDING_MARKUP_VALIDATOR_BAD_TOKEN', ['#TOKEN#' => $token]),
				'MARKUP_BAD_TOKEN'
			);
		}

		$kind = (string)($image['kind'] ?? '');
		if (!in_array($kind, [Package::IMAGE_KIND_GENERATE, Package::IMAGE_KIND_EXTERNAL], true))
		{
			return new Error(
				Loc::getMessage('LANDING_MARKUP_VALIDATOR_BAD_KIND', ['#TOKEN#' => $token]),
				'MARKUP_BAD_KIND'
			);
		}

		if ($kind === Package::IMAGE_KIND_EXTERNAL)
		{
			$url = (string)($image['url'] ?? '');
			if (!preg_match('#^https://#i', $url))
			{
				return new Error(
					Loc::getMessage('LANDING_MARKUP_VALIDATOR_BAD_URL', ['#TOKEN#' => $token]),
					'MARKUP_BAD_URL'
				);
			}
		}

		return null;
	}
}
