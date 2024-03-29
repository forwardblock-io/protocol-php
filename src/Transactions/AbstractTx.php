<?php
declare(strict_types=1);

namespace ForwardBlock\Protocol\Transactions;

use Comely\DataTypes\Buffer\Binary;
use ForwardBlock\Protocol\AbstractProtocolChain;
use ForwardBlock\Protocol\Exception\TxEncodeException;
use ForwardBlock\Protocol\KeyPair\PrivateKey\Signature;
use ForwardBlock\Protocol\Math\UInts;
use ForwardBlock\Protocol\Validator;

/**
 * Class AbstractTx
 * @package ForwardBlock\Protocol\Transactions
 */
abstract class AbstractTx
{
    /** @var AbstractProtocolChain */
    protected AbstractProtocolChain $p;

    /** @var int */
    protected int $version;
    /** @var int */
    protected int $flag;
    /** @var string|null */
    protected ?string $sender = null;
    /** @var int */
    protected int $nonce = 0;
    /** @var string|null */
    protected ?string $recipient = null;
    /** @var string|null */
    protected ?string $memo = null;
    /** @var array */
    protected array $transfers = [];
    /** @var Binary|null */
    protected ?Binary $data = null;
    /** @var array */
    protected array $signs = [];
    /** @var int */
    protected int $fee = 0;
    /** @var int */
    protected int $timeStamp;

    /**
     * AbstractTx constructor.
     * @param AbstractProtocolChain $p
     */
    protected function __construct(AbstractProtocolChain $p)
    {
        $this->p = $p;
    }

    /**
     * @param string|null $chainId
     * @param int|null $forkId
     * @return Binary
     * @throws TxEncodeException
     */
    public function hashPreImage(?string $chainId = null, ?int $forkId = null): Binary
    {
        if ($chainId) {
            if (!Validator::isValidChainId($chainId)) {
                throw new TxEncodeException('Cannot generate hashPreImage; Invalid chain identifier');
            }
        }

        if (!$chainId) {
            $chainId = $this->p->config()->chainId;
        }

        if (is_int($forkId)) {
            if ($forkId < 0 || $forkId > 0xff) {
                throw new TxEncodeException('Cannot generate hashPreImage; Invalid fork id');
            }
        }

        if (!is_int($forkId)) {
            $forkId = $this->p->config()->forkId;
        }

        $raw = $this->serialize(false)->copy()
            ->prepend(hex2bin($chainId))
            ->prepend(UInts::Encode_UInt1LE($forkId));

        return $this->p->hash256($raw)->readOnly(true);
    }

    /**
     * @param bool $includeSignatures
     * @return Binary
     * @throws TxEncodeException
     */
    public function serialize(bool $includeSignatures): Binary
    {
        // Start new Binary Buffer
        $ser = new Binary();

        // Step 1
        $ser->append(UInts::Encode_UInt1LE($this->version));

        // Step 2
        $ser->append(UInts::Encode_UInt2LE($this->flag));

        // Step 3
        if ($this->sender) {
            $ser->append("\1");
            $ser->append($this->sender);
        } else {
            $ser->append("\0");
        }

        // Step 4
        $ser->append(UInts::Encode_UInt4LE($this->nonce));

        // Step 5
        if ($this->recipient) {
            $ser->append("\1");
            $ser->append($this->recipient);
        } else {
            $ser->append("\0");
        }

        // Step 6
        if ($this->memo) {
            $ser->append(UInts::Encode_UInt1LE(strlen($this->memo)));
            $ser->append($this->memo);
        } else {
            $ser->append("\0");
        }

        // Step 7
        $transfersCount = count($this->transfers);
        if ($transfersCount > 10) {
            throw new TxEncodeException('Transaction cannot have more than 10 asset transfers');
        }

        $ser->append(UInts::Encode_UInt1LE($transfersCount));
        if ($transfersCount) {
            if (!$this->recipient) {
                throw new TxEncodeException('Transaction with no recipient cannot have transfers');
            }

            $ser->append($this->serializeTransfers()->raw());
        }

        // Step 8
        if ($this->data && $this->data->sizeInBytes) {
            if ($this->data->sizeInBytes > AbstractProtocolChain::MAX_ARBITRARY_DATA) {
                throw new TxEncodeException(sprintf(
                    'Transaction arbitrary data of size %d bytes exceeds limit of %d bytes',
                    $this->data->sizeInBytes,
                    AbstractProtocolChain::MAX_ARBITRARY_DATA
                ));
            }

            $ser->append(UInts::Encode_UInt2LE($this->data->sizeInBytes));
            $ser->append($this->data->value(0, $this->data->sizeInBytes));
        } else {
            $ser->append("\0\0");
        }

        // Step 9
        if ($includeSignatures) {
            $signsCount = count($this->signs);
            if ($signsCount > 5) {
                throw new TxEncodeException('Transaction cannot have more than 5 signatures');
            }

            $ser->append(UInts::Encode_UInt1LE($signsCount));
            if ($signsCount) {
                /** @var Signature $signed */
                foreach ($this->signs as $signed) {
                    $ser->append(hex2bin($signed->r()->hexits(false)));
                    $ser->append(hex2bin($signed->s()->hexits(false)));
                    $ser->append(UInts::Encode_UInt1LE($signed->v()));
                }
            }
        } else {
            $ser->append("\0");
        }

        // Step 10
        $ser->append(UInts::Encode_UInt8LE($this->fee));

        // Step 11
        $ser->append(UInts::Encode_UInt4LE($this->timeStamp));

        // Set Buffer into ReadOnly state
        $ser->readOnly(true);
        return $ser;
    }

    /**
     * @return Binary
     */
    public function serializeTransfers(): Binary
    {
        $ser = new Binary();
        foreach ($this->transfers as $asset => $amount) {
            $ser->append(UInts::Encode_UInt8LE($amount));
            if ($asset) {
                $ser->append("\1");
                $ser->append(str_pad($asset, 8, "\0", STR_PAD_LEFT));
            } else {
                $ser->append("\0");
            }
        }

        return $ser;
    }
}
