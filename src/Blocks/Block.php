<?php
declare(strict_types=1);

namespace ForwardBlock\Protocol\Blocks;

use Comely\DataTypes\Buffer\Base16;
use Comely\DataTypes\Buffer\Binary;
use ForwardBlock\Protocol\AbstractProtocolChain;
use ForwardBlock\Protocol\Exception\BlockDecodeException;
use ForwardBlock\Protocol\KeyPair\PrivateKey\Signature;
use ForwardBlock\Protocol\Math\UInts;
use ForwardBlock\Protocol\Transactions\AbstractPreparedTx;
use ForwardBlock\Protocol\Transactions\Transaction;
use ForwardBlock\Protocol\Validator;

/**
 * Class Block
 * @package ForwardBlock\Protocol\Blocks
 */
class Block extends AbstractBlock
{
    /** @var Binary */
    private Binary $hash;
    /** @var Binary */
    private Binary $raw;
    /** @var array */
    private array $rawTxs = [];
    /** @var array */
    private array $rawTxReceipts = [];

    /**
     * @param AbstractProtocolChain $p
     * @param Binary $encoded
     * @param int $heightContext
     * @return static
     * @throws BlockDecodeException
     */
    public static function Decode(AbstractProtocolChain $p, Binary $encoded, int $heightContext): self
    {
        return new static($p, $encoded, $heightContext);
    }

    /**
     * Block constructor.
     * @param AbstractProtocolChain $p
     * @param Binary $bytes
     * @param int $heightContext
     * @throws BlockDecodeException
     */
    protected function __construct(AbstractProtocolChain $p, Binary $bytes, int $heightContext)
    {
        parent::__construct($p);
        $this->raw = $bytes->readOnly(true);

        if ($bytes->sizeInBytes > AbstractProtocolChain::MAX_BLOCK_SIZE) {
            throw new BlockDecodeException(sprintf(
                'Encoded block of %d bytes exceeds limit of %d bytes',
                $bytes->sizeInBytes,
                AbstractProtocolChain::MAX_BLOCK_SIZE
            ));
        }

        //  Get Block Hash
        $this->hash = $this->p->hash256($bytes)->readOnly(true);

        // Byte Reading
        $read = $bytes->read();
        $read->throwUnderflowEx();

        try {
            // Step 1
            $this->version = UInts::Decode_UInt1LE($read->first(1));
            switch ($this->version) {
                case 1:
                    $this->decodeBlockV1($read, $heightContext);
                    break;
                default:
                    throw new BlockDecodeException(sprintf('Unsupported block version %d', $this->version));
            }
        } catch (BlockDecodeException $e) {
            throw $e;
        } catch (\Throwable $t) {
            throw BlockDecodeException::Incomplete($this, sprintf('[%s][%s]: %s', get_class($t), $t->getCode(), $t->getMessage()));
        }
    }

    /**
     * @param Binary\ByteReader $read
     * @param int $heightContext
     * @throws BlockDecodeException
     */
    private function decodeBlockV1(Binary\ByteReader $read, int $heightContext): void
    {
        // Step 2
        $timeStamp = UInts::Decode_UInt4LE($read->next(4));
        if (!Validator::isValidEpoch($timeStamp)) {
            throw BlockDecodeException::Incomplete($this, 'Invalid timeStamp');
        }

        $this->timeStamp = $timeStamp;

        // Step 3
        $this->prevBlockId = strval($read->next(32));

        // Step 4
        $this->txCount = UInts::Decode_UInt2LE($read->next(2));

        // Step 5
        $this->totalIn = UInts::Decode_UInt8LE($read->next(8));

        // Step 6
        $this->totalOut = UInts::Decode_UInt8LE($read->next(8));

        // Step 7
        $this->totalFee = UInts::Decode_UInt8LE($read->next(8));

        // Step 8
        $this->forger = $read->next(20);

        // Step 9
        $signs = UInts::Decode_UInt1LE($read->next(1));
        if ($signs > 5) {
            throw BlockDecodeException::Incomplete($this, 'Blocks cannot have more than 5 signatures');
        }

        if ($signs > 0) {
            for ($i = 1; $i <= $signs; $i++) { // Step 9.1
                try {
                    $signR = $read->next(32);
                    $signS = $read->next(32);
                    $signV = UInts::Decode_UInt1LE($read->next(1));
                    $sign = new Signature(new Base16(bin2hex($signR)), new Base16(bin2hex($signS)), $signV);
                } catch (\Exception $e) {
                    throw BlockDecodeException::Incomplete($this,
                        sprintf('Error with signature %d; (%s) %s', $i, get_class($e), $e->getMessage()));
                }

                $this->signs[] = $sign;
            }
        }

        // Step 10
        $this->reward = UInts::Decode_UInt8LE($read->next(8));

        // Step 11
        $this->merkleTx = $read->next(32);

        // Step 12
        $this->merkleTxReceipts = $read->next(32);

        // Verify Step 11 and 12
        if (!$this->txCount) {
            $nullMerkleTree = str_repeat("\0", 32);
            if ($this->merkleTx !== $nullMerkleTree) {
                throw BlockDecodeException::Incomplete($this, 'Block merkle tx root must be all NULL bytes with txCount 0');
            }

            if ($this->merkleTxReceipts !== $nullMerkleTree) {
                throw BlockDecodeException::Incomplete($this, 'Block merkle tx receipts root must be all NULL bytes with txCount 0');
            }
        }

        // Step 13
        $this->bodySize = UInts::Decode_UInt4LE($read->next(4));

        // Step 14
        if ($read->next(1) !== "\0") {
            throw BlockDecodeException::Incomplete($this, 'Invalid block headers separator');
        }

        // Step 15
        if ($this->txCount > 0) {
            for ($i = 0; $i < $this->txCount; $i++) {
                // Step 15.1
                $serTxLen = UInts::Decode_UInt2LE($read->next(2));

                // Step 15.2
                $serTx = $read->next($serTxLen);
                try {
                    $this->rawTxs[] = $serTx;
                    $blockTx = Transaction::DecodeAs($this->p, new Binary($serTx));
                    $this->txs->append($blockTx);
                } catch (\Exception $e) {
                    if ($this->p->isDebug()) {
                        trigger_error(sprintf('[%s][%s] %s', get_class($e), $e->getCode(), $e->getMessage()), E_USER_WARNING);
                    }

                    throw BlockDecodeException::Incomplete($this, sprintf('Failed to decode transaction at index %d', $i));
                }

                // Step 15.3
                $serTxRLen = UInts::Decode_UInt2LE($read->next(2));

                // Step 15.4
                $serTxR = $read->next($serTxRLen);
                try {
                    $this->rawTxReceipts[] = $serTxR;
                    $blockTxR = $this->p->txFlags()->get($blockTx->flag())->decodeReceipt($blockTx, new Binary($serTxR), $heightContext);
                    $this->txsReceipts->append($blockTxR);
                } catch (\Exception $e) {
                    if ($this->p->isDebug()) {
                        trigger_error(sprintf('[%s][%s] %s', get_class($e), $e->getCode(), $e->getMessage()), E_USER_WARNING);
                    }

                    throw BlockDecodeException::Incomplete($this, sprintf('Failed to decode tx receipt at index %d', $i));
                }
            }
        }

        // Check remaining bytes?
        if ($read->remaining()) {
            throw BlockDecodeException::Incomplete($this, 'Block byte reader has excess bytes');
        }
    }

    /**
     * @return array
     */
    public function __debugInfo(): array
    {
        return $this->array(true);
    }

    /**
     * @param bool $getRawTxs
     * @return array
     */
    public function array(bool $getRawTxs): array
    {
        $partialBlock = [];
        $partialBlock["hash"] = $this->hash->base16()->hexits(false);

        $partialBlockProps = [
            "version",
            "timeStamp",
            "prevBlockId",
            "txCount",
            "totalIn",
            "totalOut",
            "totalFee",
            "forger",
            "reward",
            "merkleTx",
            "merkleTxReceipts",
            "bodySize"
        ];

        foreach ($partialBlockProps as $prop) {
            if (isset($this->$prop)) {
                $value = $this->$prop;
                if (in_array($prop, ["prevBlockId", "forger", "merkleTx", "merkleTxReceipts"])) {
                    $value = bin2hex($value);
                }

                $partialBlock[$prop] = $value;
            }
        }

        // Signatures
        if (isset($this->signs) && $this->signs) {
            $partialBlock["signs"] = [];
            /** @var Signature $sign */
            foreach ($this->signs as $sign) {
                $partialBlock["signs"][] = [
                    "r" => $sign->r()->hexits(false),
                    "s" => $sign->s()->hexits(false),
                    "v" => $sign->v(),
                ];
            }
        }

        // Transactions
        $transactions = [];
        if ($getRawTxs) {
            for ($i = 0; $i < count($this->rawTxs); $i++) {
                $rawTx = $this->rawTxs[$i];
                $rawTxHash = $this->p->hash256(new Binary($rawTx));
                $rawTxR = $this->rawTxReceipts[$i] ?? null;

                $transactions[] = [
                    "hash" => $rawTxHash->base16()->hexits(false),
                    "tx" => bin2hex($rawTx),
                    "receipt" => $rawTxR ? bin2hex($rawTxR) : null,
                ];

                if (!$rawTxR) { // Break so receipts don't get mixed up next!
                    break;
                }
            }
        } else {
            $tI = -1;
            /** @var AbstractPreparedTx $tx */
            foreach ($this->txs->all() as $tx) {
                $tI++;
                $txR = $this->txsReceipts->hasIndex($tI) ? $this->txsReceipts->index($tI) : null;
                $transactions[] = [
                    "tx" => $tx->array(),
                    "receipt" => $txR,
                ];

                if (!$txR) { // Break so receipts don't get mixed up next!
                    break;
                }
            }
        }

        $partialBlock["transactions"] = $transactions;
        return $partialBlock;
    }

    /**
     * @return Binary
     */
    public function hash(): Binary
    {
        return $this->hash;
    }

    /**
     * @return Binary
     */
    public function raw(): Binary
    {
        return $this->raw;
    }

    /**
     * @return BlockTxs
     */
    public function blockTxs(): BlockTxs
    {
        return $this->txs;
    }

    /**
     * @return BlockTxReceipts
     */
    public function blockTxReceipts(): BlockTxReceipts
    {
        return $this->txsReceipts;
    }

    /**
     * @return int
     */
    public function version(): int
    {
        return $this->version;
    }

    /**
     * @return int
     */
    public function timeStamp(): int
    {
        return $this->timeStamp;
    }

    /**
     * @return string
     */
    public function prevBlockHash(): string
    {
        return $this->prevBlockId;
    }

    /**
     * @return int
     */
    public function txCount(): int
    {
        return $this->txCount;
    }

    /**
     * @return int
     */
    public function totalIn(): int
    {
        return $this->totalIn;
    }

    /**
     * @return int
     */
    public function totalOut(): int
    {
        return $this->totalOut;
    }

    /**
     * @return int
     */
    public function totalFee(): int
    {
        return $this->totalFee;
    }

    /**
     * @return string
     */
    public function forger(): string
    {
        return $this->forger;
    }

    /**
     * @return array
     */
    public function signatures(): array
    {
        return $this->signs;
    }

    /**
     * @return int
     */
    public function reward(): int
    {
        return $this->reward;
    }

    /**
     * @return string
     */
    public function merkleHashTx(): string
    {
        return $this->merkleTx;
    }

    /**
     * @return string
     */
    public function merkleHashTxReceipts(): string
    {
        return $this->merkleTxReceipts;
    }

    /**
     * @return int
     */
    public function bodySize(): int
    {
        return $this->bodySize;
    }
}
