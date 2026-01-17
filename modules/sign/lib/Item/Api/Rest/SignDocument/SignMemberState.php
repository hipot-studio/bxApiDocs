<?php
declare(strict_types=1);

namespace Bitrix\Sign\Item\Api\Rest\SignDocument;

/**
 * Class SignMemberState
 *
 * Represents the state of a sign member (code and readable name).
 */
class SignMemberState
{
    /** @var string|null Machine code of state */
    public ?string $code = null;

    /** @var string|null Human-readable name of state */
    public ?string $name = null;

	public function __construct(?string $code = null, ?string $name = null)
	{
		$this->name = $name;
		$this->code = $code;
	}

    /**
     * Create instance from associative array.
     *
     * @param array $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $self = new self();

        if (array_key_exists('code', $data))
		{
            $self->code = $data['code'] === null ? null : (string)$data['code'];
        }

        if (array_key_exists('name', $data))
		{
            $self->name = $data['name'] === null ? null : (string)$data['name'];
        }

        return $self;
    }

	/**
	 * Convert object to array
	 *
	 * @return array
	 */
	public function toArray(): array
	{
		return [
			'code' => $this->code,
			'name' => $this->name,
		];
	}
}


