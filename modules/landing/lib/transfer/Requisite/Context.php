<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Requisite;

use Bitrix\Rest\Configuration;

/**
 * DTO for all data
 */
class Context
{
	private string $code;
	private array $data;
	private int $siteId;
	private int $userId;
	private ?string $appCode;
	private Dictionary\Ratio $ratio;
	private Dictionary\AdditionalOption $additional;
	private Configuration\Structure $structure;

	private Dictionary\RunData $runData;

	public function __construct()
	{
		$this->ratio = new Dictionary\Ratio();
		$this->additional = new Dictionary\AdditionalOption();
		$this->runData = new Dictionary\RunData();
	}

	public function setCode(?string $code): self
	{
		if (is_string($code) && $code !== '')
		{
			$this->code = $code;
		}

		return $this;
	}

	public function getCode(): ?string
	{
		return $this->code ?? null;
	}

	public function setData(?array $data): self
	{
		if (is_array($data) && !empty($data))
		{
			$this->data = $data;
		}

		return $this;
	}

	public function getData(): ?array
	{
		return $this->data ?? null;
	}

	/**
	 * Support method for get some Data key
	 * @return string|null - if set - return current language
	 */
	public function getLang(): ?string
	{
		return $this->getData()['LANG'] ?? null;
	}

	/**
	 * Support method for get some Data key
	 * @return array|null
	 */
	public function getTemplates(): ?array
	{
		return $this->getData()['TEMPLATES'] ?? null;
	}

	public function setRatio(Dictionary\Ratio $ratio): self
	{
		$this->ratio = $ratio;

		return $this;
	}

	public function getRatio(): Dictionary\Ratio
	{
		return $this->ratio;
	}

	public function setUserId(?int $userId): self
	{
		if (is_int($userId) && $userId > 0)
		{
			$this->userId = $userId;
		}

		return $this;
	}

	public function getUserId(): ?int
	{
		return $this->userId ?? null;
	}

	public function setAppCode(?string $appCode): self
	{
		if (is_string($appCode) && $appCode !== '')
		{
			$this->appCode = $appCode;
		}

		return $this;
	}

	public function getAppCode(): ?string
	{
		return $this->appCode ?? null;
	}

	public function setAdditionalOptions(Dictionary\AdditionalOption $additional): self
	{
		$this->additional = $additional;

		return $this;
	}

	public function getAdditionalOptions(): Dictionary\AdditionalOption
	{
		return $this->additional;
	}

	public function getRunData(): Dictionary\RunData
	{
		return $this->runData;
	}

	public function setStructureByUserContext(?string $userContext): self
	{
		if (is_string($userContext) && $userContext !== '')
		{
			$this->structure = new Configuration\Structure($userContext);
		}

		return $this;
	}

	public function getStructure(): ?Configuration\Structure
	{
		return $this->structure ?? null;
	}

	public function setSiteId(?int $siteId): self
	{
		if (is_int($siteId) && $siteId > 0)
		{
			$this->siteId = $siteId;
		}

		return $this;
	}

	/**
	 * @return int|null - if set - always >0
	 */
	public function getSiteId(): ?int
	{
		return $this->siteId ?? null;
	}
}
