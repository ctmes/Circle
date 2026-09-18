<?php

namespace App\Enums;

/**
 * Who may see an opening or a published record (spec §21.5).
 *
 * The default is `network` rather than `public`, and that is a product
 * decision rather than a cautious one. A public board fights the gate the rest
 * of the product is built on, and a marketplace that is only a public board is
 * a cold-start problem with a UI. The network — organisations you have already
 * completed an engagement with — is worth something on day one because the
 * relationships in it were earned rather than declared.
 */
enum WorkVisibility: string
{
    case Party   = 'party';
    case Circle  = 'circle';
    case Network = 'network';
    case Public  = 'public';

    /**
     * How wide this is, for comparing two visibilities without a match block.
     * Higher is wider.
     */
    public function width(): int
    {
        return match ($this) {
            self::Party   => 0,
            self::Circle  => 1,
            self::Network => 2,
            self::Public  => 3,
        };
    }

    /** Whether this reaches beyond the Circle, and so past §15's rule. */
    public function reachesOutside(): bool
    {
        return $this->width() >= self::Network->width();
    }

    public function label(): string
    {
        return match ($this) {
            self::Party   => 'this company only',
            self::Circle  => 'everyone in the Circle',
            self::Network => 'companies we have worked with',
            self::Public  => 'anyone signed in',
        };
    }
}
