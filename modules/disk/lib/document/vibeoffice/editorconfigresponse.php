<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

use Bitrix\Main\Type\Contract\Jsonable;
use Bitrix\Main\Web\Json;

/**
 * Typed carrier of the proxied platform open-config (DTO-EDITORCONFIG).
 *
 * The vibeoffice platform — not Bitrix — builds and signs the editor `config`
 * (its `token` is signed with the DS browser-secret). The backend opens a
 * session server-to-server and proxies the whole open-response into the browser
 * verbatim: the frontend passes it to `@vibeoffice/helper createEditor({ openConfig })`
 * WITHOUT rebuilding `config` (integration-guide §4: "do not rebuild config").
 *
 * This object is the boundary between the controller and the shell-component:
 * it keeps the open-response as-is and adds the container binding the shell needs.
 */
final class EditorConfigResponse implements Jsonable
{
	/** @var array raw open-response from POST /v1/documents/{docKey}/open, kept verbatim */
	private array $openConfig;
	private string $documentSessionHash;

	public function __construct(array $openConfig, string $documentSessionHash)
	{
		$this->openConfig = $openConfig;
		$this->documentSessionHash = $documentSessionHash;
	}

	/**
	 * The platform open-response (DTO-EDITORCONFIG), proxied to the browser
	 * unchanged. The frontend forwards it to createEditor({ openConfig }).
	 */
	public function getOpenConfig(): array
	{
		return $this->openConfig;
	}

	public function getDocumentSessionHash(): string
	{
		return $this->documentSessionHash;
	}

	/**
	 * Shape consumed by the shell-component: the session hash plus the verbatim
	 * open-config the helper expects under `openConfig`.
	 */
	public function toArray(): array
	{
		return [
			'documentSessionHash' => $this->documentSessionHash,
			'openConfig' => $this->openConfig,
		];
	}

	public function toJson($options = 0): string
	{
		return Json::encode($this->toArray(), $options);
	}
}
