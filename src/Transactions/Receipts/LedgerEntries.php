<?php
declare(strict_types=1);

namespace ForwardBlock\Protocol\Transactions\Receipts;

use ForwardBlock\Protocol\Math\UInts;
use ForwardBlock\Protocol\ProtocolConstants;

/**
 * Class LedgerEntries
 * @package ForwardBlock\Protocol\Transactions\Receipts
 */
class LedgerEntries
{
    /** @var array */
    private array $batches = [];
    /** @var int */
    private int $batchCount = 0;
    /** @var int */
    private int $leCount = 0;

    /**
     * @return void
     */
    public function purge(): void
    {
        $this->batches = [];
        $this->batchCount = 0;
        $this->leCount = 0;
    }

    /**
     * @param LedgerEntry ...$entries
     */
    public function addBatch(LedgerEntry ...$entries)
    {
        $batchCount = count($entries);
        if (($this->leCount + $batchCount) >= ProtocolConstants::MAX_LEDGER_ENTRIES) {
            throw new \DomainException(
                sprintf('Tx receipt cannot contain more than %d ledger entries', ProtocolConstants::MAX_LEDGER_ENTRIES)
            );
        }

        $this->batches[] = $entries;
        $this->batchCount++;
        $this->leCount = $this->leCount + $batchCount;
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
        $batches = [];
        /** @var array $batch */
        foreach ($this->batches as $batch) {
            $dump = [];
            /** @var LedgerEntry $lE */
            foreach ($batch as $lE) {
                $dump[] = $lE->dump();
            }

            $batches[] = $dump;
        }

        return [
            "batchCount" => $this->batchCount,
            "totalCount" => $this->leCount,
            "batches" => $batches,
        ];
    }

    /**
     * @return array
     */
    public function batches(): array
    {
        return $this->batches;
    }

    /**
     * @return int
     */
    public function batchCount(): int
    {
        return $this->batchCount;
    }

    /**
     * @return int
     */
    public function entriesCount(): int
    {
        return $this->leCount;
    }

    /**
     * @return string
     */
    public function serializedBatches(): string
    {
        $ser = UInts::Encode_UInt1LE($this->batchCount);
        foreach ($this->batches as $batch) {
            $batchCount = count($batch);
            $ser .= UInts::Encode_UInt1LE($batchCount);
            /** @var LedgerEntry $entry */
            foreach ($batch as $entry) {
                $ser .= $entry->serializeRawBytes();
            }
        }

        return $ser;
    }
}
