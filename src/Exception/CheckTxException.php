<?php
declare(strict_types=1);

namespace ForwardBlock\Protocol\Exception;

/**
 * Class CheckTxException
 * @package ForwardBlock\Protocol\Exception
 */
class CheckTxException extends TransactionsException
{
    /** @var int */
    public const ERR_SIGNATURES = 0x0b;
    /** @var int */
    public const ERR_RECEIPT = 0x0c;
}
