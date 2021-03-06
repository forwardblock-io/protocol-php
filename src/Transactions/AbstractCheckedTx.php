<?php
declare(strict_types=1);

namespace ForwardBlock\Protocol\Transactions;

use ForwardBlock\Protocol\AbstractProtocolChain;
use ForwardBlock\Protocol\Accounts\ChainAccountInterface;
use ForwardBlock\Protocol\Exception\CheckTxException;

/**
 * Class AbstractCheckedTx
 * @package ForwardBlock\Protocol\Transactions
 */
abstract class AbstractCheckedTx
{
    /** @var Transaction */
    protected Transaction $tx;
    /** @var AbstractTxReceipt */
    protected AbstractTxReceipt $receipt;

    /**
     * AbstractCheckedTx constructor.
     * @param AbstractProtocolChain $p
     * @param ChainAccountInterface $sender
     * @param Transaction $tx
     * @param int $blockHeightContext
     * @throws CheckTxException
     * @throws \ForwardBlock\Protocol\Exception\TxEncodeException
     * @throws \ForwardBlock\Protocol\Exception\TxFlagException
     */
    public function __construct(AbstractProtocolChain $p, ChainAccountInterface $sender, Transaction $tx, int $blockHeightContext)
    {
        $this->tx = $tx;

        // Block height context
        $chainId = null;
        if ($blockHeightContext === 0) {
            $chainId = bin2hex(str_repeat("\0", 32));
        }

        // Signatures Verification
        $signatures = $tx->signatures();
        $reqSigns = $p->accounts()->sigRequiredCount($sender);
        $verifiedSigns = $p->accounts()->verifyAllSignatures($sender, $tx->hashPreImage($chainId)->base16(), ...$signatures);
        if ($reqSigns > $verifiedSigns) {
            throw new CheckTxException(
                sprintf('Required %d signatures, verified %d', $reqSigns, $verifiedSigns),
                CheckTxException::ERR_SIGNATURES
            );
        }

        // Check TxFlag status in height context
        $txFlag = $p->txFlags()->get($tx->flag());
        if (!$p->isEnabledTxFlag($txFlag, $blockHeightContext)) {
            throw new CheckTxException(
                sprintf('Transaction flag %d (%s) is disabled in block height %d context', $txFlag->id(), strtoupper($txFlag->name()), $blockHeightContext)
            );
        }

        // Get Raw Receipt
        try {
            $this->receipt = $p->txFlags()->get($tx->flag())->newReceipt($tx, $blockHeightContext);
        } catch (\Exception $e) {
            if ($p->isDebug()) {
                trigger_error(sprintf('[%s][%s] %s', get_class($e), $e->getCode(), $e->getMessage()), E_USER_WARNING);
            }

            throw new CheckTxException('Failed to generate transaction raw receipt', CheckTxException::ERR_RECEIPT);
        }
    }

    /**
     * @return Transaction
     */
    public function tx(): Transaction
    {
        return $this->tx;
    }

    /**
     * @return AbstractTxReceipt
     */
    public function rawReceipt(): AbstractTxReceipt
    {
        return $this->receipt;
    }
}
