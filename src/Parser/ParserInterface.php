<?php

declare(strict_types=1);

namespace LinkWatcher\Parser;

interface ParserInterface
{
    /**
     * @return list<array{key:string,value:string,line:?int}>
     * @throws \RuntimeException when the file cannot be parsed
     */
    public function parse(string $content): array;
}
