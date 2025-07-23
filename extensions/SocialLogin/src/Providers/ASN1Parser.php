<?php

declare(strict_types=1);

namespace Glueful\Extensions\SocialLogin\Providers;

/**
 * ASN1 Parser for DER encoded signatures
 */
class ASN1Parser
{
    /** @var string Binary data to parse */
    private string $data;

    /** @var int Current position in the data */
    private int $pos = 0;

    /**
     * Constructor
     *
     * @param string $data Binary data to parse
     */
    public function __construct(string $data)
    {
        $this->data = $data;
    }

    /**
     * Read an ASN.1 object from the data
     *
     * @return array Object information (type, length, value)
     */
    public function readObject(): array
    {
        $type = ord($this->data[$this->pos++]);
        $length = $this->readLength();
        $value = substr($this->data, $this->pos, $length);
        $this->pos += $length;

        return [
            "type" => $type,
            "length" => $length,
            "value" => $value
        ];
    }

    /**
     * Read ASN.1 length field
     *
     * @return int Length of the following content
     */
    private function readLength(): int
    {
        $length = ord($this->data[$this->pos++]);

        if ($length & 0x80) {
            $lengthBytes = $length & 0x7F;
            $length = 0;

            for ($i = 0; $i < $lengthBytes; $i++) {
                $length = ($length << 8) | ord($this->data[$this->pos++]);
            }
        }

        return $length;
    }
}
