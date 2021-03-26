<?php
declare(strict_types=1);

namespace ForwardBlock\Protocol\Transactions;

use Comely\DataTypes\Buffer\Binary;
use ForwardBlock\Protocol\AbstractProtocolChain;
use ForwardBlock\Protocol\Exception\TxEncodeException;
use ForwardBlock\Protocol\Exception\TxReceiptDecodeException;
use ForwardBlock\Protocol\Math\UInts;
use ForwardBlock\Protocol\ProtocolConstants;
use ForwardBlock\Protocol\Transactions\Receipts\LedgerEntries;
use ForwardBlock\Protocol\Transactions\Receipts\LedgerEntry;
use ForwardBlock\Protocol\Transactions\Receipts\LedgerFlag;

/**
 * Class AbstractTxReceipt
 * @package ForwardBlock\Protocol\Transactions
 */
abstract class AbstractTxReceipt
{
    /** @var AbstractProtocolChain */
    protected AbstractProtocolChain $p;
    /** @var AbstractPreparedTx */
    protected AbstractPreparedTx $tx;
    /** @var int */
    protected int $blockHeightContext;

    /** @var int|null */
    protected ?int $status = null;
    /** @var Binary */
    protected Binary $data;
    /** @var LedgerEntries */
    protected LedgerEntries $ledgerEntries;

    /**
     * @param AbstractProtocolChain $p
     * @param AbstractPreparedTx $tx
     * @param int $blockHeightContext
     * @param Binary $encoded
     * @return static
     * @throws TxReceiptDecodeException
     */
    public static function Decode(AbstractProtocolChain $p, AbstractPreparedTx $tx, int $blockHeightContext, Binary $encoded): self
    {
        $receipt = new static($p, $tx, $blockHeightContext);

        // Purge all raw receipts generated by callback (generateLedgerEntries())
        $receipt->ledgerEntries->purge();

        $read = $encoded->read();
        $read->throwUnderflowEx();

        // Step 1
        $txId = $read->first(32);
        if ($txId !== $tx->hash()->raw()) {
            throw new TxReceiptDecodeException(sprintf(
                'Receipt for tx "0x%s" does not match transaction hash "0x%s"', bin2hex($txId), bin2hex($tx->hash()->raw())
            ));
        }

        // Step 2
        $status = UInts::Decode_UInt2LE($read->next(2));
        $receipt->setStatus($status);

        // Step 3
        $dataLen = UInts::Decode_UInt1LE($read->next(1));
        if ($dataLen > 0) { // Step 2.1
            $receipt->data()->append($read->next($dataLen));
        }

        // Step 4
        $leBatches = UInts::Decode_UInt1LE($read->next(1));
        if ($leBatches > 0) {
            for ($lB = 0; $lB < $leBatches; $lB++) {
                $leC = UInts::Decode_UInt1LE($read->next(1));
                $leBatch = [];
                if ($leC < 0 || $leC > ProtocolConstants::MAX_LEDGER_ENTRIES) {
                    throw new TxReceiptDecodeException(
                        sprintf('Receipt batch contains %d ledger entries, allowed are 1 to %d', $leC, ProtocolConstants::MAX_LEDGER_ENTRIES)
                    );
                }

                for ($leN = 0; $leN < $leC; $leN++) {
                    $hash160 = $read->next(20); // Account Hash160 bytes
                    $flag = $p->txFlags()->ledgerFlags()->get(UInts::Decode_UInt2LE($read->next(2)));
                    $amount = UInts::Decode_UInt8LE($read->next(8));
                    $asset = trim($read->next(8));
                    if (!$asset) { // Native token?
                        $asset = null;
                    }

                    $status = $read->next(1);
                    if (!in_array($status, ["\0", "\1"])) {
                        throw new TxReceiptDecodeException(sprintf('Invalid ledger entry # %d status byte', $leN + 1));
                    }

                    $decodedLE = new LedgerEntry($p, $receipt, $flag, $hash160, $amount, $asset);
                    if ($status === "\1") {
                        $decodedLE->markApplicable();
                    }

                    $leBatch[] = $decodedLE;
                }

                $receipt->registerLedgerEntriesBatch(...$leBatch);
            }
        }

        return $receipt;
    }

    /**
     * AbstractTxReceipt constructor.
     * @param AbstractProtocolChain $p
     * @param AbstractPreparedTx $tx
     * @param int $blockHeightContext
     */
    public function __construct(AbstractProtocolChain $p, AbstractPreparedTx $tx, int $blockHeightContext)
    {
        $this->p = $p;
        $this->tx = $tx;
        $this->data = new Binary();
        $this->ledgerEntries = new LedgerEntries();
        $this->blockHeightContext = $blockHeightContext;

        // Generate ledger entries here
        $this->generateLedgerEntries();
    }

    /**
     * @return array
     */
    public function __debugInfo(): array
    {
        return $this->dump();
    }

    /**
     * @return array
     */
    public function dump(): array
    {
        return [
            "status" => $this->status,
            "data" => $this->data->raw(),
            "ledgerEntries" => $this->ledgerEntries->dump(),
        ];
    }

    /**
     * This method is called on construct of receipt to generate RAW ledger entries
     */
    abstract protected function generateLedgerEntries(): void;

    /**
     * This method is called when transaction is being applied
     */
    abstract protected function applyCallback(): void;

    /**
     * This method is called when transaction is being undone
     */
    abstract protected function undoCallback(): void;

    /**
     * @return bool
     */
    public function isFinalised(): bool
    {
        return is_int($this->status) && $this->status >= 0;
    }

    /**
     * @return AbstractPreparedTx
     */
    public function getTx(): AbstractPreparedTx
    {
        return $this->tx;
    }

    /**
     * @return Binary
     */
    public function data(): Binary
    {
        return $this->data;
    }

    /**
     * @param int $code
     * @return $this
     */
    public function setStatus(int $code): self
    {
        $this->status = $code;
        return $this;
    }

    /**
     * @return int
     */
    public function status(): int
    {
        return $this->status ?? -1;
    }

    /**
     * @return LedgerEntries
     */
    public function ledgerEntries(): LedgerEntries
    {
        return $this->ledgerEntries;
    }

    /**
     * @return Binary
     */
    public function ledgerEntriesHash(): Binary
    {
        $serBatch = $this->ledgerEntries->serializedBatches();
        return $this->p->hash256(new Binary($this->tx->hash()->raw() . $serBatch))->readOnly(true);
    }

    /**
     * @return Binary
     * @throws TxEncodeException
     */
    public function getReceiptHash(): Binary
    {
        $raw = $this->halfSerialize()->append($this->ledgerEntriesHash());
        return $this->p->hash256($raw);
    }

    /**
     * @return Binary
     * @throws TxEncodeException
     */
    public function serialize(): Binary
    {
        $ser = $this->halfSerialize();
        $ser->append($this->ledgerEntries->serializedBatches());
        return $ser->readOnly(true);
    }

    /**
     * @return Binary
     * @throws TxEncodeException
     */
    private function halfSerialize(): Binary
    {
        if (!is_int($this->status)) {
            throw new TxEncodeException('TxReceipt status not set');
        }

        $ser = new Binary();
        $ser->append($this->tx->hash()->raw());
        $ser->append(UInts::Encode_UInt2LE($this->status));

        if ($this->data->sizeInBytes > 0xff) {
            throw new TxEncodeException('TxReceipt data cannot exceed 255 bytes');
        }

        $ser->append(UInts::Encode_UInt1LE($this->data->sizeInBytes));
        $ser->append($this->data);
        return $ser;
    }

    /**
     * @param LedgerFlag $lF
     * @param string $hash160
     * @param int $amount
     * @param string|null $assetId
     * @return LedgerEntry
     */
    public function createLedgerEntry(LedgerFlag $lF, string $hash160, int $amount, ?string $assetId = null): LedgerEntry
    {
        return new LedgerEntry($this->p, $this, $lF, $hash160, $amount, $assetId);
    }

    /**
     * @param LedgerEntry ...$entries
     */
    public function registerLedgerEntriesBatch(LedgerEntry ...$entries): void
    {
        $this->ledgerEntries->addBatch(...$entries);
    }
}
