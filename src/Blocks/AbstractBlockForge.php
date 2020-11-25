<?php
declare(strict_types=1);

namespace ForwardBlock\Protocol\Blocks;

use Comely\DataTypes\Buffer\Binary;
use ForwardBlock\Protocol\AbstractProtocolChain;
use ForwardBlock\Protocol\Exception\BlockForgeException;
use ForwardBlock\Protocol\KeyPair\PrivateKey\Signature;
use ForwardBlock\Protocol\KeyPair\PublicKey;
use ForwardBlock\Protocol\Transactions\CheckedTx;
use ForwardBlock\Protocol\Validator;

/**
 * Class AbstractBlockForge
 * @package ForwardBlock\Protocol\Blocks
 */
abstract class AbstractBlockForge extends AbstractBlock
{
    /** @var PublicKey|null */
    protected ?PublicKey $forgerPubKey = null;

    /**
     * BlockForge constructor.
     * @param AbstractProtocolChain $p
     * @param string $prevBlock
     * @param int $ver
     * @param int $epoch
     * @throws BlockForgeException
     */
    public function __construct(AbstractProtocolChain $p, string $prevBlock, int $ver, int $epoch)
    {
        parent::__construct($p);
        if (strlen($prevBlock) !== 32) {
            throw new BlockForgeException('PrevBlockHash must be precisely 32 bytes');
        }

        if ($p < 0 || $p > 0xff) {
            throw new BlockForgeException('Invalid block version');
        }

        if (!Validator::isValidEpoch($epoch)) {
            throw new BlockForgeException('Invalid time stamp');
        }

        $this->prevBlockId = $prevBlock;
        $this->version = $ver;
        $this->timeStamp = $epoch;

        // onConstructCallback for i.e. first default/reward transaction
        $this->onConstructCallback();
    }

    /**
     * Callback method ideally to create first default/reward transaction
     */
    abstract public function onConstructCallback(): void;

    /**
     * @param PublicKey $publicKey
     * @return $this
     */
    public function forger(PublicKey $publicKey): self
    {
        $this->forgerPubKey = $publicKey;
        return $this;
    }

    /**
     * @param Signature $sign
     * @return $this
     * @throws BlockForgeException
     */
    public function addSignature(Signature $sign): self
    {
        if (count($this->signs) >= 5) {
            throw new BlockForgeException('Cannot add more then 5 signatures');
        }

        $this->signs[] = $sign;
        return $this;
    }

    /**
     * @param CheckedTx $tx
     * @throws \ForwardBlock\Protocol\Exception\TxEncodeException
     */
    public function appendCheckedTx(CheckedTx $tx): void
    {
        $this->txs->append($tx->tx());
        // Todo: check below that receipt is NOT raw
        $this->txsReceipts->append($tx->rawReceipt());
        $this->txCount++;
    }

    /**
     * @param bool $includeSignatures
     * @return Binary
     * @throws \ForwardBlock\Protocol\Exception\BlockEncodeException
     */
    public function serialize(bool $includeSignatures): Binary
    {
        $this->forger = hex2bin($this->forgerPubKey->getHash160());
        $this->merkleTx = $this->txs->merkleRoot()->raw();
        $this->merkleTxReceipts = $this->txsReceipts->merkleRoot()->raw();
        return parent::serialize($includeSignatures);
    }
}