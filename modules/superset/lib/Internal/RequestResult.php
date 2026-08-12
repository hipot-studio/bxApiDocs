<?php

namespace Bitrix\Superset\Internal;

use Bitrix\Main\Result;
use Bitrix\Main\Web\HttpHeaders;

class RequestResult extends Result
{
	protected $httpStatus;
	protected $answer;
	protected $time;
	protected $headers;

	public function __construct(int $httpStatus, HttpHeaders $headers, string $answer, float $time)
	{
		$this->httpStatus = $httpStatus;
		$this->headers = $headers;
		$this->answer = $answer;
		$this->time = $time;

		parent::__construct();
	}

	public function getHttpStatus(): int
	{
		return $this->httpStatus;
	}

	public function getAnswer(): string
	{
		return $this->answer;
	}

	public function getTime(): float
	{
		return $this->time;
	}

	public function getHeaders(): HttpHeaders
	{
		return $this->headers;
	}
}