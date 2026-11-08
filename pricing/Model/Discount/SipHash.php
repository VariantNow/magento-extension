<?php
/**
 * Copyright © Variant. All rights reserved.
 */
declare(strict_types=1);

namespace Variant\Pricing\Model\Discount;

/**
 * SipHash-2-4 over 32-bit lanes — MIRROR of packages/shared/src/rec-token/siphash.ts and the
 * Shopify discount function's copy. PHP has no SipHash, and its integers are signed 64-bit,
 * so the 64-bit lanes are kept as two masked 32-bit halves exactly like the JS mirror.
 * The unit test runs the same published reference vectors, so the copies cannot drift.
 *
 * Output is the 8-byte tag, little-endian, as lowercase hex.
 */
class SipHash
{
    private const M = 0xffffffff;

    /** @var int[] v0h, v0l, v1h, v1l, v2h, v2l, v3h, v3l */
    private array $v = [];

    /**
     * Null when the key is not exactly 16 bytes of hex: never a silently wrong MAC.
     *
     * @param string $keyHex
     * @param string $message
     * @return string|null
     */
    public function hex(string $keyHex, string $message): ?string
    {
        if (strlen($keyHex) !== 32 || !ctype_xdigit($keyHex)) {
            return null;
        }
        return $this->hash($this->bytes((string)hex2bin($keyHex)), $this->bytes($message));
    }

    /**
     * The SipHash-2-4 tag of a message under a 16-byte key, as lowercase hex.
     *
     * @param int[] $key 16 bytes
     * @param int[] $message
     * @return string
     */
    public function hash(array $key, array $message): string
    {
        $k0l = $this->u32le($key, 0);
        $k0h = $this->u32le($key, 4);
        $k1l = $this->u32le($key, 8);
        $k1h = $this->u32le($key, 12);
        $this->v = [
            $k0h ^ 0x736f6d65, $k0l ^ 0x70736575,
            $k1h ^ 0x646f7261, $k1l ^ 0x6e646f6d,
            $k0h ^ 0x6c796765, $k0l ^ 0x6e657261,
            $k1h ^ 0x74656462, $k1l ^ 0x79746573,
        ];

        $len = count($message);
        $blocks = $len - ($len % 8);
        for ($i = 0; $i < $blocks; $i += 8) {
            $this->compress($this->u32le($message, $i + 4), $this->u32le($message, $i));
        }
        $tail = [0, 0, 0, 0, 0, 0, 0, 0];
        for ($i = $blocks; $i < $len; $i++) {
            $tail[$i - $blocks] = $message[$i];
        }
        $tail[7] = $len & 0xff;
        $this->compress($this->u32le($tail, 4), $this->u32le($tail, 0));

        $this->v[5] ^= 0xff;
        for ($i = 0; $i < 4; $i++) {
            $this->round();
        }
        $outh = $this->v[0] ^ $this->v[2] ^ $this->v[4] ^ $this->v[6];
        $outl = $this->v[1] ^ $this->v[3] ^ $this->v[5] ^ $this->v[7];
        return bin2hex(pack('V', $outl) . pack('V', $outh));
    }

    /**
     * Absorb one 8-byte message word, given as (high, low) halves, with two SipRounds.
     *
     * @param int $mh
     * @param int $ml
     */
    private function compress(int $mh, int $ml): void
    {
        $this->v[6] ^= $mh;
        $this->v[7] ^= $ml;
        $this->round();
        $this->round();
        $this->v[0] ^= $mh;
        $this->v[1] ^= $ml;
    }

    /**
     * One SipRound on [v0, v1, v2, v3], each as (high, low) 32-bit halves.
     */
    private function round(): void
    {
        [$v0h, $v0l, $v1h, $v1l, $v2h, $v2l, $v3h, $v3l] = $this->v;
        [$v0h, $v0l] = $this->add($v0h, $v0l, $v1h, $v1l);
        [$v1h, $v1l] = $this->rotl($v1h, $v1l, 13);
        $v1h ^= $v0h;
        $v1l ^= $v0l;
        [$v0h, $v0l] = [$v0l, $v0h];
        [$v2h, $v2l] = $this->add($v2h, $v2l, $v3h, $v3l);
        [$v3h, $v3l] = $this->rotl($v3h, $v3l, 16);
        $v3h ^= $v2h;
        $v3l ^= $v2l;
        [$v0h, $v0l] = $this->add($v0h, $v0l, $v3h, $v3l);
        [$v3h, $v3l] = $this->rotl($v3h, $v3l, 21);
        $v3h ^= $v0h;
        $v3l ^= $v0l;
        [$v2h, $v2l] = $this->add($v2h, $v2l, $v1h, $v1l);
        [$v1h, $v1l] = $this->rotl($v1h, $v1l, 17);
        $v1h ^= $v2h;
        $v1l ^= $v2l;
        [$v2h, $v2l] = [$v2l, $v2h];
        $this->v = [$v0h, $v0l, $v1h, $v1l, $v2h, $v2l, $v3h, $v3l];
    }

    /**
     * 64-bit addition of two (high, low) pairs, wrapping around like uint64.
     *
     * @param int $ah
     * @param int $al
     * @param int $bh
     * @param int $bl
     * @return int[] high, low
     */
    private function add(int $ah, int $al, int $bh, int $bl): array
    {
        $sum = $al + $bl;
        return [($ah + $bh + ($sum > self::M ? 1 : 0)) & self::M, $sum & self::M];
    }

    /**
     * 64-bit rotate left of a (high, low) pair by 1 to 31 bits.
     *
     * @param int $h
     * @param int $l
     * @param int $bits
     * @return int[] high, low
     */
    private function rotl(int $h, int $l, int $bits): array
    {
        return [
            (($h << $bits) | ($l >> (32 - $bits))) & self::M,
            (($l << $bits) | ($h >> (32 - $bits))) & self::M,
        ];
    }

    /**
     * The little-endian 32-bit word at byte offset $i.
     *
     * @param int[] $bytes
     * @param int $i
     * @return int
     */
    private function u32le(array $bytes, int $i): int
    {
        return ($bytes[$i] | ($bytes[$i + 1] << 8) | ($bytes[$i + 2] << 16) | ($bytes[$i + 3] << 24)) & self::M;
    }

    /**
     * The string's bytes, as integers 0–255.
     *
     * @param string $s
     * @return int[]
     */
    private function bytes(string $s): array
    {
        return $s === '' ? [] : array_map('ord', str_split($s));
    }
}
