<?php

declare(strict_types=1);

namespace OpenPairing\Domain\Colour;

enum Colour: string
{
    case White = 'white';
    case Black = 'black';

    public function opposite(): self
    {
        return match ($this) {
            self::White => self::Black,
            self::Black => self::White,
        };
    }
}

