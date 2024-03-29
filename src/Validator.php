<?php
declare(strict_types=1);

namespace ForwardBlock\Protocol;

use Comely\DataTypes\Buffer\Base16;
use Comely\DataTypes\Buffer\Binary;
use FurqanSiddiqui\Base58\Base58;

/**
 * Class Validator
 * @package ForwardBlock\Protocol
 */
class Validator
{
    /**
     * @param $name
     * @return bool
     */
    public static function isValidTxFlagName($name): bool
    {
        return is_string($name) && preg_match('/^[a-z][a-z0-9]+(_[a-z0-9]+)*$/i', $name);
    }

    /**
     * @param $chainId
     * @return bool
     */
    public static function isValidChainId($chainId): bool
    {
        return is_string($chainId) && preg_match('/^[a-f0-9]{64}$/i', $chainId);
    }

    /**
     * @param $assetId
     * @return bool
     */
    public static function isValidAssetId($assetId): bool
    {
        return is_string($assetId) && preg_match('/^[a-z][a-z0-9]{1,3}-[a-z]{2}[0-9]$/i', $assetId);
    }

    /**
     * @param $assetTicker
     * @return bool
     */
    public static function isValidAssetTicker($assetTicker): bool
    {
        return is_string($assetTicker) && preg_match('/^[a-z][a-z0-9]{1,5}$/i', $assetTicker);
    }

    /**
     * @param $name
     * @return bool
     */
    public static function isValidAssetName($name): bool
    {
        if (is_string($name) && preg_match('/^\w+(\s\w+)*$/i', $name)) {
            $len = strlen($name);
            if ($len > 0 && $len < 32) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param $arg
     * @param int $len
     * @return bool
     */
    public static function isValidWIF($arg, int $len = 52): bool
    {
        return is_string($arg) && preg_match('/^[' . preg_quote(Base58::CHARSET, "/") . ']{' . $len . '}$/', $arg);
    }

    /**
     * @param $timeStamp
     * @return bool
     */
    public static function isValidEpoch($timeStamp): bool
    {
        if (is_int($timeStamp)) {
            if ($timeStamp > 0 && $timeStamp < 0xffffffff) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param $in
     * @return bool
     */
    public static function isValidUsername($in): bool
    {
        if (is_string($in)) {
            try {
                return $in === self::validatedMemo($in);
            } catch (\Exception $e) {
            }
        }

        return false;
    }

    /**
     * @param $memo
     * @return string
     */
    public static function validatedMemo($memo): string
    {
        if (!is_string($memo)) {
            throw new \InvalidArgumentException('Invalid transaction memo');
        }

        $memo = trim($memo);
        if ($memo === "") {
            return ""; // Empty memos are valid
        }

        $memoLen = strlen($memo);
        if ($memoLen > ProtocolConstants::MAX_TX_MEMO_LEN) {
            throw new \LengthException(sprintf('Memo cannot exceed length of %d bytes', ProtocolConstants::MAX_TX_MEMO_LEN));
        }

        if (!preg_match('/^[a-z0-9\s\-_.@%:;()\[\]\"\']+$/i', $memo)) {
            throw new \DomainException('Memo contains an illegal character or invalid format');
        }

        return $memo;
    }

    /**
     * @param $pubKey
     * @param bool|null $compressed
     */
    public static function checkPublicKey($pubKey, ?bool $compressed = null): void
    {
        $pubKeyStr = null;
        if ($pubKey instanceof Base16) {
            $pubKeyStr = $pubKey->binary()->value();
        } elseif ($pubKey instanceof Binary) {
            $pubKeyStr = $pubKey->value();
        } elseif (is_string($pubKey)) {
            $pubKeyStr = $pubKey;
        }

        if (!$pubKeyStr) {
            throw new \InvalidArgumentException(sprintf('Invalid public key arg type, got "%s"', gettype($pubKeyStr)));
        }

        $prefixes = ["\x02", "\x03", "\x04"];
        $length = [33, 65];
        $type = null;
        if (is_bool($compressed)) {
            $prefixes = $compressed ? ["\x02", "\x03"] : ["\x04"];
            $length = $compressed ? [33] : [65];
            $type = $compressed ? "compressed " : "uncompressed ";
        }

        $pubKeyLen = strlen($pubKeyStr);
        if (!in_array($pubKeyLen, $length)) {
            throw new \LengthException(sprintf('Invalid %s public key length, got %d bytes', $type, $pubKeyLen));
        }

        if (!in_array($pubKeyStr[0], $prefixes)) {
            throw new \DomainException(sprintf('Invalid %s public key prefix', $type));
        }
    }

    /**
     * @param $str
     * @param int $size
     * @return bool
     */
    public static function isBase16Int($str, int $size = 1): bool
    {
        if (is_string($str)) {
            if (preg_match('/^[a-f0-9]{' . $size * 2 . '}$/i', $str)) {
                return true;
            }
        }

        return false;
    }
}
